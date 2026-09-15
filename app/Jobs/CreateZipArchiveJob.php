<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\VirtualFile;
use App\Services\Storage\StorageManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

class CreateZipArchiveJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    /**
     * @param  array<int>  $fileIds
     */
    public function __construct(
        public int $fileJobId,
        public array $fileIds,
    ) {}

    public function handle(StorageManager $manager): void
    {
        $job = FileJob::find($this->fileJobId);
        if (! $job) {
            return;
        }

        $job->update([
            'status' => JobStatus::Processing,
            'progress' => 5,
        ]);

        $files = VirtualFile::where('user_id', $job->user_id)
            ->whereIn('id', $this->fileIds)
            ->with('account')
            ->get();

        $manager->ensureTempDir();
        $zipPath = self::zipPath($manager, $job);

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $job->update([
                'status' => JobStatus::Failed,
                'error' => 'Gagal membuat berkas arsip ZIP.',
            ]);

            return;
        }

        $total = $files->count();
        $added = 0;
        $usedNames = [];
        $tempsToClean = [];

        try {
            foreach ($files as $index => $file) {
                if (! $file->account || $file->account->status !== AccountStatus::Active) {
                    continue;
                }

                $sourcePath = null;
                if ($file->is_chunked) {
                    $sourcePath = DownloadFileJob::mergedPath($manager, $file);
                    if (! file_exists($sourcePath)) {
                        $raw = $manager->driver($file->account)->download($file->account, $file->remote_ref);
                        rename($raw, $sourcePath);
                    }
                } else {
                    $sourcePath = $manager->driver($file->account)->download($file->account, $file->remote_ref);
                    $tempsToClean[] = $sourcePath;
                }

                if (file_exists($sourcePath)) {
                    $name = $file->name;
                    if (isset($usedNames[$name])) {
                        $usedNames[$name]++;
                        $pi = pathinfo($name);
                        $ext = isset($pi['extension']) && $pi['extension'] !== '' ? '.'.$pi['extension'] : '';
                        $entryName = $pi['filename'].' ('.$usedNames[$name].')'.$ext;
                    } else {
                        $usedNames[$name] = 0;
                        $entryName = $name;
                    }

                    $zip->addFile($sourcePath, $entryName);
                    $added++;
                }

                $progress = (int) round((($index + 1) / max(1, $total)) * 90);
                $job->update(['progress' => min(95, max(10, $progress))]);
            }

            $zip->close();

            foreach ($tempsToClean as $temp) {
                if (file_exists($temp)) {
                    @unlink($temp);
                }
            }

            if ($added === 0) {
                if (file_exists($zipPath)) {
                    @unlink($zipPath);
                }
                $job->update([
                    'status' => JobStatus::Failed,
                    'error' => 'Tidak ada berkas yang dapat diunduh ke dalam ZIP.',
                ]);

                return;
            }

            $job->update([
                'status' => JobStatus::Done,
                'progress' => 100,
                'size' => file_exists($zipPath) ? filesize($zipPath) : 0,
            ]);
        } catch (Throwable $e) {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
            foreach ($tempsToClean as $temp) {
                if (file_exists($temp)) {
                    @unlink($temp);
                }
            }
            $job->update([
                'status' => JobStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public static function zipPath(StorageManager $manager, FileJob $job): string
    {
        return $manager->tempPath('bulk_zip_'.$job->id.'_'.md5((string) $job->created_at).'.zip');
    }

    public function failed(Throwable $e): void
    {
        Log::error('Pembuatan arsip ZIP gagal', [
            'file_job_id' => $this->fileJobId,
            'error' => $e->getMessage(),
        ]);
    }
}
