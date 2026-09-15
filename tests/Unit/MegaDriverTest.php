<?php

namespace Tests\Unit;

use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Storage\Drivers\MegaDriver;
use App\Services\Storage\StorageManager;
use App\Values\RemoteItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mega\Crypto\A32;
use Mega\Crypto\Aes;
use Mega\Crypto\Attr;
use Mega\Crypto\Base64Url;
use Mega\Entity\Node;
use Mega\Transport\Connector;
use Tests\TestCase;

class MegaDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_files_correctly_parses_nodes_and_hierarchy(): void
    {
        $masterKey = [1111, 2222, 3333, 4444];
        $masterKeyStr = A32::toString($masterKey);

        $rootHandle = 'ROOT_DRIVE';
        $trashHandle = 'TRASH_BIN';

        // 1. Root folder child: "Folder A"
        $folderAKey = [10, 20, 30, 40];
        $folderAEncKey = A32::toBase64(Aes::encryptKey($masterKeyStr, $folderAKey));
        $folderAAttr = Base64Url::encode(Attr::encrypt(['n' => 'Folder A'], $folderAKey));

        // 2. Child of Folder A: "file1.txt"
        $file1Key = [50, 60, 70, 80, 1, 2, 3, 4];
        $file1EncKey = A32::toBase64(Aes::encryptKey($masterKeyStr, $file1Key));
        $file1Attr = Base64Url::encode(Attr::encrypt(['n' => 'file1.txt'], $file1Key));

        // 3. Direct child of Root: "file2.pdf"
        $file2Key = [90, 100, 110, 120, 5, 6, 7, 8];
        $file2EncKey = A32::toBase64(Aes::encryptKey($masterKeyStr, $file2Key));
        $file2Attr = Base64Url::encode(Attr::encrypt(['n' => 'file2.pdf'], $file2Key));

        // 4. Trashed file (parent is TRASH_BIN) - should be excluded
        $trashFileKey = [130, 140, 150, 160, 9, 10, 11, 12];
        $trashFileEncKey = A32::toBase64(Aes::encryptKey($masterKeyStr, $trashFileKey));
        $trashFileAttr = Base64Url::encode(Attr::encrypt(['n' => 'deleted.txt'], $trashFileKey));

        $simulatedResponse = [
            'f' => [
                // Root node (t = 2)
                ['h' => $rootHandle, 'p' => 'user_root', 't' => 2],
                // Trash node (t = 4)
                ['h' => $trashHandle, 'p' => 'user_root', 't' => 4],
                // Folder A under Root
                [
                    'h' => 'HANDLE_FOLDER_A',
                    'p' => $rootHandle,
                    't' => Node::TYPE_FOLDER,
                    'k' => 'u123:'.$folderAEncKey,
                    'a' => $folderAAttr,
                ],
                // file1.txt under Folder A
                [
                    'h' => 'HANDLE_FILE_1',
                    'p' => 'HANDLE_FOLDER_A',
                    't' => Node::TYPE_FILE,
                    'k' => 'u123:'.$file1EncKey,
                    'a' => $file1Attr,
                    's' => 1024,
                ],
                // file2.pdf under Root
                [
                    'h' => 'HANDLE_FILE_2',
                    'p' => $rootHandle,
                    't' => Node::TYPE_FILE,
                    'k' => 'u123:'.$file2EncKey,
                    'a' => $file2Attr,
                    's' => 2048,
                ],
                // deleted.txt in Trash
                [
                    'h' => 'HANDLE_TRASH_FILE',
                    'p' => $trashHandle,
                    't' => Node::TYPE_FILE,
                    'k' => 'u123:'.$trashFileEncKey,
                    'a' => $trashFileAttr,
                    's' => 512,
                ],
            ],
        ];

        $mockConnector = $this->createMock(Connector::class);
        $mockConnector->method('send')->willReturnCallback(function (array $payload) use ($simulatedResponse) {
            if (($payload['a'] ?? '') === 'f') {
                return $simulatedResponse;
            }

            return [];
        });

        $storageManager = $this->createMock(StorageManager::class);

        $driver = new class($storageManager, $mockConnector) extends MegaDriver
        {
            public function __construct(StorageManager $sm, private Connector $mockConn)
            {
                parent::__construct($sm);
            }

            public function connector(StorageAccount $account): Connector
            {
                return $this->mockConn;
            }
        };

        $account = new StorageAccount;
        $account->forceFill([
            'credentials' => [
                'session_id' => 'fake_session',
                'master_key' => $masterKey,
                'root_handle' => $rootHandle,
            ],
        ]);

        /** @var RemoteItem[] $items */
        $items = iterator_to_array($driver->listFiles($account));

        $this->assertCount(3, $items);

        // Index by id
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[$item->id] = $item;
        }

        // Check Folder A
        $this->assertArrayHasKey('HANDLE_FOLDER_A', $itemsById);
        $folderA = $itemsById['HANDLE_FOLDER_A'];
        $this->assertSame('Folder A', $folderA->name);
        $this->assertTrue($folderA->isFolder);
        $this->assertNull($folderA->parentId, 'Root child should have null parentId');

        // Check file1.txt
        $file1Ref = 'HANDLE_FILE_1|u123:'.$file1EncKey;
        $this->assertArrayHasKey($file1Ref, $itemsById);
        $file1 = $itemsById[$file1Ref];
        $this->assertSame('file1.txt', $file1->name);
        $this->assertFalse($file1->isFolder);
        $this->assertSame(1024, $file1->size);
        $this->assertSame('HANDLE_FOLDER_A', $file1->parentId, 'Subfolder child should have parentId = HANDLE_FOLDER_A');

        // Check file2.pdf
        $file2Ref = 'HANDLE_FILE_2|u123:'.$file2EncKey;
        $this->assertArrayHasKey($file2Ref, $itemsById);
        $file2 = $itemsById[$file2Ref];
        $this->assertSame('file2.pdf', $file2->name);
        $this->assertFalse($file2->isFolder);
        $this->assertSame(2048, $file2->size);
        $this->assertNull($file2->parentId, 'Root child file should have null parentId');

        // Verify trash file was NOT included
        $trashFileRef = 'HANDLE_TRASH_FILE|u123:'.$trashFileEncKey;
        $this->assertArrayNotHasKey($trashFileRef, $itemsById);
    }

    public function test_list_files_resolves_root_handle_if_missing(): void
    {
        $masterKey = [1111, 2222, 3333, 4444];
        $masterKeyStr = A32::toString($masterKey);

        $rootHandle = 'AUTO_DETECTED_ROOT';

        $fileKey = [50, 60, 70, 80, 1, 2, 3, 4];
        $fileEncKey = A32::toBase64(Aes::encryptKey($masterKeyStr, $fileKey));
        $fileAttr = Base64Url::encode(Attr::encrypt(['n' => 'hello.txt'], $fileKey));

        $simulatedResponse = [
            'f' => [
                ['h' => $rootHandle, 'p' => 'user_root', 't' => 2],
                [
                    'h' => 'HANDLE_HELLO',
                    'p' => $rootHandle,
                    't' => Node::TYPE_FILE,
                    'k' => 'u123:'.$fileEncKey,
                    'a' => $fileAttr,
                    's' => 100,
                ],
            ],
        ];

        $mockConnector = $this->createMock(Connector::class);
        $mockConnector->method('send')->willReturnCallback(function (array $payload) use ($simulatedResponse) {
            if (($payload['a'] ?? '') === 'f') {
                return $simulatedResponse;
            }

            return [];
        });

        $storageManager = $this->createMock(StorageManager::class);

        $driver = new class($storageManager, $mockConnector) extends MegaDriver
        {
            public function __construct(StorageManager $sm, private Connector $mockConn)
            {
                parent::__construct($sm);
            }

            public function connector(StorageAccount $account): Connector
            {
                return $this->mockConn;
            }
        };

        $user = User::create(['name' => 'Test', 'email' => 'test@test.local', 'password' => bcrypt('secret')]);
        $provider = StorageProvider::create(['name' => 'mega', 'driver_class' => MegaDriver::class, 'is_active' => true]);
        $account = StorageAccount::create([
            'user_id' => $user->id,
            'storage_provider_id' => $provider->id,
            'alias' => 'Test MEGA',
            'credentials' => [
                'session_id' => 'fake_session',
                'master_key' => $masterKey,
                // Notice: no root_handle provided!
            ],
        ]);

        /** @var RemoteItem[] $items */
        $items = iterator_to_array($driver->listFiles($account));

        /** @var mixed $freshCreds */
        $freshCreds = $account->fresh()?->credentials;
        $this->assertIsArray($freshCreds);
        $this->assertSame($rootHandle, $freshCreds['root_handle']);
    }
}
