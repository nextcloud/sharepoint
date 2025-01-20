<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

	protected ?ClientContext $context;

	private array $knownSP2013SystemFolders = ['Forms', 'Item', 'Attachments'];

	// as there is one client per storage it is a 1:1 Client<->DocumentLibrary relation (lazy-loading)
	private ?SPList $documentLibrary = null;
	private ?Folder $documentLibraryRootFolder = null;

	public function __construct(
		private ContextsFactory $contextsFactory,
		private LoggerInterface $logger,
		private string $sharePointUrl,
		private array $credentials,
		protected array $options,
	) {
	}

	/**
	 * Returns the corresponding File or Folder object for the provided path.
	 * If none can be retrieved, an exception is thrown.
	 *
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function fetchFileOrFolder(string $path, ?array $properties = null): File|Folder {
		$fetchFileFunc = function ($path, $props) {
			return $this->fetchFile($path, $props);
		};
		$fetchFolderFunc = function ($path, $props) {
			return $this->fetchFolder($path, $props);
		};
		$fetchers = [ $fetchFileFunc, $fetchFolderFunc ];
		if (!str_contains($path, '.')) {
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
	 */
	public function fetchFile(string $relativeServerPath, ?array $properties = null): File {
		$this->ensureConnection();
		$file = $this->context->getWeb()->getFileByServerRelativeUrl($relativeServerPath);
		$this->loadAndExecute($file, $properties);
		return $file;
	}

	/**
	 * returns a Folder instance for the provided path
	 */
	public function fetchFolder(string $relativeServerPath, ?array $properties = null): Folder {
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
	 * @throws Exception
	 */
	public function createFolder(string $relativeServerPath): Folder {
		$this->ensureConnection();

		$parentFolder = $this->context->getWeb()->getFolderByServerRelativeUrl(dirname($relativeServerPath));
		$folder = $parentFolder->getFolders()->add(basename($relativeServerPath));

		$this->context->executeQuery();
		return $folder;
	}

	/**
	 * downloads a file by passing it directly into a file resource
	 *
	 * @param resource $fp a file resource open for writing
	 * @return bool
	 * @throws Exception
	 */
	public function getFileViaStream(string $relativeServerPath, $fp): bool {
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
	 * @param resource $fp
	 * @return void
	 * @throws RequestException
	 */
	public function overwriteFileViaStream(string $relativeServerPath, $fp, string $localPath): void {
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
	 * @throws Exception
	 */
	public function uploadNewFile(string $relativeServerPath, string $content): File {
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
	 * @throws Exception
	 */
	public function rename(string $oldPath, string $newPath): bool {
		$this->ensureConnection();

		$item = $this->fetchFileOrFolder($oldPath);
		if ($item instanceof File) {
			$this->renameFile($item, $newPath);
		} else {
			$this->renameFolder($item, $newPath);
		}
		return true;
	}

	/**
	 * renames a folder
	 */
	private function renameFolder(Folder $folder, string $newPath): void {
		$folder->rename(basename($newPath));
		$this->context->executeQuery();
	}

	/**
	 * moves a file
	 */
	private function renameFile(File $file, string $newPath): void {
		$newPath = rawurlencode($newPath);
		$file->moveTo($newPath, 0);
		$this->context->executeQuery();
	}

	/**
	 * deletes a provided File or Folder
	 */
	public function delete(ClientObject $item): void {
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
	 * @throws Exception
	 */
	public function deleteFile(File $file): void {
		$file->recycle();
		$this->context->executeQuery();
	}

	/**
	 * deletes (in fact recycles) the given Folder on SP.
	 */
	public function deleteFolder(Folder $folder): void {
		$folder->recycle();
		$this->context->executeQuery();
	}

	/**
	 * returns a Folder- and a FileCollection of the children of the given directory
	 *
	 * @return ClientObjectCollection[]
	 */
	public function fetchFolderContents(Folder $folder): array {
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
	 */
	public function isHidden(ClientObject $file): bool {
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
			$this->knownSP2013SystemFolders,
			true
		);
	}

	/**
	 * requests the permission for the provided file or folder
	 */
	public function getPermissions(ClientObject $item): BasePermissions {
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
	public function getDocumentLibraries(): array {
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
	public function loadAndExecute(ClientObject $object, ?array $properties = null): void {
		$this->context->load($object, $properties);
		$this->context->executeQuery();
	}

	/**
	 * Set up necessary contexts for authentication and access to SharePoint
	 *
	 * @throws \InvalidArgumentException
	 */
	private function ensureConnection(): void {
		if (isset($this->context)) {
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
