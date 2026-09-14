<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use App\Services\Storage\StorageManager;
use App\Services\Storage\ThumbnailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public int $fileJobRowId,
        public string $tempPath,
        public ?int $preferredAccountId,
    ) {}

    public function handle(StorageManager $manager, ?ThumbnailService $thumbnailService = null): void
    {
        $thumbnailService ??= app(ThumbnailService::class);
        $row = FileJob::findOrFail($this->fileJobRowId);
        $row->update(['status' => JobStatus::Processing, 'progress' => 5]);

        try {
            $account = $this->preferredAccountId
                ? StorageAccount::where('user_id', $row->user_id)->findOrFail($this->preferredAccountId)
                : $manager->pickBestAccount($row->user);

            $row->update(['storage_account_id' => $account->id]);

            $driver = $manager->driver($account);
            $result = $driver->upload($account, $this->tempPath, $row->original_name);

            $createdFile = null;
            DB::transaction(function () use ($row, $account, $result, &$createdFile) {
                $createdFile = VirtualFile::create([
                    'user_id' => $row->user_id,
                    'virtual_folder_id' => $row->virtual_folder_id,
                    'storage_account_id' => $account->id,
                    'name' => $row->original_name,
                    'remote_ref' => $result->remoteRef,
                    'size' => $result->size,
                    'mime_type' => $row->mime_type,
                    'is_chunked' => $result->isChunked(),
                ]);

                if ($result->isChunked()) {
                    $createdFile->chunks()->createMany($result->chunks);
                }

                $account->increment('quota_used', $result->size);
            });

            if ($createdFile && $thumbnailService->supports($createdFile)) {
                try {
                    $thumbnailService->generateThumbnail($createdFile, $this->tempPath);
                } catch (Throwable $th) {
                    Log::warning('Gagal membuat thumbnail saat upload', [
                        'file_id' => $createdFile->id,
                        'name' => $createdFile->name,
                        'error' => $th->getMessage(),
                    ]);
                }
            }

            $row->update(['status' => JobStatus::Done, 'progress' => 100]);
        } catch (Throwable $e) {
            Log::error('Upload gagal', [
                'file_job' => $row->id,
                'name' => $row->original_name,
                'error' => $e->getMessage(),
            ]);

            $row->update([
                'status' => JobStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            @unlink($this->tempPath);
        }
    }

    public function failed(Throwable $e): void
    {
        @unlink($this->tempPath);

        FileJob::whereKey($this->fileJobRowId)
            ->whereNot('status', JobStatus::Done)
            ->update(['status' => JobStatus::Failed, 'error' => $e->getMessage()]);
    }
}
