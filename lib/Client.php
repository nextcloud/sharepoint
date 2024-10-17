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

use Exception;
use OCA\SharePoint\Storage\Storage;
use OCA\SharePoint\Vendor\Office365\Runtime\ClientObject;
use OCA\SharePoint\Vendor\Office365\Runtime\ClientObjectCollection;
use OCA\SharePoint\Vendor\Office365\Runtime\Http\RequestException;
use OCA\SharePoint\Vendor\Office365\Runtime\Http\RequestOptions;
use OCA\SharePoint\Vendor\Office365\SharePoint\BasePermissions;
use OCA\SharePoint\Vendor\Office365\SharePoint\ClientContext;
use OCA\SharePoint\Vendor\Office365\SharePoint\Field;
use OCA\SharePoint\Vendor\Office365\SharePoint\File;
use OCA\SharePoint\Vendor\Office365\SharePoint\FileCreationInformation;
use OCA\SharePoint\Vendor\Office365\SharePoint\Folder;
use OCA\SharePoint\Vendor\Office365\SharePoint\Internal\Paths\FileContentPath;
use OCA\SharePoint\Vendor\Office365\SharePoint\SPList;
use Psr\Log\LoggerInterface;
use function explode;
use function json_decode;
use function OCP\Log\logger;

class Client {
	public const DEFAULT_PROPERTIES = [
		Storage::SP_PROPERTY_MTIME,
		Storage::SP_PROPERTY_NAME,
		Storage::SP_PROPERTY_SIZE,
	];

	/** @var ClientContext */
	protected $context;

	/** @var array */
	protected $options;
	/** @var ContextsFactory */
	private $contextsFactory;

	/** @var string */
	private $sharePointUrl;

	/** @var string[] */
	private $credentials;

	/** @var string[] */
	private $knownSP2013SystemFolders = ['Forms', 'Item', 'Attachments'];

	private LoggerInterface $logger;
	// as there is one client per storage it is a 1:1 Client<->DocumentLibrary relation (lazy-loading)
	private ?SPList $documentLibrary = null;
	private ?Folder $documentLibraryRootFolder = null;

	public function __construct(
		ContextsFactory $contextsFactory,
		LoggerInterface $logger,
		string $sharePointUrl,
		array $credentials,
		array $options,
	) {
		$this->contextsFactory = $contextsFactory;
		$this->sharePointUrl = $sharePointUrl;
		$this->credentials = $credentials;
		$this->options = $options;
		$this->logger = $logger;
	}

	/**
	 * Returns the corresponding File or Folder object for the provided path.
	 * If none can be retrieved, an exception is thrown.
	 *
	 * @param string $path
	 * @param array $properties
	 * @return File|Folder
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function fetchFileOrFolder($path, ?array $properties = null) {
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
			} catch (RequestException $e) {
				if ($e->getCode() === 404) {
					continue;
				}
				$payload = json_decode($e->getMessage(), true);
				$responseCodeJson = $payload['error'];
				$spErrorCode = (int)explode(',', $responseCodeJson['code'])[0];
				if ($this->isErrorDoesNotExist($spErrorCode)) {
					continue;
				}
				throw $e;
			}
		}

		# Nothing succeeded, quit with not found
		throw new NotFoundException('File or Folder not found');
	}

	private function isErrorDoesNotExist(int $sharePointErrorCode): bool {
		return in_array($sharePointErrorCode, [
			-2130575338, # Microsoft.SharePoint.SPException: The file $path does not exist
			-2146232832, # Microsoft.SharePoint.SPException (unclear)
			-2147024894, # System.IO.FileNotFoundException: File Not Found.
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
	public function fetchFile($relativeServerPath, ?array $properties = null): File {
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
	public function fetchFolder($relativeServerPath, ?array $properties = null) {
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
	 * @throws Exception
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
	 * @throws Exception
	 */
	public function getFileViaStream($relativeServerPath, $fp) {
		if (!is_resource($fp)) {
			throw new \InvalidArgumentException('file resource expected');
		}
		$this->ensureConnection();
		try {
			$file = $this->fetchFile($relativeServerPath);
			$file->download($fp)->executeQuery();
		} catch (RequestException $e) {
			$this->logger->error('Error while downloading file from Sharepoint', [
				'app' => 'sharepoint',
				'exception' => $e,
			]);
			return false;
		}
		return true;
	}

