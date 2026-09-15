<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\UploadFileJob;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\VirtualFile;
use App\Services\Storage\PreviewService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakeDriver;
use Tests\TestCase;

class PreviewTest extends TestCase
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

    private function createVirtualFile(string $name, string $content, string $mimeType): VirtualFile
    {
        $tempPath = app(StorageManager::class)->tempPath(uniqid('test_', true));
        app(StorageManager::class)->ensureTempDir();
        file_put_contents($tempPath, $content);

        $row = FileJob::create([
            'user_id' => $this->user->id,
            'type' => 'upload',
            'original_name' => $name,
            'size' => strlen($content),
            'mime_type' => $mimeType,
            'status' => JobStatus::Pending,
        ]);

        (new UploadFileJob($row->id, $tempPath, $this->account->id))
            ->handle(app(StorageManager::class));

        return VirtualFile::where('name', $name)->firstOrFail();
    }

    public function test_guests_cannot_access_preview(): void
    {
        $file = $this->createVirtualFile('doc.pdf', '%PDF-1.4 test', 'application/pdf');

        auth()->logout();

        $this->get("/files/{$file->id}/preview")->assertRedirect(route('login'));
        $this->get("/files/{$file->id}/preview-status")->assertRedirect(route('login'));
    }

    public function test_user_cannot_preview_other_users_file(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@test.local',
            'password' => bcrypt('secret'),
        ]);

        $file = $this->createVirtualFile('secret.pdf', '%PDF-1.4', 'application/pdf');

        $this->actingAs($otherUser);

        $this->get("/files/{$file->id}/preview")->assertStatus(404);
        $this->get("/files/{$file->id}/preview-status")->assertStatus(404);
    }

    public function test_preview_status_endpoint_returns_file_metadata(): void
    {
        $file = $this->createVirtualFile('movie.mp4', 'dummy video content', 'video/mp4');

        $response = $this->getJson("/files/{$file->id}/preview-status");

        $response->assertOk();
        $response->assertJson([
            'supported' => true,
            'type' => 'video',
            'name' => 'movie.mp4',
            'size' => strlen('dummy video content'),
        ]);
    }

    public function test_preview_serves_pdf_inline(): void
    {
        $content = '%PDF-1.4 test pdf file';
        $file = $this->createVirtualFile('manual.pdf', $content, 'application/pdf');

        $response = $this->get("/files/{$file->id}/preview");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition') ?? '');
        $this->assertStringContainsString('manual.pdf', $response->headers->get('Content-Disposition') ?? '');
        $this->assertSame($content, $response->streamedContent());
    }

    public function test_preview_serves_image_inline(): void
    {
        $content = 'fake png image data';
        $file = $this->createVirtualFile('photo.png', $content, 'image/png');

        $response = $this->get("/files/{$file->id}/preview");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_preview_serves_audio_inline(): void
    {
        $content = 'fake mp3 audio data';
        $file = $this->createVirtualFile('song.mp3', $content, 'audio/mpeg');

        $response = $this->get("/files/{$file->id}/preview");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_preview_supports_http_range_requests_for_video(): void
    {
        $content = '0123456789abcdefghijklmnopqrstuvwxyz';
        $file = $this->createVirtualFile('clip.mp4', $content, 'video/mp4');

        $response = $this->get("/files/{$file->id}/preview", [
            'Range' => 'bytes=0-9',
        ]);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 0-9/'.strlen($content));
        $this->assertSame('0123456789', $response->streamedContent());
    }

    public function test_preview_fails_if_account_is_disconnected(): void
    {
        $file = $this->createVirtualFile('test.pdf', '%PDF-1.4', 'application/pdf');

        $this->account->update(['status' => 'disconnected']);

        $this->get("/files/{$file->id}/preview")->assertStatus(403);
    }

    public function test_cache_is_cleaned_when_file_is_deleted(): void
    {
        $file = $this->createVirtualFile('cached.png', 'image data', 'image/png');

        // Trigger preview to populate cache
        $this->get("/files/{$file->id}/preview")->assertOk();

        $previewService = app(PreviewService::class);
        $cachePath = $previewService->getCachePath($file);
        $this->assertFileExists($cachePath);

        // Delete file
        $this->delete("/files/{$file->id}")->assertRedirect();

        $this->assertFileDoesNotExist($cachePath);
    }
}
