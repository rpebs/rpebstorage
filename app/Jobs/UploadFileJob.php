<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use App\Services\Storage\StorageManager;
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

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 7200;

    public function __construct(
        public int $fileJobRowId,
        public string $tempPath,
        public ?int $preferredAccountId,
    ) {}

    public function handle(StorageManager $manager): void
    {
        $row = FileJob::findOrFail($this->fileJobRowId);
        $row->update(['status' => JobStatus::Processing, 'progress' => 5]);

        try {
            $account = $this->preferredAccountId
                ? StorageAccount::where('user_id', $row->user_id)->findOrFail($this->preferredAccountId)
                : $manager->pickBestAccount($row->user);

            $row->update(['storage_account_id' => $account->id]);

            $driver = $manager->driver($account);
            $result = $driver->upload($account, $this->tempPath, $row->original_name);

            DB::transaction(function () use ($row, $account, $result) {
                $file = VirtualFile::create([
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
                    $file->chunks()->createMany($result->chunks);
                }

                $account->increment('quota_used', $result->size);
            });

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
        FileJob::whereKey($this->fileJobRowId)
            ->whereNot('status', JobStatus::Done)
            ->update(['status' => JobStatus::Failed, 'error' => $e->getMessage()]);
    }
}
