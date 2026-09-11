<?php

namespace App\Jobs;

use App\Models\VirtualFile;
use App\Services\Storage\StorageManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DownloadFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [120];

    public int $timeout = 7200;

    public function __construct(public int $fileId) {}

    public function handle(StorageManager $manager): void
    {
        $file = VirtualFile::with('account')->findOrFail($this->fileId);

        $mergedPath = self::mergedPath($manager, $file);

        if (file_exists($mergedPath)) {
            return;
        }

        $manager->ensureTempDir();
        $raw = $manager->driver($file->account)->download($file->account, $file->remote_ref);

        rename($raw, $mergedPath);
    }

    public static function mergedPath(StorageManager $manager, VirtualFile $file): string
    {
        // Updated-at in the key invalidates stale merges after a re-upload.
        return $manager->tempPath('merged_'.$file->id.'_'.md5((string) $file->updated_at));
    }

    public function failed(Throwable $e): void
    {
        Log::error('Download prepare gagal', ['file' => $this->fileId, 'error' => $e->getMessage()]);
    }
}
