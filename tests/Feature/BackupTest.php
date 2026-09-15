<?php

namespace Tests\Feature;

use App\Exceptions\BackupException;
use App\Models\Label;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\VirtualFile;
use App\Models\VirtualFolder;
use App\Services\Backup\BackupService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\FakeDriver;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/rpeb_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            app(Filesystem::class)->deleteDirectory($this->tempDir);
        }

        $telegramDir = storage_path('app/'.config('rpebs.telegram.session_path', 'telegram-sessions'));
        foreach (['test.madeline', 'test_backup.madeline'] as $sub) {
            $path = $telegramDir.'/'.$sub;
            if (is_dir($path)) {
                app(Filesystem::class)->deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_creates_and_verifies_encrypted_backup_archive(): void
    {
        $user = User::factory()->create();
        $provider = StorageProvider::create([
            'name' => 'google_drive',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);

        $account = $user->storageAccounts()->create([
            'storage_provider_id' => $provider->id,
            'alias' => 'Drive Utama',
            'credentials' => ['access_token' => 'secret_oauth_token'],
            'status' => 'active',
        ]);

        $folder = VirtualFolder::create([
            'user_id' => $user->id,
            'name' => 'Dokumen',
        ]);

        $file = VirtualFile::create([
            'user_id' => $user->id,
            'virtual_folder_id' => $folder->id,
            'storage_account_id' => $account->id,
            'name' => 'catatan.txt',
            'remote_ref' => 'ref-001',
            'size' => 120,
            'mime_type' => 'text/plain',
        ]);

        $label = Label::create([
            'user_id' => $user->id,
            'name' => 'Penting',
            'color' => '#14b8a6',
        ]);
        $file->labels()->attach($label);

        // Dummy Telegram session
        $telegramDir = storage_path('app/'.config('rpebs.telegram.session_path', 'telegram-sessions'));
        if (! is_dir($telegramDir)) {
            mkdir($telegramDir, 0777, true);
        }
        $dummySession = $telegramDir.'/test_backup.madeline';
        if (! is_dir($dummySession)) {
            mkdir($dummySession, 0777, true);
        }
        file_put_contents($dummySession.'/safe.php', 'telegram_session_binary_state');
        file_put_contents($dummySession.'/lock', 'ignore_me_lock');

        $backupService = app(BackupService::class);
        $outputPath = $this->tempDir.'/backup.zip';
        $createdPath = $backupService->createBackup('secretPassword123', $outputPath);

        $this->assertFileExists($createdPath);

        // Verify with right password
        $manifest = $backupService->verifyBackup($createdPath, 'secretPassword123');
        $this->assertEquals('1.0', $manifest['format_version']);
        $this->assertEquals(1, $manifest['table_counts']['users']);
        $this->assertEquals(1, $manifest['table_counts']['virtual_files']);
        $this->assertEquals(1, $manifest['table_counts']['virtual_folders']);
        $this->assertEquals(1, $manifest['table_counts']['labels']);

        // Verify with wrong password throws exception
        $this->expectException(BackupException::class);
        $backupService->verifyBackup($createdPath, 'wrongPassword');
    }

    public function test_it_restores_complete_system_from_backup(): void
    {
        $user = User::factory()->create(['email' => 'original@example.com']);
        $provider = StorageProvider::create([
            'name' => 'google_drive',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);

        $account = $user->storageAccounts()->create([
            'storage_provider_id' => $provider->id,
            'alias' => 'Akun Lama',
            'credentials' => ['token' => 'token_lama'],
            'status' => 'active',
        ]);

        $folder = VirtualFolder::create([
            'user_id' => $user->id,
            'name' => 'Folder Lama',
        ]);

        $file = VirtualFile::create([
            'user_id' => $user->id,
            'virtual_folder_id' => $folder->id,
            'storage_account_id' => $account->id,
            'name' => 'file_lama.pdf',
            'remote_ref' => 'ref-old',
            'size' => 2048,
            'mime_type' => 'application/pdf',
        ]);

        $backupService = app(BackupService::class);
        $backupPath = $this->tempDir.'/system_backup.zip';
        $backupService->createBackup('masterKey123', $backupPath);

        // Now mutate database to simulate a wiped or fresh machine
        VirtualFile::query()->delete();
        VirtualFolder::query()->delete();
        StorageAccount::query()->delete();
        User::query()->delete();

        // Fresh installation created a new default user
        $freshUser = User::factory()->create(['email' => 'fresh@example.com']);

        $this->assertEquals(1, User::count());
        $this->assertEquals(0, VirtualFile::count());

        // Restore from backup
        $result = $backupService->restoreBackup($backupPath, 'masterKey123');

        // Verify restoration
        $this->assertEquals(1, User::where('email', 'original@example.com')->count());
        $this->assertEquals(0, User::where('email', 'fresh@example.com')->count());
        $this->assertEquals(1, StorageAccount::where('alias', 'Akun Lama')->count());
        $this->assertEquals(1, VirtualFolder::where('name', 'Folder Lama')->count());
        $this->assertEquals(1, VirtualFile::where('name', 'file_lama.pdf')->count());

        // Verify credentials decrypt properly
        $restoredAccount = StorageAccount::where('alias', 'Akun Lama')->firstOrFail();
        $this->assertEquals(['token' => 'token_lama'], $restoredAccount->credentials);
    }

    public function test_backup_export_and_restore_artisan_commands(): void
    {
        $user = User::factory()->create(['email' => 'artisan@example.com']);
        $backupPath = $this->tempDir.'/artisan_backup.zip';

        $this->artisan('backup:export', [
            '--password' => 'secretArtisanPass123',
            '--output' => $backupPath,
        ])->assertSuccessful();

        $this->assertFileExists($backupPath);

        // Wipe user
        User::query()->delete();
        $this->assertEquals(0, User::count());

        // Restore
        $this->artisan('backup:restore', [
            'path' => $backupPath,
            '--password' => 'secretArtisanPass123',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertEquals(1, User::where('email', 'artisan@example.com')->count());
    }

    public function test_settings_backup_web_routes(): void
    {
        $user = User::factory()->create();

        // Guest cannot access settings backup
        $this->get(route('backup.index'))->assertRedirect(route('login'));

        $this->actingAs($user);

        // Authenticated user visits settings backup
        $this->get(route('backup.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Backup')
                ->has('stats')
            );

        // Export via web
        $response = $this->post(route('backup.export'), [
            'password' => 'webPassword123',
            'password_confirmation' => 'webPassword123',
        ]);

        $response->assertOk();
        $this->assertTrue($response->headers->contains('content-type', 'application/zip'));

        // Save content to test restore
        $zipContent = $response->streamedContent();
        $tempZip = $this->tempDir.'/web_test.zip';
        file_put_contents($tempZip, $zipContent);

        $uploadedFile = new UploadedFile(
            $tempZip,
            'backup.zip',
            'application/zip',
            null,
            true
        );

        // Restore via settings web
        $restoreResponse = $this->post(route('backup.restore'), [
            'backup_file' => $uploadedFile,
            'password' => 'webPassword123',
        ]);

        $restoreResponse->assertRedirect();
    }

    public function test_guest_can_restore_from_login_page(): void
    {
        $user = User::factory()->create(['email' => 'laptop1@example.com']);
        $backupService = app(BackupService::class);
        $tempZip = $this->tempDir.'/guest_backup.zip';
        $backupService->createBackup('passwordSatu123', $tempZip);

        // Simulate new laptop without user
        User::query()->delete();

        // Visit restore page
        $this->get(route('restore.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/RestoreBackup'));

        $uploaded = new UploadedFile(
            $tempZip,
            'guest_backup.zip',
            'application/zip',
            null,
            true
        );

        // Post to guest restore
        $res = $this->post(route('restore.perform'), [
            'backup_file' => $uploaded,
            'password' => 'passwordSatu123',
        ]);

        $res->assertRedirect(route('login'));
        $res->assertSessionHas('status');

        $this->assertEquals(1, User::where('email', 'laptop1@example.com')->count());
    }
}
