<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\ScanStorageAccountJob;
use App\Models\FileJob;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\VirtualFile;
use App\Models\VirtualFolder;
use App\Services\Storage\StorageManager;
use App\Values\RemoteItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\FakeDriver;
use Tests\TestCase;

class ScanStorageAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private StorageProvider $fakeProvider;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDriver::reset();

        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($this->user);

        $this->fakeProvider = StorageProvider::create([
            'name' => 'fake',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);
    }

    public function test_scan_dispatches_job_for_active_account(): void
    {
        Queue::fake();

        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $this->fakeProvider->id,
            'alias' => 'Cloud Drive',
            'status' => 'active',
        ]);

        $response = $this->post("/accounts/{$account->id}/scan");
        $response->assertRedirect()->assertInertiaFlash('toast');

        Queue::assertPushed(ScanStorageAccountJob::class, function ($job) use ($account) {
            return $job->accountId === $account->id;
        });

        $this->assertDatabaseHas('file_jobs', [
            'user_id' => $this->user->id,
            'storage_account_id' => $account->id,
            'type' => 'scan',
            'status' => JobStatus::Pending->value,
        ]);
    }

    public function test_scan_rejected_for_disconnected_account(): void
    {
        Queue::fake();

        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $this->fakeProvider->id,
            'alias' => 'Putus',
            'status' => 'disconnected',
        ]);

        $this->post("/accounts/{$account->id}/scan")->assertRedirect();
        Queue::assertNothingPushed();
    }

    public function test_scan_rejected_for_telegram_account(): void
    {
        Queue::fake();

        $tgProvider = StorageProvider::create([
            'name' => 'telegram',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);

        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $tgProvider->id,
            'alias' => 'TG Bucket',
            'status' => 'active',
        ]);

        $this->post("/accounts/{$account->id}/scan")->assertRedirect();
        Queue::assertNothingPushed();
    }

    public function test_scan_rejected_if_already_scanning(): void
    {
        Queue::fake();

        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $this->fakeProvider->id,
            'alias' => 'Busy Account',
            'status' => 'active',
        ]);

        FileJob::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $account->id,
            'type' => 'scan',
            'original_name' => 'Pindai Busy Account',
            'status' => JobStatus::Processing,
        ]);

        $this->post("/accounts/{$account->id}/scan")->assertRedirect();
        Queue::assertNothingPushed();
    }

    public function test_scan_cannot_be_triggered_by_another_user(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@test.local',
            'password' => bcrypt('secret'),
        ]);

        $account = $otherUser->storageAccounts()->create([
            'storage_provider_id' => $this->fakeProvider->id,
            'alias' => 'Secret Account',
            'status' => 'active',
        ]);

        $this->post("/accounts/{$account->id}/scan")->assertNotFound();
    }

    public function test_scan_job_imports_hierarchical_folders_and_files(): void
    {
        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $this->fakeProvider->id,
            'alias' => 'Utama',
            'status' => 'active',
        ]);

        FakeDriver::$remoteItems = [
            new RemoteItem(
                id: 'folder-1',
                name: 'Dokumen',
                isFolder: true,
                parentId: null
            ),
            new RemoteItem(
                id: 'folder-2',
                name: 'Sub-Kerja',
                isFolder: true,
                parentId: 'folder-1'
            ),
            new RemoteItem(
                id: 'file-1',
                name: 'catatan.txt',
                isFolder: false,
                size: 256,
                mimeType: 'text/plain',
                parentId: null
            ),
            new RemoteItem(
                id: 'file-2',
                name: 'laporan.pdf',
                isFolder: false,
                size: 1024,
                mimeType: 'application/pdf',
                parentId: 'folder-2'
            ),
        ];

        FakeDriver::$total = 10000;
        FakeDriver::$used = 1280;

        $fileJob = FileJob::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $account->id,
            'type' => 'scan',
            'original_name' => 'Pindai Utama',
            'status' => JobStatus::Pending,
        ]);

        $job = new ScanStorageAccountJob($account->id, $fileJob->id);
        $job->handle(app(StorageManager::class));

        $this->assertSame(JobStatus::Done, $fileJob->fresh()->status);
        $this->assertSame(100, $fileJob->fresh()->progress);

        // Check root folder created
        $rootFolder = VirtualFolder::where('user_id', $this->user->id)
            ->whereNull('parent_id')
            ->where('name', "[{$this->fakeProvider->label()} - Utama]")
            ->first();

        $this->assertNotNull($rootFolder);

        // Check Dokumen folder
        $dokumenFolder = VirtualFolder::where('parent_id', $rootFolder->id)
            ->where('name', 'Dokumen')
            ->first();
        $this->assertNotNull($dokumenFolder);

        // Check Sub-Kerja folder
        $subFolder = VirtualFolder::where('parent_id', $dokumenFolder->id)
            ->where('name', 'Sub-Kerja')
            ->first();
        $this->assertNotNull($subFolder);

        // Check file-1 in root folder
        $file1 = VirtualFile::where('storage_account_id', $account->id)
            ->where('remote_ref', 'file-1')
            ->first();
        $this->assertNotNull($file1);
        $this->assertSame('catatan.txt', $file1->name);
        $this->assertSame($rootFolder->id, $file1->virtual_folder_id);
        $this->assertSame(256, $file1->size);

        // Check file-2 in Sub-Kerja folder
        $file2 = VirtualFile::where('storage_account_id', $account->id)
            ->where('remote_ref', 'file-2')
            ->first();
        $this->assertNotNull($file2);
        $this->assertSame('laporan.pdf', $file2->name);
        $this->assertSame($subFolder->id, $file2->virtual_folder_id);
        $this->assertSame(1024, $file2->size);

        // Check quota updated
        $this->assertSame(10000, $account->fresh()->quota_total);
        $this->assertSame(1280, $account->fresh()->quota_used);
    }

    public function test_scan_job_is_idempotent_and_updates_existing_files(): void
    {
        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $this->fakeProvider->id,
            'alias' => 'Secondary',
            'status' => 'active',
        ]);

        FakeDriver::$remoteItems = [
            new RemoteItem(
                id: 'file-abc',
                name: 'versi_lama.txt',
                isFolder: false,
                size: 100,
                mimeType: 'text/plain',
                parentId: null
            ),
        ];

        $job = new ScanStorageAccountJob($account->id);
        $job->handle(app(StorageManager::class));

        $this->assertSame(1, VirtualFile::where('storage_account_id', $account->id)->count());
        $file = VirtualFile::where('remote_ref', 'file-abc')->first();
        $this->assertSame('versi_lama.txt', $file->name);
        $this->assertSame(100, $file->size);

        // Scan again with updated name & size
        FakeDriver::$remoteItems = [
            new RemoteItem(
                id: 'file-abc',
                name: 'versi_baru.txt',
                isFolder: false,
                size: 200,
                mimeType: 'text/plain',
                parentId: null
            ),
        ];

        $job2 = new ScanStorageAccountJob($account->id);
        $job2->handle(app(StorageManager::class));

        // Still only 1 record, but updated
        $this->assertSame(1, VirtualFile::where('storage_account_id', $account->id)->count());
        $freshFile = VirtualFile::where('remote_ref', 'file-abc')->first();
        $this->assertSame('versi_baru.txt', $freshFile->name);
        $this->assertSame(200, $freshFile->size);
    }

    public function test_scan_job_imports_path_based_items_like_dropbox(): void
    {
        $account = $this->user->storageAccounts()->create([
            'storage_provider_id' => $this->fakeProvider->id,
            'alias' => 'Dropbox Style',
            'status' => 'active',
        ]);

        FakeDriver::$remoteItems = [
            new RemoteItem(
                id: '/photos',
                name: 'Photos',
                isFolder: true,
                remotePath: '/Photos'
            ),
            new RemoteItem(
                id: '/photos/vacation',
                name: 'Vacation',
                isFolder: true,
                remotePath: '/Photos/Vacation'
            ),
            new RemoteItem(
                id: '/photos/vacation/beach.jpg',
                name: 'beach.jpg',
                isFolder: false,
                size: 2048,
                mimeType: 'image/jpeg',
                remotePath: '/Photos/Vacation/beach.jpg'
            ),
        ];

        $job = new ScanStorageAccountJob($account->id);
        $job->handle(app(StorageManager::class));

        $rootFolder = VirtualFolder::where('name', "[{$this->fakeProvider->label()} - Dropbox Style]")->first();
        $this->assertNotNull($rootFolder);

        $photosFolder = VirtualFolder::where('parent_id', $rootFolder->id)->where('name', 'Photos')->first();
        $this->assertNotNull($photosFolder);

        $vacationFolder = VirtualFolder::where('parent_id', $photosFolder->id)->where('name', 'Vacation')->first();
        $this->assertNotNull($vacationFolder);

        $file = VirtualFile::where('remote_ref', '/photos/vacation/beach.jpg')->first();
        $this->assertNotNull($file);
        $this->assertSame($vacationFolder->id, $file->virtual_folder_id);
        $this->assertSame(2048, $file->size);
    }
}
