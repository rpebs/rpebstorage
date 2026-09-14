<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FakeDriver;
use Tests\TestCase;

class LogTest extends TestCase
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

    public function test_guests_cannot_access_logs(): void
    {
        auth()->logout();

        $this->get('/logs')->assertRedirect(route('login'));
    }

    public function test_logs_page_renders_with_jobs_and_counts(): void
    {
        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'done-file.png',
            'size' => 1024,
            'status' => JobStatus::Done,
            'progress' => 100,
        ]);

        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'failed-file.zip',
            'size' => 2048,
            'status' => JobStatus::Failed,
            'error' => 'Storage out of memory',
        ]);

        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'active-file.mp4',
            'size' => 4096,
            'status' => JobStatus::Processing,
            'progress' => 45,
        ]);

        $this->get('/logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('logs/index')
                ->has('logs.data', 3)
                ->where('counts.all', 3)
                ->where('counts.done', 1)
                ->where('counts.failed', 1)
                ->where('counts.active', 1)
            );
    }

    public function test_logs_can_be_filtered_by_status(): void
    {
        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'done-file.png',
            'size' => 1024,
            'status' => JobStatus::Done,
        ]);

        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'failed-file.zip',
            'size' => 2048,
            'status' => JobStatus::Failed,
        ]);

        $this->get('/logs?status=done')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('logs/index')
                ->has('logs.data', 1)
                ->where('logs.data.0.name', 'done-file.png')
            );

        $this->get('/logs?status=failed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('logs/index')
                ->has('logs.data', 1)
                ->where('logs.data.0.name', 'failed-file.zip')
            );
    }

    public function test_logs_can_be_searched_by_filename(): void
    {
        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'archive_2026.zip',
            'size' => 1024,
            'status' => JobStatus::Done,
        ]);

        FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'invoice_september.pdf',
            'size' => 2048,
            'status' => JobStatus::Done,
        ]);

        $this->get('/logs?q=archive')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('logs/index')
                ->has('logs.data', 1)
                ->where('logs.data.0.name', 'archive_2026.zip')
            );
    }

    public function test_individual_log_can_be_deleted(): void
    {
        $job = FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'delete-me.txt',
            'size' => 500,
            'status' => JobStatus::Done,
        ]);

        $this->delete("/logs/{$job->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('file_jobs', ['id' => $job->id]);
    }

    public function test_logs_clear_endpoint_removes_only_finished_jobs(): void
    {
        $done = FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'done.txt',
            'size' => 100,
            'status' => JobStatus::Done,
        ]);

        $failed = FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'failed.txt',
            'size' => 100,
            'status' => JobStatus::Failed,
        ]);

        $processing = FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => 'active.txt',
            'size' => 100,
            'status' => JobStatus::Processing,
        ]);

        $this->delete('/logs')->assertRedirect();

        $this->assertDatabaseMissing('file_jobs', ['id' => $done->id]);
        $this->assertDatabaseMissing('file_jobs', ['id' => $failed->id]);
        $this->assertDatabaseHas('file_jobs', ['id' => $processing->id]);
    }

    public function test_user_cannot_delete_other_users_log(): void
    {
        $other = User::create([
            'name' => 'Other',
            'email' => 'other@test.local',
            'password' => bcrypt('secret'),
        ]);

        $job = FileJob::create([
            'user_id' => $other->id,
            'type' => 'upload',
            'original_name' => 'secret.txt',
            'size' => 500,
            'status' => JobStatus::Done,
        ]);

        $this->delete("/logs/{$job->id}")->assertNotFound();
    }
}