	/**
	 * @param string $relativeServerPath
	 * @param resource $fp
	 * @param string $localPath - we need to pass the file size for the content length header
	 * @return void
	 * @throws RequestException
	 */
	public function overwriteFileViaStream($relativeServerPath, $fp, $localPath): void {
		// inspired by File::saveBinary()
		$file = $this->fetchFile($relativeServerPath);
		$contentPath = new FileContentPath($file->getResourcePath());
		$url = $this->context->getServiceRootUrl() . $contentPath->toUrl();

		$request = new RequestOptions($url);
		$request->Method = 'POST'; // yes, POST
		$request->ensureHeader('X-HTTP-Method', 'PUT'); // yes, PUT
		$this->context->ensureFormDigest($request);
		$request->StreamHandle = $fp;
		$request->ensureHeader('Content-Length', (string)filesize($localPath));

		$this->context->executeQueryDirect($request);
	}

	/**
	 * FIXME: use StreamHandle as in  overwriteFileViaStream for uploading a file
	 * needs to reimplement adding-file-tp-sp-logic quite some… perhaps upload an
	 * empty file and continue with overwriteFileViaStream?
	 *
	 * @param $relativeServerPath
	 * @param $content
	 * @return File
	 * @throws Exception
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
	 * @throws Exception
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
	 * @throws Exception
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
		if (!$file instanceof File && !$file instanceof Folder && !$file instanceof Field) {
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

	/**
	 * @throws NotFoundException
	 */
	public function getDocumentLibrary(string $documentLibrary): SPList {
		if ($this->documentLibrary === null) {
			$this->ensureConnection();
			$title = substr($documentLibrary, strrpos($documentLibrary, '/'));
			$lists = $this->context->getWeb()->getLists()->getByTitle($title);
			$this->loadAndExecute($lists);
			if ($lists instanceof SPList) {
				$this->documentLibrary = $lists;
			}
		}
		if ($this->documentLibrary instanceof SPList) {
			return $this->documentLibrary;
		}
		throw new NotFoundException('List not found');
	}

	public function getDocumentLibrariesRootFolder(string $documentLibrary): Folder {
		if ($this->documentLibraryRootFolder === null) {
			$library = $this->getDocumentLibrary($documentLibrary);
			$this->documentLibraryRootFolder = $library->getRootFolder();
			$this->loadAndExecute($this->documentLibraryRootFolder);
		}
		return $this->documentLibraryRootFolder;
	}

	/**
	 * shortcut for querying a provided object from SP
	 *
	 * @param ClientObject $object
	 * @param array|null $properties
	 */
	public function loadAndExecute(ClientObject $object, ?array $properties = null) {
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
			if ($this->options['forceNtlm'] ?? false) {
				throw new Exception('enforced NTLM auth');
			}
			$this->context = $this->contextsFactory->getClientContext($this->sharePointUrl, $this->credentials['user'], $this->credentials['password']);
		} catch (Exception $e) {
			logger('sharepoint')->debug(
				'Failed to acquire token for user, fall back to NTLM auth',
				[
					'app' => 'sharepoint',
					'exception' => $e,
				]
			);
			// fall back to NTLM
			$this->context = $this->contextsFactory->getClientContext($this->sharePointUrl, $this->credentials['user'], $this->credentials['password'], true);
			// Auth is not triggered yet with NTLM. This will happen when
			// something is requested from SharePoint (on demand)
		}
	}
}
