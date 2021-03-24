<?php
/**
 * @copyright Copyright (c) 2017 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\SharePoint;

use function explode;
use function json_decode;
use OCA\SharePoint\Storage\Storage;
use Office365\PHP\Client\Runtime\Auth\AuthenticationContext;
use Office365\PHP\Client\Runtime\ClientObject;
use Office365\PHP\Client\Runtime\ClientObjectCollection;
use Office365\PHP\Client\Runtime\Utilities\RequestOptions;
use Office365\PHP\Client\Runtime\Utilities\Requests;
use Office365\PHP\Client\SharePoint\BasePermissions;
use Office365\PHP\Client\SharePoint\ClientContext;
use Office365\PHP\Client\SharePoint\File;
use Office365\PHP\Client\SharePoint\FileCreationInformation;
use Office365\PHP\Client\SharePoint\Folder;
use Office365\PHP\Client\SharePoint\SPList;

class Client {
	/** @var  ClientContext */
	protected $context;

	/** @var  AuthenticationContext */
	protected $authContext;

	/** @var ContextsFactory */
	private $contextsFactory;

	/** @var  string */
	private $sharePointUrl;

	/** @var string[] */
	private $credentials;

	/** @var string[] */
	private $knownSP2013SystemFolders = ['Forms', 'Item', 'Attachments'];

	public const DEFAULT_PROPERTIES = [
		Storage::SP_PROPERTY_MTIME,
		Storage::SP_PROPERTY_NAME,
		Storage::SP_PROPERTY_SIZE,
	];

	/**
	 * SharePointClient constructor.
	 *
	 * @param ContextsFactory $contextsFactory
	 * @param string $sharePointUrl
	 * @param array $credentials
	 */
	public function __construct(
		ContextsFactory $contextsFactory,
		$sharePointUrl,
		array $credentials
	) {
		$this->contextsFactory = $contextsFactory;
		$this->sharePointUrl = $sharePointUrl;
		$this->credentials = $credentials;
	}

	/**
	 * Returns the corresponding File or Folder object for the provided path.
	 * If none can be retrieved, an exception is thrown.
	 *
	 * @param string $path
	 * @param array $properties
	 * @return File|Folder
	 * @throws NotFoundException
	 * @throws \Exception
	 */
	public function fetchFileOrFolder($path, array $properties = null) {
		$fetchFileFunc = function ($path, $props) {
			return $this->fetchFile($path, $props);
		};
		$fetchFolderFunc = function ($path, $props) {
			return $this->fetchFolder($path, $props);
		};
		$fetchers = [ $fetchFileFunc, $fetchFolderFunc ];
		if (strpos($path, '.') === false) {
			$fetchers = array_reverse($fetchers);
		}

		foreach ($fetchers as $fetchFunction) {
			try {
				$instance = call_user_func_array($fetchFunction, [$path, $properties]);
				return $instance;
			} catch (\Exception $e) {
				if (
					strpos($e->getMessage(), $path) === false
					&& $e->getMessage() !== 'Unknown Error'
					&& $e->getMessage() !== 'File Not Found.'
					&& !$this->isErrorDoesNotExist($e)
				) {
					# Unexpected Exception, pass it on
					throw $e;
				}
			}
		}

		# Nothing succeeded, quit with not found
		throw new NotFoundException('File or Folder not found');
	}

	private function isErrorDoesNotExist(\Exception $e): bool {
		$trace = $e->getTrace()[0];
		if ($trace['function'] !== 'validateResponse' || !isset($trace['args'][0])) {
			return false;
		}
		$error = json_decode($trace['args'][0], true)['error'];
		$errorCode = (int)explode(',', $error['code'])[0];
		return in_array($errorCode, [
			-2146232832, # Microsoft.SharePoint.SPException (unclear)
			-2147024894, # File cannot be found
			-1, # unknown error
		]);
	}

	/**
	 * returns a File instance for the provided path
	 *
	 * @param string $relativeServerPath
	 * @param array|null $properties
	 * @return File
	 */
	public function fetchFile($relativeServerPath, array $properties = null) {
		$this->ensureConnection();
		$file = $this->context->getWeb()->getFileByServerRelativeUrl($relativeServerPath);
		$this->loadAndExecute($file, $properties);
		return $file;
	}

	/**
	 * returns a Folder instance for the provided path
	 *
	 * @param string $relativeServerPath
	 * @param array|null $properties
	 * @return Folder
	 */
	public function fetchFolder($relativeServerPath, array $properties = null) {
		$this->ensureConnection();
		$folder = $this->context->getWeb()->getFolderByServerRelativeUrl($relativeServerPath);
		$allFields = $folder->getListItemAllFields();
		$this->context->load($allFields);
		$this->loadAndExecute($folder, $properties);

		return $folder;
	}

	/**
	 * adds a folder on the given server path
	 *
	 * @param string $relativeServerPath
	 * @return Folder
	 * @throws \Exception
	 */
	public function createFolder($relativeServerPath) {
		$this->ensureConnection();

		$parentFolder = $this->context->getWeb()->getFolderByServerRelativeUrl(dirname($relativeServerPath));
		$folder = $parentFolder->getFolders()->add(basename($relativeServerPath));

		$this->context->executeQuery();
		return $folder;
	}

	/**
	 * downloads a file by passing it directly into a file resource
	 *
	 * @param $relativeServerPath
	 * @param resource $fp a file resource open for writing
	 * @return bool
	 * @throws \Exception
	 */
	public function getFileViaStream($relativeServerPath, $fp) {
		if (!is_resource($fp)) {
			throw new \InvalidArgumentException('file resource expected');
		}
		$this->ensureConnection();
		$relativeServerPath = rawurlencode($relativeServerPath);
		$url = $this->context->getServiceRootUrl() .
			"web/getfilebyserverrelativeurl('$relativeServerPath')/\$value";
		$options = new RequestOptions($url);
		$options->StreamHandle = $fp;

		return $this->context->executeQueryDirect($options);
	}

	/**
	 * @param string $relativeServerPath
	 * @param resource $fp
	 * @param string $localPath - we need to pass the file size for the content length header
	 * @return bool
	 * @throws \Exception
	 */
	public function overwriteFileViaStream($relativeServerPath, $fp, $localPath) {
		$serverRelativeUrl = rawurlencode($relativeServerPath);
		$url = $this->context->getServiceRootUrl() . "web/getfilebyserverrelativeurl('$serverRelativeUrl')/\$value";
		$request = new RequestOptions($url);
		$request->Method = 'POST'; // yes, POST
		$request->addCustomHeader('X-HTTP-Method','PUT'); // yes, PUT
		$this->context->ensureFormDigest($request);
		$request->StreamHandle = $fp;
		$request->addCustomHeader("content-length", filesize($localPath));

		return false !== $this->context->executeQueryDirect($request);
	}

	/**
	 * FIXME: use StreamHandle as in  overwriteFileViaStream for uploading a file
	 * needs to reimplement adding-file-tp-sp-logic quite some… perhaps upload an
	 * empty file and continue with overwriteFileViaStream?
	 *
	 * @param $relativeServerPath
	 * @param $content
	 * @return File
	 * @throws \Exception
	 */
	public function uploadNewFile($relativeServerPath, $content) {
		$parentFolder = $this->context->getWeb()->getFolderByServerRelativeUrl(dirname($relativeServerPath));
		$fileCollection = $parentFolder->getFiles();

		$info = new FileCreationInformation();
		$info->Content = $content;
		$info->Url = basename($relativeServerPath);
		$file = $fileCollection->add($info);
		$this->context->executeQuery();
		return $file;
	}

	/**
	 * moves a file or a folder to the given destination
	 *
	 * @param string $oldPath
	 * @param string $newPath
	 * @return bool
	 * @throws \Exception
	 */
	public function rename($oldPath, $newPath) {
		$this->ensureConnection();

		$item = $this->fetchFileOrFolder($oldPath);
		if ($item instanceof File) {
			$this->renameFile($item, $newPath);
		} elseif ($item instanceof Folder) {
			$this->renameFolder($item, $newPath);
		} else {
			return false;
		}
		return true;
	}

	/**
	 * renames a folder
	 *
	 * @param Folder $folder
	 * @param string $newPath
	 */
	private function renameFolder(Folder $folder, $newPath) {
		$folder->rename(basename($newPath));
		$this->context->executeQuery();
	}

	/**
	 * moves a file
	 *
	 * @param File $file
	 * @param string $newPath
	 */
	private function renameFile(File $file, $newPath) {
		$newPath = rawurlencode($newPath);
		$file->moveTo($newPath, 0);
		$this->context->executeQuery();
	}

	/**
	 * @return array
	 */
	private function _debugGetLastRequest() {
		$requestHistory = Requests::getHistory();
		$request = array_pop($requestHistory);
		return $request;
	}

	/**
	 * deletes a provided File or Folder
	 *
	 * @param ClientObject $item
	 */
	public function delete(ClientObject $item) {
		$this->ensureConnection();
		if ($item instanceof File) {
			$this->deleteFile($item);
		} elseif ($item instanceof Folder) {
			$this->deleteFolder($item);
		}
	}

	/**
	 * deletes (in fact recycles) the given file on SP
	 *
	 * @param File $file
	 * @throws \Exception
	 */
	public function deleteFile(File $file) {
		$file->recycle();
		$this->context->executeQuery();
	}

	/**
	 * deletes (in fact recycles) the given Folder on SP.
	 *
	 * @param Folder $folder
	 */
	public function deleteFolder(Folder $folder) {
		$folder->recycle();
		$this->context->executeQuery();
	}

	/**
	 * returns a Folder- and a FileCollection of the children of the given directory
	 *
	 * @param Folder $folder
	 * @return ClientObjectCollection[]
	 */
	public function fetchFolderContents(Folder $folder) {
		$this->ensureConnection();

		$folderCollection = $folder->getFolders();
		$fileCollection = $folder->getFiles();

		$this->context->load($folderCollection, self::DEFAULT_PROPERTIES);
		$this->context->load($fileCollection, array_merge(self::DEFAULT_PROPERTIES, [Storage::SP_PROPERTY_URL]));
		$this->context->executeQuery();

		$collections = ['folders' => $folderCollection, 'files' => $fileCollection];

		return $collections;
	}

	/**
	 * tests whether the provided instance is hidden
	 *
	 * @param ClientObject $file
	 * @return bool
	 */
	public function isHidden(ClientObject $file) {
		// ClientObject itself does not have getListItemAllFields but is
		// the common denominator of File and Folder
		if (!$file instanceof File && !$file instanceof Folder) {
			throw new \InvalidArgumentException('File or Folder expected');
		}
		if ($file instanceof File) {
			// it's expensive, we only check folders
			return false;
		}
		return in_array(
			(string)$file->getProperty(Storage::SP_PROPERTY_NAME),
			$this->knownSP2013SystemFolders
		);
	}

	/**
	 * requests the permission for the provided file or folder
	 *
	 * @param ClientObject $item
	 * @return BasePermissions
	 */
	public function getPermissions(ClientObject $item) {
		if (!$item instanceof File && !$item instanceof Folder) {
			throw new \InvalidArgumentException('File or Folder expected');
		}
		$this->ensureConnection();

		$listItem = $item->getListItemAllFields();
		$this->loadAndExecute($listItem, ['EffectiveBasePermissions']);
		$data = $listItem->getProperty('EffectiveBasePermissions');
		if (!is_object($data) || !property_exists($data, 'High') || !property_exists($data, 'Low')) {
			throw new \RuntimeException('Unexpected value from SP Server');
		}
		$permissions = new BasePermissions();
		$permissions->High = $data->High;
		$permissions->Low = $data->Low;

		return $permissions;
	}

	/**
	 * @return ClientObjectCollection[]
	 */
	public function getDocumentLibraries() {
		$this->ensureConnection();
		$lists = $this->context->getWeb()->getLists();
		$lists->filter('BaseTemplate eq 101 and hidden eq false and NoCrawl eq false');

		$this->loadAndExecute($lists);
		return $lists->getData();
	}

	public function getDocumentLibrary(string $documentLibrary): SPList {
		static $list = null;
		if ($list instanceof SPList) {
			return $list;
		}

		$this->ensureConnection();
		$title = substr($documentLibrary, strrpos($documentLibrary, '/'));
		$lists = $this->context->getWeb()->getLists()->getByTitle($title);
		$this->loadAndExecute($lists);
		if ($lists instanceof SPList) {
			$list = $lists;
			$rFolder = $list->getRootFolder();
			$this->loadAndExecute($rFolder);
			return $list;
		}
		throw new NotFoundException('List not found');
	}

	/**
	 * shortcut for querying a provided object from SP
	 *
	 * @param ClientObject $object
	 * @param array|null $properties
	 */
	public function loadAndExecute(ClientObject $object, array $properties = null) {
		$this->context->load($object, $properties);
		$this->context->executeQuery();
	}

	/**
	 * Set up necessary contexts for authentication and access to SharePoint
	 *
	 * @throws \InvalidArgumentException
	 */
	private function ensureConnection() {
		if ($this->context instanceof ClientContext) {
			return;
		}

		if (!is_string($this->credentials['user']) || empty($this->credentials['user'])) {
			throw new \InvalidArgumentException('No user given');
		}
		if (!is_string($this->credentials['password']) || empty($this->credentials['password'])) {
			throw new \InvalidArgumentException('No password given');
		}

		try {
			$this->authContext = $this->contextsFactory->getTokenAuthContext($this->sharePointUrl);
			$this->authContext->acquireTokenForUser($this->credentials['user'], $this->credentials['password']);
		} catch (\Exception $e) {
			// fall back to NTLM
			$this->authContext = $this->contextsFactory->getCredentialsAuthContext($this->credentials['user'], $this->credentials['password']);
			$this->authContext->AuthType = CURLAUTH_NTLM;
			// Auth is not triggered yet with NTLM. This will happen when
			// something is requested from SharePoint (on demand)
		}

		$this->context = $this->contextsFactory->getClientContext($this->sharePointUrl, $this->authContext);
	}
}
