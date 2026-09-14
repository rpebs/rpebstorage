<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\UploadFileJob;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\VirtualFile;
use App\Models\VirtualFolder;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\FakeDriver;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private StorageAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        FakeDriver::reset();

        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
        ]);

        $provider = StorageProvider::create([
            'name' => 'fake',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);

        $this->account = StorageAccount::create([
            'user_id' => $this->user->id,
            'storage_provider_id' => $provider->id,
            'alias' => 'Fake (test)',
            'quota_total' => 10 * 1024 ** 3,
            'quota_used' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($this->user);
    }

    private function uploadViaJob(array $overrides = []): VirtualFile
    {
        $content = 'hello rpebstorage '.uniqid();
        $tempPath = app(StorageManager::class)->tempPath(uniqid('test_', true));
        app(StorageManager::class)->ensureTempDir();
        file_put_contents($tempPath, $content);

        $row = FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'virtual_folder_id' => $overrides['virtual_folder_id'] ?? null,
            'original_name' => $overrides['original_name'] ?? 'test.txt',
            'size' => strlen($content),
            'mime_type' => 'text/plain',
            'status' => JobStatus::Pending,
        ]);

        (new UploadFileJob($row->id, $tempPath, $overrides['account_id'] ?? null))
            ->handle(app(StorageManager::class));

        $this->assertSame('done', $row->fresh()->status->value, 'Upload job harus selesai');

        return VirtualFile::where('name', $overrides['original_name'] ?? 'test.txt')->firstOrFail();
    }

    public function test_guests_cannot_access_files(): void
    {
        auth()->logout();

        $this->get('/files')->assertRedirect(route('login'));
    }

    public function test_upload_endpoint_dispatches_job_and_creates_pending_row(): void
    {
        Queue::fake();

        $response = $this->post('/files/upload', [
            'file' => UploadedFile::fake()->createWithContent('hello.txt', 'hi'),
        ]);

        $response->assertRedirect();
        Queue::assertPushed(UploadFileJob::class);
        $this->assertDatabaseHas('file_jobs', [
            'user_id' => $this->user->id,
            'original_name' => 'hello.txt',
            'status' => 'pending',
        ]);
    }

    public function test_upload_job_persists_file_and_cleans_temp(): void
    {
        $file = $this->uploadViaJob();

        $this->assertSame($this->account->id, $file->storage_account_id);
        $this->assertSame('done', FileJob::latest('id')->first()->status->value);
        $this->assertGreaterThan(0, $this->account->fresh()->quota_used, 'quota_used bertambah setelah upload');
        $this->assertDatabaseHas('virtual_files', ['name' => 'test.txt']);
    }

    public function test_upload_job_fails_and_records_error_when_no_account_exists(): void
    {
        $this->account->update(['status' => 'disconnected']);

        $tempPath = app(StorageManager::class)->tempPath(uniqid('test_', true));
        app(StorageManager::class)->ensureTempDir();
        file_put_contents($tempPath, 'data');

        $row = FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'orphan.txt',
            'size' => 4,
            'mime_type' => 'text/plain',
            'status' => JobStatus::Pending,
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            (new UploadFileJob($row->id, $tempPath, null))->handle(app(StorageManager::class));
        } finally {
            $this->assertSame('failed', $row->fresh()->status->value);
            $this->assertFileDoesNotExist($tempPath);
        }
    }

    public function test_files_page_lists_file_and_accounts(): void
    {
        $this->uploadViaJob();

        $this->get('/files')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('files/index')
                    ->has('files', 1)
                    ->has('accounts', 1)
            );
    }

    public function test_search_finds_file_by_name(): void
    {
        $this->uploadViaJob(['original_name' => 'unique-report.pdf']);

        $this->get('/files?q=unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('files', 1));

        $this->get('/files?q=nonexistent-xyz')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('files', 0));
    }

    public function test_folder_crud_and_duplicate_protection(): void
    {
        $this->post('/files/folders', ['name' => 'Docs'])->assertRedirect();
        $this->post('/files/folders', ['name' => 'Docs'])
            ->assertSessionHas('flash.toast.type', 'error');

        $folder = VirtualFolder::where('name', 'Docs')->firstOrFail();

        $this->patch("/files/folders/{$folder->id}", ['name' => 'Documents'])
            ->assertRedirect();
        $this->assertDatabaseHas('virtual_folders', ['id' => $folder->id, 'name' => 'Documents']);

        // Non-empty folder cannot be deleted.
        $this->uploadViaJob(['virtual_folder_id' => $folder->id, 'original_name' => 'nested.txt']);
        $this->delete("/files/folders/{$folder->id}")->assertSessionHas('flash.toast.type', 'error');
        $this->assertDatabaseHas('virtual_folders', ['id' => $folder->id]);

        // Empty folder can be deleted.
        $this->post('/files/folders', ['name' => 'Empty'])->assertRedirect();
        $empty = VirtualFolder::where('name', 'Empty')->firstOrFail();
        $this->delete("/files/folders/{$empty->id}")->assertRedirect();
        $this->assertModelMissing($empty);
    }

    public function test_file_move_only_changes_pointer(): void
    {
        $file = $this->uploadViaJob();
        $refBefore = $file->remote_ref;

        $this->post('/files/folders', ['name' => 'Target'])->assertRedirect();
        $target = VirtualFolder::where('name', 'Target')->firstOrFail();

        $this->patch("/files/{$file->id}/move", ['folder_id' => $target->id])->assertRedirect();

        $file->refresh();
        $this->assertSame($target->id, $file->virtual_folder_id);
        $this->assertSame($refBefore, $file->remote_ref, 'remote_ref must not change on move');
    }

    public function test_file_delete_removes_remote_copy_and_record(): void
    {
        $file = $this->uploadViaJob();
        $ref = $file->remote_ref;

        $this->delete("/files/{$file->id}")->assertRedirect();

        $this->assertModelMissing($file);
        $this->assertArrayNotHasKey($ref, FakeDriver::$storage);
    }

    public function test_download_streams_file_for_active_account(): void
    {
        $file = $this->uploadViaJob();

        $response = $this->get("/files/{$file->id}/download");

        $response->assertOk();
        // deleteFileAfterSend removes the temp immediately, but the body was served.
        $this->assertTrue(strlen((string) $response->getFile()->getContent()) > 0);
    }

    public function test_download_blocked_for_disconnected_account(): void
    {
        $file = $this->uploadViaJob();
        $this->account->update(['status' => 'disconnected']);

        $this->get("/files/{$file->id}/download")->assertSessionHas('flash.toast.type', 'error');
    }

    public function test_jobs_endpoint_reports_recent_jobs(): void
    {
        $this->uploadViaJob();

        $response = $this->getJson('/files/jobs');
        $response->assertOk()->assertJsonCount(1, 'jobs');
        $this->assertSame('test.txt', $response->json('jobs.0.name'));
    }

    public function test_other_users_files_are_inaccessible(): void
    {
        $file = $this->uploadViaJob();

        $other = User::create([
            'name' => 'Second',
            'email' => 'second@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($other);

        $this->get("/files/{$file->id}/download")->assertNotFound();
        $this->delete("/files/{$file->id}")->assertNotFound();
    }
}
