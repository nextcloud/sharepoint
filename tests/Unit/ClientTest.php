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

namespace OCA\SharePoint\Tests\Unit;

use Exception;
use OCA\SharePoint\Client;
use OCA\SharePoint\ContextsFactory;
use OCA\SharePoint\NotFoundException;
use Office365\Runtime\ClientObject;
use Office365\Runtime\OData\ODataRequest;
use Office365\SharePoint\ClientContext;
use Office365\SharePoint\File;
use Office365\SharePoint\FileCollection;
use Office365\SharePoint\Folder;
use Office365\SharePoint\FolderCollection;
use Office365\SharePoint\ListItem;
use Office365\SharePoint\Web;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SharePointClientTest extends TestCase {
	/** @var  ContextsFactory|\PHPUnit_Framework_MockObject_MockObject */
	protected $contextsFactory;

	protected string $documentLibraryTitle;

	protected Client $client;

	protected function setUp(): void {
		parent::setUp();

		$this->contextsFactory = $this->createMock(ContextsFactory::class);
		$credentials = ['user' => 'foobar', 'password' => 'barfoo'];
		$this->documentLibraryTitle = 'Our Docs';

		$this->client = new Client(
			$this->contextsFactory,
			$this->createMock(LoggerInterface::class),
			'my.sp.server',
			$credentials,
			[]
		);
	}

	public function testFetchFileByFileOrFolder() {
		$path = '/' . $this->documentLibraryTitle . '/OurFile.txt';
		$properties = ['Length', 'TimeLastModified'];

		$fileMock = $this->createMock(File::class);

		$webMock = $this->createMock(Web::class);
		$webMock->expects($this->once())
			->method('getFileByServerRelativeUrl')
			->with($path)
			->willReturn($fileMock);

		$clientContextMock = $this->createMock(ClientContext::class);
		$clientContextMock->expects($this->once())
			->method('getWeb')
			->willReturn($webMock);
		$clientContextMock->expects($this->once())
			->method('load')
			->with($fileMock, $properties);
		$clientContextMock->expects($this->once())
			->method('executeQuery');
		$clientContextMock->expects($this->any())
			->method('getPendingRequest')
			->willReturn($this->createMock(ODataRequest::class));

		$this->contextsFactory->expects($this->once())
			->method('getClientContext')
			->willReturn($clientContextMock);

		$fileObject = $this->client->fetchFileOrFolder($path, $properties);
		$this->assertSame($fileMock, $fileObject);
	}

	public function testFetchFolderByFileOrFolder() {
		$path = '/' . $this->documentLibraryTitle . '/Our Directory';
		$properties = ['Length', 'TimeLastModified'];

		$listItemAllFieldsMock = $this->createMock(ListItem::class);

		$folderMock = $this->createMock(Folder::class);
		$folderMock->expects($this->any())
			->method('getListItemAllFields')
			->willReturn($listItemAllFieldsMock);

		$webMock = $this->createMock(Web::class);
		$webMock->expects($this->never())
			->method('getFileByServerRelativeUrl');
		$webMock->expects($this->once())
			->method('getFolderByServerRelativeUrl')
			->with($path)
			->willReturn($folderMock);

		$clientContextMock = $this->createMock(ClientContext::class);
		$clientContextMock->expects($this->once())
			->method('getWeb')
			->willReturn($webMock);
		$clientContextMock->expects($this->atLeastOnce())
			->method('load');
		$clientContextMock->expects($this->atLeastOnce())
			->method('executeQuery');

		$this->contextsFactory->expects($this->atLeastOnce())
			->method('getClientContext')
			->willReturn($clientContextMock);
		$clientContextMock->expects($this->any())
			->method('getPendingRequest')
			->willReturn($this->createMock(ODataRequest::class));

		$folderObject = $this->client->fetchFileOrFolder($path, $properties);
		$this->assertSame($folderMock, $folderObject);
	}

	/**
	 * also fully covers fetchFolder(), loadAndExecute(), createClientContext()
	 */
	public function testFetchNotExistingByFileOrFolder() {
		$path = '/' . $this->documentLibraryTitle . '/Our Directory/not-here.pdf';
		$properties = ['Length', 'TimeLastModified'];

		$fileMock = $this->createMock(File::class);

		$listItemMock = $this->createMock(ListItem::class);
		$folderMock = $this->createMock(Folder::class);
		$folderMock->expects($this->once())
			->method('getListItemAllFields')
			->willReturn($listItemMock);

		$webMock = $this->createMock(Web::class);
		$webMock->expects($this->once())
			->method('getFileByServerRelativeUrl')
			->with($path)
			->willReturn($fileMock);
		$webMock->expects($this->once())
			->method('getFolderByServerRelativeUrl')
			->with($path)
			->willReturn($folderMock);

		$clientContextMock = $this->createMock(ClientContext::class);
		$clientContextMock->expects($this->exactly(2))
			->method('getWeb')
			->willReturn($webMock);
		$clientContextMock->expects($this->exactly(3))
			->method('load')
			->withConsecutive([$fileMock, $properties], [$listItemMock, $this->anything()], [$folderMock, $properties]);
		$clientContextMock->expects($this->exactly(2))
			->method('executeQuery')
			->willReturnCallback(function () use ($path) {
				static $cnt = 0;
				$cnt++;
				if ($cnt === 1) {
					throw new Exception('The file ' . $path . ' does not exist.');
				} elseif ($cnt === 2) {
					$e = new \Exception('Unknown Error');
					$reflected = new \ReflectionObject($e);
					$reflectedTrace = $reflected->getProperty('trace');
					$reflectedTrace->setAccessible(true);
					$reflectedTrace->setValue($e, [
						[
							'function' => 'validateResponse',
							'args' => [
								\json_encode([
									'error' => [
										'code' => '-1,opaque'
									]
								])
							]
						]
					]);
					$reflectedTrace->setAccessible(false);
					throw $e;
				}
			});
		$clientContextMock->expects($this->any())
			->method('getPendingRequest')
			->willReturn($this->createMock(ODataRequest::class));

		$this->contextsFactory->expects($this->exactly(1))
			->method('getClientContext')
			->willReturn($clientContextMock);

		$this->expectException(NotFoundException::class);

		$this->client->fetchFileOrFolder($path, $properties);
	}

	public function testCreateFolderSuccess() {
		$dirName = 'New Project Dir';
		$parentPath = '/' . $this->documentLibraryTitle . '/Our Directory';
		$path = $parentPath . '/'. $dirName;

		$folderCollectionMock = $this->createMock(FolderCollection::class);
		$folderCollectionMock->expects($this->once())
			->method('add')
			->with($dirName);

		$folderMock = $this->createMock(Folder::class);
		$folderMock->expects($this->once())
			->method('getFolders')
			->willReturn($folderCollectionMock);

		$webMock = $this->createMock(Web::class);
		$webMock->expects($this->once())
			->method('getFolderByServerRelativeUrl')
			->with($parentPath)
			->willReturn($folderMock);

		$clientContextMock = $this->createMock(ClientContext::class);
		$clientContextMock->expects($this->once())
			->method('getWeb')
			->willReturn($webMock);
		$clientContextMock->expects($this->once())
			->method('executeQuery');

		$this->contextsFactory->expects($this->once())
			->method('getClientContext')
			->willReturn($clientContextMock);

		$this->client->createFolder($path);
	}

	public function testCreateFolderError() {
		$dirName = 'New Project Dir';
		$parentPath = '/' . $this->documentLibraryTitle . '/Our Directory';
		$path = $parentPath . '/'. $dirName;

		$folderCollectionMock = $this->createMock(FolderCollection::class);
		$folderCollectionMock->expects($this->once())
			->method('add')
			->with($dirName);

		$folderMock = $this->createMock(Folder::class);
		$folderMock->expects($this->once())
			->method('getFolders')
			->willReturn($folderCollectionMock);

		$webMock = $this->createMock(Web::class);
		$webMock->expects($this->once())
			->method('getFolderByServerRelativeUrl')
			->with($parentPath)
			->willReturn($folderMock);

		$clientContextMock = $this->createMock(ClientContext::class);
		$clientContextMock->expects($this->once())
			->method('getWeb')
			->willReturn($webMock);
		$clientContextMock->expects($this->once())
			->method('executeQuery')
			->willThrowException(new Exception('Whatever'));

		$this->contextsFactory->expects($this->exactly(1))
			->method('getClientContext')
			->willReturn($clientContextMock);

		$this->expectException(Exception::class);
		$this->client->createFolder($path);
	}

	public function fileTypeProvider() {
		return [
			[ 'file' ],
			[ 'dir' ],
		];
	}

	/**
	 * @dataProvider fileTypeProvider
	 */
	public function testDelete($fileType) {
		$itemClass = $fileType === 'dir' ? Folder::class : File::class;
		/** @var ClientObject|\PHPUnit_Framework_MockObject_MockObject $itemMock */
		$itemMock = $this->createMock($itemClass);
		$itemMock->expects($this->once())
			->method('recycle');

		$clientContextMock = $this->createMock(ClientContext::class);
		$this->contextsFactory->expects($this->once())
			->method('getClientContext')
			->willReturn($clientContextMock);

		$clientContextMock->expects($this->once())
			->method('executeQuery');

		$this->client->delete($itemMock);
	}

	/**
	 * @dataProvider fileTypeProvider
	 */
	public function testRename($fileType) {
		if ($fileType === 'dir') {
			$fileName = 'Goodies';
			$path = '/' . $this->documentLibraryTitle . '/' . $fileName;
			$newPath = $path . '1337';
			$spFetchMethod = 'getFolderByServerRelativeUrl';
			$spRenameMethod = 'rename';
			$spRenameParameter = $fileName . '1337';
			$itemClass = Folder::class;
		} else {
			$fileName = 'Goodies.asc';
			$path = '/' . $this->documentLibraryTitle . '/' . $fileName;
			$newPath = '/' . $this->documentLibraryTitle . '/Goodies w00t.asc';
			$spFetchMethod = 'getFileByServerRelativeUrl';
			$spRenameMethod = 'moveTo';
			$spRenameParameter = rawurlencode($newPath);
			$itemClass = File::class;
		}

		$listItemAllFieldsMock = $this->createMock(ListItem::class);

		$itemMock = $this->createMock($itemClass);
		$itemMock->expects($this->once())
			->method($spRenameMethod)
			->with($spRenameParameter);
		$itemMock->expects($this->any())
			->method('getListItemAllFields')
			->willReturn($listItemAllFieldsMock);

		$webMock = $this->createMock(Web::class);
		$webMock->expects($this->once())
			->method($spFetchMethod)
			->with($path)
			->willReturn($itemMock);

		$clientContextMock = $this->createMock(ClientContext::class);
		$clientContextMock->expects($this->once())
			->method('getWeb')
			->willReturn($webMock);
		$clientContextMock->expects($this->atLeast(2))
			->method('executeQuery');
		$clientContextMock->expects($this->any())
			->method('getPendingRequest')
			->willReturn($this->createMock(ODataRequest::class));

		$this->contextsFactory->expects($this->once())
			->method('getClientContext')
			->willReturn($clientContextMock);

		$this->client->rename($path, $newPath);
	}

	public function testFetchFolderContents() {
		$folderCollectionMock = $this->createMock(FolderCollection::class);
		$fileCollectionMock = $this->createMock(FileCollection::class);

		/** @var Folder|\PHPUnit_Framework_MockObject_MockObject $folderMock */
		$folderMock = $this->createMock(Folder::class);
		$folderMock->expects($this->once())
			->method('getFolders')
			->willReturn($folderCollectionMock);
		$folderMock->expects($this->once())
			->method('getFiles')
			->willReturn($fileCollectionMock);

		$clientContextMock = $this->createMock(ClientContext::class);
		$clientContextMock->expects($this->exactly(2))
			->method('load')
			->withConsecutive([$folderCollectionMock], [$fileCollectionMock]);
		$clientContextMock->expects($this->once())
			->method('executeQuery');

		$this->contextsFactory->expects($this->once())
			->method('getClientContext')
			->willReturn($clientContextMock);

		$result = $this->client->fetchFolderContents($folderMock);
		$this->assertSame($result['folders'], $folderCollectionMock);
		$this->assertSame($result['files'], $fileCollectionMock);
	}

	public function authOptionsProvider(): array {
		return [
			#0: NTLM not enforced, with Exception (i.e. NTLM fallback)
			[ false, true ],
			#1: NTLM not enforced, without Exception
			[ false, false ],
			#1: NTLM enforced
			[ true ]
		];
	}

	/**
	 * @dataProvider authOptionsProvider
	 */
	public function testConnectionNtlmHandling(bool $forceNtlm, bool $throwsException = false): void {
		$credentials = ['user' => 'foobar', 'password' => 'barfoo'];

		$clientContext = $this->createMock(ClientContext::class);

		$this->contextsFactory->expects($this->exactly($throwsException ? 2 : 1))
			->method('getClientContext')
			->with('my.sp.server', $credentials['user'], $credentials['password'], $this->anything())
			->willReturnCallback(function (string $url, string $user, string $pwd, $useNtlm) use ($throwsException, $clientContext): ClientContext {
				if ($throwsException && !$useNtlm) {
					throw new \Exception('Expected exceptiopn');
				}
				return $clientContext;
			});

		$client = new Client(
			$this->contextsFactory,
			$this->createMock(LoggerInterface::class),
			'my.sp.server',
			$credentials,
			[ 'forceNtlm' => $forceNtlm ]
		);

		$this->invokePrivate($client, 'ensureConnection');
	}
}
