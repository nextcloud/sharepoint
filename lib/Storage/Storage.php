<?php
/**
 * @copyright Copyright (c) 2016 Arthur Schiwon <blizzz@arthur-schiwon.de>
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

namespace OCA\SharePoint\Storage;

use Icewind\Streams\CallbackWrapper;
use Icewind\Streams\IteratorDirectory;
use OC\Cache\CappedMemoryCache;
use OC\Files\Storage\Common;
use OCA\SharePoint\Client;
use OCA\SharePoint\ClientFactory;
use OCA\SharePoint\ContextsFactory;
use OCA\SharePoint\NotFoundException;
use OCP\Files\FileInfo;
use OCP\ILogger;
use OCP\ITempManager;
use OCP\Server;
use Office365\Runtime\ClientObject;
use Office365\Runtime\ClientObjectCollection;
use Office365\SharePoint\File;
use Office365\SharePoint\Folder;

class Storage extends Common {
	public const SP_PROPERTY_SIZE = 'Length';
	public const SP_PROPERTY_MTIME = 'TimeLastModified';
	public const SP_PROPERTY_MODIFIED = 'Modified';
	public const SP_PROPERTY_MTIME_LAST_ITEM = 'LastItemModifiedDate';
	public const SP_PROPERTY_URL = 'ServerRelativeUrl';
	public const SP_PROPERTY_NAME = 'Name';

	public const SP_PERMISSION_READ = 1;
	public const SP_PERMISSION_CREATE = 2;
	public const SP_PERMISSION_UPDATE = 3;
	public const SP_PERMISSION_DELETE = 4;

	/** @var  string */
	protected $server;

	/** @var  string */
	protected $documentLibrary;

	/** @var  string */
	protected $authUser;

	/** @var  string */
	protected $authPwd;

	/** @var  Client */
	protected $spClient;

	/** @var  CappedMemoryCache */
	protected $fileCache;
	/** @var false|mixed */
	protected $forceNtlm;

	/** @var ContextsFactory */
	private $contextsFactory;

	/** @var ITempManager */
	private $tempManager;

	public function __construct($parameters) {
		$this->server = rtrim($parameters['host'], '/') . '/';
		$this->documentLibrary = ltrim($parameters['documentLibrary'], '/');

		if (strpos($this->documentLibrary, '"') !== false) {
			// they are, amongst others, not allowed and we use it in the filter
			// cf. https://support.microsoft.com/en-us/kb/2933738
			// TODO: verify, it talks about files and folders mostly
			throw new \InvalidArgumentException('Illegal character in Document Library Name');
		}

		if (!isset($parameters['user']) || !isset($parameters['password'])) {
			throw new \UnexpectedValueException('No user or password given');
		}
		$this->authUser = $parameters['user'];
		$this->authPwd = $parameters['password'];
		$this->forceNtlm = $parameters['forceNtlm'] ?? false;

		$this->fixDI($parameters);
	}

	/**
	 * Get the identifier for the storage,
	 * the returned id should be the same for every storage object that is created with the same parameters
	 * and two storage objects with the same id should refer to two storages that display the same files.
	 *
	 * @return string
	 * @since 6.0.0
	 */
	public function getId() {
		return 'SharePoint::' . $this->server . '::' . $this->documentLibrary . '::' . $this->authUser;
	}

	/**
	 * see http://php.net/manual/en/function.mkdir.php
	 * implementations need to implement a recursive mkdir
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function mkdir($path) {
		$serverUrl = $this->formatPath($path);
		try {
			$folder = $this->spClient->createFolder($serverUrl);
			$this->fileCache->set($serverUrl, [
				'instance' => $folder,
				'children' => [
					'folders' => $folder->getFolders(),
					'files' => $folder->getFiles()
				]
			]);
			return true;
		} catch (\Exception $e) {
			$this->fileCache->remove($serverUrl);
			\OC::$server->getLogger()->logException($e,
				[
					'app' => 'sharepoint',
					'level' => ILogger::INFO
				]
			);
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.rmdir.php
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function rmdir($path) {
		$serverUrl = $this->formatPath($path);
		try {
			$folder = $this->getFileOrFolder($serverUrl);
			$this->spClient->delete($folder);
			$this->fileCache->set($serverUrl, false);
			return true;
		} catch (\Exception $e) {
			$this->fileCache->remove($serverUrl);
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.opendir.php
	 *
	 * @param string $path
	 * @return resource|false
	 * @since 6.0.0
	 */
	public function opendir($path) {
		try {
			$serverUrl = $this->formatPath($path);
			$collections = $this->getFolderContents($serverUrl);
			$files = [];

			foreach ($collections as $collection) {
				/** @var File[]|Folder[] $items */
				$items = $collection->getData();
				foreach ($items as $item) {
					if (!$this->spClient->isHidden($item)) {
						$files[] = $item->getProperty(Storage::SP_PROPERTY_NAME);
					}
				}
			}

			return IteratorDirectory::wrap($files);
		} catch (NotFoundException $e) {
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.stat.php
	 * only the following keys are required in the result: size and mtime
	 *
	 * @param string $path
	 * @return array|false
	 * @since 6.0.0
	 */
	public function stat($path) {
		$serverUrl = $this->formatPath($path);
		try {
			if ($path === '' || $path === '/') {
				return $this->statForDocumentLibrary();
			}
			$file = $this->getFileOrFolder($serverUrl);
		} catch (\Exception $e) {
			return false;
		}

		$size = $file->getProperty(self::SP_PROPERTY_SIZE) ?: FileInfo::SPACE_UNKNOWN;
		$mtimeValue = (string)$file->getProperty(self::SP_PROPERTY_MTIME);
		if ($mtimeValue === '') {
			// if sp2013 ListItemAllFields are fetched automatically
			$mtimeValue = $file->getListItemAllFields()->getProperty(self::SP_PROPERTY_MODIFIED);
		}
		$name = (string)$file->getProperty(self::SP_PROPERTY_NAME);

		if ($mtimeValue === '') {
			// SP2013 does not provide an mtime.
			$timestamp = time();
		} else {
			$mtime = new \DateTime($mtimeValue);
			$timestamp = $mtime->getTimestamp();
		}

		$stat = [
			// int64, size in bytes, excluding the size of any Web Parts that are used in the file.
			'size' => $size,
			'mtime' => $timestamp,
			// no property in SP 2013 & 2016, other storages do the same
			'atime' => time(),
		];

		if ($name !== '') {
			// previously, checking mtime was the check, alas SP2013…
			return $stat;
		}

		// If we do not get a mtime from SP, we treat it as an error
		// thus returning false, according to PHP documentation on stat()
		return false;
	}

	protected function statForDocumentLibrary() {
		try {
			$dLib = $this->spClient->getDocumentLibrary($this->documentLibrary);
			$mtimeValue = (string)$dLib->getProperty(self::SP_PROPERTY_MTIME_LAST_ITEM);
		} catch (NotFoundException $e) {
			\OC::$server->getLogger()->logException($e);
			return false;
		}

		if ($mtimeValue === '') {
			// SP2013 does not provide an mtime.
			$timestamp = time();
		} else {
			$mtime = new \DateTime($mtimeValue);
			$timestamp = $mtime->getTimestamp();
		}

		return [
			// int64, size in bytes, excluding the size of any Web Parts that are used in the file.
			'size' => FileInfo::SPACE_UNKNOWN,
			'mtime' => $timestamp,
			// no property in SP 2013 & 2016, other storages do the same
			'atime' => time(),
		];
	}

	/**
	 * see http://php.net/manual/en/function.filetype.php
	 *
	 * @param string $path
	 * @return false|string
	 * @throws \Exception
	 * @since 6.0.0
	 */
	public function filetype($path) {
		try {
			$serverUrl = $this->formatPath($path);
			$object = $this->getFileOrFolder($serverUrl);
		} catch (NotFoundException $e) {
			return false;
		}
		if ($object instanceof File) {
			return 'file';
		} elseif ($object instanceof Folder) {
			return 'dir';
		} else {
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.file_exists.php
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function file_exists($path) {
		try {
			$serverUrl = $this->formatPath($path);
			// alternative approach is to use a CAML query instead of querying
			// for file and folder. It is not necessarily faster, though.
			// Would need evaluation of typical use cases (I assume most often
			// existing files are checked) and measurements.
			$this->getFileOrFolder($serverUrl);
			return true;
		} catch (NotFoundException $e) {
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.unlink.php
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function unlink($path) {
		// file methods get called twice at least, returning true
		if (!$this->file_exists($path)) {
			return true;
		}
		try {
			$serverUrl = $this->formatPath($path);
			$item = $this->getFileOrFolderForQuery($serverUrl);
			$this->spClient->delete($item);
			$this->fileCache->set($serverUrl, false);
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * @param string $path1
	 * @param string $path2
	 * @return bool
	 */
	public function rename($path1, $path2) {
		$oldPath = $this->formatPath($path1);
		$newPath = $this->formatPath($path2);

		try {
			$item = $this->getFileOrFolder($newPath);
			$this->spClient->delete($item);
			$this->fileCache->remove($newPath);
		} catch (NotFoundException $e) {
			// noop
		}

		try {
			$isRenamed = $this->spClient->rename($oldPath, $newPath);
			if ($isRenamed) {
				$entry = $this->fileCache->get($oldPath);
				$this->fileCache->remove($newPath);
				if ($entry !== false) {
					$this->fileCache->set($newPath, $entry);
				}
				$this->fileCache->remove($oldPath);
			}
			return $isRenamed;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.fopen.php
	 *
	 * @param string $path
	 * @param string $mode
	 * @return resource|false
	 * @since 6.0.0
	 */
	public function fopen($path, $mode) {
		$serverUrl = $this->formatPath($path);

		switch ($mode) {
			case 'a':
			case 'ab':
			case 'a+':
				// no native support
				return false;
			case 'r':
			case 'rb':
				$tmpFile = $this->tempManager->getTemporaryFile();

				$fp = fopen($tmpFile, 'w+');
				if (!$this->spClient->getFileViaStream($serverUrl, $fp)) {
					fclose($fp);
					return false;
				}
				fseek($fp, 0);
				return $fp;
				break;
			case 'r+':
			case 'rb+':
			case 'r+b':
				// fseek 0
			case 'w':
			case 'w+':
			case 'wb':
			case 'wb+':
			case 'w+b':
				// truncate
				// fseek 0
			case 'x':
			case 'x+':
			case 'xb':
			case 'xb+':
			case 'x+b':
				// fseek 0
			case 'c':
			case 'cb':
			case 'c+':
			case 'cb+':
			case 'c+b':
				//fseek 0
				if ($mode[0] === 'x' && $this->file_exists($path)) {
					return false;
				}
				$tmpFile = $this->tempManager->getTemporaryFile();
				if ($mode[0] !== 'w' && $this->file_exists($path)) {
					$content = $this->fopen($path, 'r');
					if ($content === false) {
						// should not happen, but let's be safe
						return false;
					}
					$this->file_put_contents($tmpFile, $content);
				}
				$fp = fopen($tmpFile, $mode);
				return CallbackWrapper::wrap($fp, null, null, function () use ($path, $tmpFile) {
					$this->writeBack($tmpFile, $path);
				});
		}
		return false;
	}

	/**
	 * @param string $tmpFile
	 * @param string $path
	 */
	public function writeBack($tmpFile, $path) {
		$serverUrl = $this->formatPath($path);
		$content = file_get_contents($tmpFile);
		$fp = fopen($tmpFile, 'r');

		try {
			if ($this->file_exists($path)) {
				$this->spClient->overwriteFileViaStream($serverUrl, $fp, $tmpFile);
				fclose($fp);
				$this->fileCache->remove($serverUrl);
			} else {
				$file = $this->spClient->uploadNewFile($serverUrl, $content);
				$this->fileCache->set($serverUrl, ['instance' => $file]);
			}
		} catch (\Exception $e) {
			// noop
		}
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public function isCreatable($path) {
		try {
			return $this->hasPermission($path, self::SP_PERMISSION_CREATE);
		} catch (\Exception $e) {
			return parent::isCreatable($path);
		}
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public function isUpdatable($path) {
		try {
			return $this->hasPermission($path, self::SP_PERMISSION_UPDATE);
		} catch (\Exception $e) {
			return parent::isUpdatable($path);
		}
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public function isReadable($path) {
		try {
			return $this->hasPermission($path, self::SP_PERMISSION_READ);
		} catch (\Exception $e) {
			return parent::isReadable($path);
		}
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public function isDeletable($path) {
		try {
			return $this->hasPermission($path, self::SP_PERMISSION_DELETE);
		} catch (\Exception $e) {
			return parent::isDeletable($path);
		}
	}

	/**
	 * @param string $path
	 * @param int $permissionType
	 * @return bool
	 */
	private function hasPermission($path, $permissionType) {
		$serverUrl = $this->formatPath($path);
		return $this->getUserPermissions($serverUrl)->has($permissionType);
	}

	/**
	 * see http://php.net/manual/en/function.touch.php
	 * If the backend does not support the operation, false should be returned
	 *
	 * @param string $path
	 * @param int $mtime
	 * @return bool
	 * @since 6.0.0
	 */
	public function touch($path, $mtime = null) {
		return false;
	}

	/**
	 * work around dependency injection issues, so we can test this class properly
	 *
	 * @param array $parameters
	 */
	private function fixDI(array $parameters) {
		if (isset($parameters['contextFactory'])
			&& $parameters['contextFactory'] instanceof ContextsFactory) {
			$this->contextsFactory = $parameters['contextFactory'];
		} else {
			$this->contextsFactory = new ContextsFactory();
		}

		if (isset($parameters['sharePointClientFactory'])
			&& $parameters['sharePointClientFactory'] instanceof ClientFactory) {
			$spcFactory = $parameters['sharePointClientFactory'];
		} else {
			$spcFactory = Server::get(ClientFactory::class);
		}
		$this->spClient = $spcFactory->getClient(
			$this->contextsFactory,
			$this->server,
			['user' => $this->authUser, 'password' => $this->authPwd],
			['forceNtlm' => $this->forceNtlm]
		);

		if (isset($parameters['cappedMemoryCache'])) {
			$this->fileCache = $parameters['cappedMemoryCache'];
		} else {
			// there's no API to get such
			$this->fileCache = new CappedMemoryCache();
		}

		if (isset($parameters['tempManager'])) {
			$this->tempManager = $parameters['tempManager'];
		} else {
			$this->tempManager = Server::get(ITempManager::class);
		}
	}

	/**
	 * @param $serverUrl
	 * @return ClientObjectCollection[]
	 */
	private function getFolderContents($serverUrl) {
		$folder = $this->getFileOrFolder($serverUrl);
		$entry = $this->fileCache->get($serverUrl);
		if ($entry === null || !isset($entry['children'])) {
			$contents = $this->spClient->fetchFolderContents($folder);
			$cacheItem = $entry ?: [];
			$cacheItem['children'] = $contents;
			$this->fileCache->set($serverUrl, $cacheItem);

			// cache children instances
			foreach ($contents as $collection) {
				foreach ($collection->getData() as $item) {
					/** @var  File|Folder $item */
					$url = $item->getProperty(self::SP_PROPERTY_URL);
					if (is_null($url)) {
						// at least on SP13 requesting self::SP_PROPERTY_URL against folders causes an exception
						continue;
					}
					$itemEntry = $this->fileCache->get($url);
					$itemEntry = $itemEntry ?: [];
					if (!isset($itemEntry['instance'])) {
						$itemEntry['instance'] = $item;
						$this->fileCache->set($url, $itemEntry);
					}
				}
			}
		} else {
			$contents = $entry['children'];
		}
		return $contents;
	}

	/**
	 * @param string $serverUrl
	 * @return \Office365\PHP\Client\SharePoint\BasePermissions
	 * @throws NotFoundException
	 */
	private function getUserPermissions($serverUrl) {
		// temporarily, cf. https://github.com/vgrem/phpSPO/issues/93#issuecomment-489024363
		throw new NotFoundException('Could not retrieve permissions');

		$item = $this->getFileOrFolder($serverUrl);
		$entry = $this->fileCache->get($serverUrl);
		if (isset($entry['permissions'])) {
			if ($entry['permissions'] === false) {
				throw new NotFoundException('Could not retrieve permissions');
			}
			return $entry['permissions'];
		}
		try {
			$permissions = $this->spClient->getPermissions($item);
		} catch (\Exception $e) {
			$permissions = false;
		}
		$entry['permissions'] = $permissions;
		$this->fileCache->set($serverUrl, $entry);
		if ($entry['permissions'] === false) {
			throw new NotFoundException('Could not retrieve permissions');
		}
		return $entry['permissions'];
	}

	/**
	 * @param $serverUrl
	 * @return File|Folder
	 * @throws NotFoundException
	 */
	private function getFileOrFolder($serverUrl) {
		$entry = $this->fileCache->get($serverUrl);
		if ($entry === false) {
			throw new NotFoundException('File or Folder not found');
		} elseif ($entry === null || !isset($entry['instance'])) {
			try {
				$file = $this->spClient->fetchFileOrFolder($serverUrl);
			} catch (NotFoundException $e) {
				$this->fileCache->set($serverUrl, false);
				throw $e;
			} catch (\Exception $e) {
				\OC::$server->getLogger()->logException($e, ['app' => 'sharepoint']);
				throw new NotFoundException($e->getMessage(), $e->getCode(), $e);
			}
			$cacheItem = $entry ?: [];
			$cacheItem['instance'] = $file;
			$this->fileCache->set($serverUrl, $cacheItem);
		} else {
			$file = $entry['instance'];
		}
		return $file;
	}

	private function getFileOrFolderForQuery(string $serverUrl): ClientObject {
		$entry = $this->fileCache->get($serverUrl);
		$item = is_array($entry) && $entry['instance']
			? $entry['instance']
			: null;

		// entries from getFolderContents may have not resourcePath set, and
		// request against this would fail, e.g. on delete.
		if ($item instanceof ClientObject && $item->getResourcePath() === null) {
			$this->fileCache->remove($serverUrl);
			$item = null;
		}
		return $item ?? $this->getFileOrFolder($serverUrl);
	}

	/**
	 * creates the relative server "url" out of the provided path
	 *
	 * @param $path
	 * @return string
	 */
	private function formatPath($path) {
		$path = trim($path, '/');
		$rootFolder = $this->spClient->getDocumentLibrariesRootFolder($this->documentLibrary);
		$serverUrl = $rootFolder->getProperty(self::SP_PROPERTY_URL);
		if ($path !== '') {
			$serverUrl .= '/' . $path;
		}

		$pathParts = explode('/', $serverUrl);
		$filename = array_pop($pathParts);
		if ($filename === '.') {
			// remove /. from the end of the path
			$serverUrl = mb_substr($serverUrl, 0, mb_strlen($serverUrl) - 2);
		}

		return $serverUrl;
	}
}
