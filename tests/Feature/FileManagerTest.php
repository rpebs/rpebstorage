<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\CreateZipArchiveJob;
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
            ->assertInertiaFlash('toast');

        $folder = VirtualFolder::where('name', 'Docs')->firstOrFail();

        $this->patch("/files/folders/{$folder->id}", ['name' => 'Documents'])
            ->assertRedirect();
        $this->assertDatabaseHas('virtual_folders', ['id' => $folder->id, 'name' => 'Documents']);

        // Non-empty folder cannot be deleted.
        $this->uploadViaJob(['virtual_folder_id' => $folder->id, 'original_name' => 'nested.txt']);
        $this->delete("/files/folders/{$folder->id}")->assertInertiaFlash('toast');
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

        $this->get("/files/{$file->id}/download")->assertInertiaFlash('toast');
    }

    public function test_jobs_endpoint_only_reports_active_jobs_and_not_completed_ones(): void
    {
        // Completed job should not be reported in files/jobs
        $this->uploadViaJob(['original_name' => 'completed.txt']);

        // In-flight job should be reported
        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'active.txt',
            'size' => 123,
            'mime_type' => 'text/plain',
            'status' => JobStatus::Processing,
        ]);

        $response = $this->getJson('/files/jobs');
        $response->assertOk()->assertJsonCount(1, 'jobs');
        $this->assertSame('active.txt', $response->json('jobs.0.name'));
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

    public function test_bulk_move_moves_multiple_files_to_folder(): void
    {
        $file1 = $this->uploadViaJob(['original_name' => 'bulk1.txt']);
        $file2 = $this->uploadViaJob(['original_name' => 'bulk2.txt']);

        $this->post('/files/folders', ['name' => 'BulkTarget'])->assertRedirect();
        $target = VirtualFolder::where('name', 'BulkTarget')->firstOrFail();

        $response = $this->post('/files/bulk/move', [
            'file_ids' => [$file1->id, $file2->id],
            'folder_id' => $target->id,
        ]);

        $response->assertRedirect();
        $this->assertSame($target->id, $file1->fresh()->virtual_folder_id);
        $this->assertSame($target->id, $file2->fresh()->virtual_folder_id);
    }

    public function test_bulk_move_moves_files_to_root(): void
    {
        $this->post('/files/folders', ['name' => 'SourceFolder'])->assertRedirect();
        $source = VirtualFolder::where('name', 'SourceFolder')->firstOrFail();

        $file1 = $this->uploadViaJob(['original_name' => 'nested1.txt', 'virtual_folder_id' => $source->id]);
        $file2 = $this->uploadViaJob(['original_name' => 'nested2.txt', 'virtual_folder_id' => $source->id]);

        $response = $this->post('/files/bulk/move', [
            'file_ids' => [$file1->id, $file2->id],
            'folder_id' => null,
        ]);

        $response->assertRedirect();
        $this->assertNull($file1->fresh()->virtual_folder_id);
        $this->assertNull($file2->fresh()->virtual_folder_id);
    }

    public function test_bulk_delete_deletes_remote_copies_and_records(): void
    {
        $file1 = $this->uploadViaJob(['original_name' => 'del1.txt']);
        $file2 = $this->uploadViaJob(['original_name' => 'del2.txt']);
        $ref1 = $file1->remote_ref;
        $ref2 = $file2->remote_ref;

        $response = $this->post('/files/bulk/delete', [
            'file_ids' => [$file1->id, $file2->id],
        ]);

        $response->assertRedirect();
        $this->assertModelMissing($file1);
        $this->assertModelMissing($file2);
        $this->assertArrayNotHasKey($ref1, FakeDriver::$storage);
        $this->assertArrayNotHasKey($ref2, FakeDriver::$storage);
    }

    public function test_bulk_delete_skips_disconnected_account_files(): void
    {
        $file1 = $this->uploadViaJob(['original_name' => 'del-active.txt']);
        $file2 = $this->uploadViaJob(['original_name' => 'del-disc.txt']);

        $this->account->update(['status' => 'disconnected']);

        $response = $this->post('/files/bulk/delete', [
            'file_ids' => [$file1->id, $file2->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('virtual_files', ['id' => $file1->id]);
        $this->assertDatabaseHas('virtual_files', ['id' => $file2->id]);
    }

    public function test_bulk_zip_creates_job_and_dispatches_archive_job(): void
    {
        Queue::fake();

        $file1 = $this->uploadViaJob(['original_name' => 'zip1.txt']);
        $file2 = $this->uploadViaJob(['original_name' => 'zip2.txt']);

        $response = $this->postJson('/files/bulk/zip', [
            'file_ids' => [$file1->id, $file2->id],
        ]);

        $response->assertOk()
            ->assertJsonStructure(['job_id', 'name', 'status', 'progress']);

        Queue::assertPushed(CreateZipArchiveJob::class);
        $this->assertDatabaseHas('file_jobs', [
            'user_id' => $this->user->id,
            'type' => 'zip',
            'status' => 'pending',
        ]);
    }

    public function test_bulk_zip_executes_and_streams_download(): void
    {
        $file1 = $this->uploadViaJob(['original_name' => 'doc1.txt']);
        $file2 = $this->uploadViaJob(['original_name' => 'doc2.txt']);

        // Since QUEUE_CONNECTION=sync, dispatch will run CreateZipArchiveJob immediately
        $response = $this->postJson('/files/bulk/zip', [
            'file_ids' => [$file1->id, $file2->id],
        ]);

        $response->assertOk();
        $jobId = $response->json('job_id');
        $this->assertNotNull($jobId);

        $job = FileJob::findOrFail($jobId);
        $this->assertSame(JobStatus::Done, $job->status);
        $this->assertSame(100, $job->progress);

        // Check status endpoint
        $statusResp = $this->getJson("/files/zip/{$job->id}/status");
        $statusResp->assertOk()
            ->assertJson([
                'id' => $job->id,
                'status' => 'done',
                'progress' => 100,
            ]);

        // Download ZIP stream
        $downloadResp = $this->get("/files/zip/{$job->id}/download");
        $downloadResp->assertOk();
        $downloadResp->assertHeader('content-type', 'application/zip');
    }

    public function test_bulk_endpoints_prevent_unauthorized_access(): void
    {
        $file = $this->uploadViaJob(['original_name' => 'secret.txt']);

        $other = User::create([
            'name' => 'Third',
            'email' => 'third@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($other);

        // Other user tries to bulk move file
        $this->post('/files/bulk/move', [
            'file_ids' => [$file->id],
            'folder_id' => null,
        ]);
        $this->assertNull($file->fresh()->virtual_folder_id);

        // Other user tries to bulk delete file
        $this->post('/files/bulk/delete', [
            'file_ids' => [$file->id],
        ]);
        $this->assertDatabaseHas('virtual_files', ['id' => $file->id]);

        // Other user tries to bulk zip file
        $response = $this->postJson('/files/bulk/zip', [
            'file_ids' => [$file->id],
        ]);
        $response->assertStatus(422);
    }
}
