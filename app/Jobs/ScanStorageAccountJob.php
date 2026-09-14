<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use App\Models\VirtualFolder;
use App\Services\Storage\StorageManager;
use App\Values\RemoteItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanStorageAccountJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $accountId,
        public ?int $fileJobId = null,
    ) {}

    public function handle(StorageManager $manager): void
    {
        $account = StorageAccount::with('provider', 'user')->findOrFail($this->accountId);

        $job = $this->fileJobId ? FileJob::find($this->fileJobId) : null;
        $job?->update(['status' => JobStatus::Processing, 'progress' => 10]);

        try {
            $driver = $manager->driver($account);
            $items = $driver->listFiles($account);

            $rootFolderName = "[{$account->provider->label()} - {$account->alias}]";
            $rootAccountFolder = VirtualFolder::firstOrCreate([
                'user_id' => $account->user_id,
                'parent_id' => null,
                'name' => $rootFolderName,
            ]);

            $job?->update(['progress' => 30]);

            /** @var array<string, RemoteItem> */
            $remoteFolders = [];
            /** @var array<RemoteItem> */
            $remoteFiles = [];

            foreach ($items as $item) {
                if ($item->isFolder) {
                    $remoteFolders[$item->id] = $item;
                } else {
                    $remoteFiles[] = $item;
                }
            }

            $job?->update(['progress' => 50]);

            /** @var array<string, int> */
            $folderMap = [];
            $folderMap[''] = $rootAccountFolder->id;
            $folderMap['/'] = $rootAccountFolder->id;

            $resolveFolderByParentId = function (
                ?string $remoteFolderId,
                callable $self
            ) use (
                &$folderMap,
                $remoteFolders,
                $rootAccountFolder,
                $account
            ): int {
                if (empty($remoteFolderId)) {
                    return $rootAccountFolder->id;
                }

                if (isset($folderMap[$remoteFolderId])) {
                    return $folderMap[$remoteFolderId];
                }

                $folderItem = $remoteFolders[$remoteFolderId] ?? null;
                if (! $folderItem) {
                    return $rootAccountFolder->id;
                }

                $parentId = $self($folderItem->parentId, $self);

                $virtualFolder = VirtualFolder::firstOrCreate([
                    'user_id' => $account->user_id,
                    'parent_id' => $parentId,
                    'name' => $folderItem->name,
                ]);

                return $folderMap[$remoteFolderId] = $virtualFolder->id;
            };

            foreach ($remoteFolders as $folderItem) {
                if ($folderItem->remotePath !== null) {
                    $this->resolvePathFolder($folderItem->remotePath, $rootAccountFolder->id, $account->user_id, $folderMap);
                } else {
                    $resolveFolderByParentId($folderItem->id, $resolveFolderByParentId);
                }
            }

            $job?->update(['progress' => 70]);

            DB::transaction(function () use ($remoteFiles, $account, $rootAccountFolder, $folderMap, $resolveFolderByParentId) {
                foreach ($remoteFiles as $file) {
                    $targetFolderId = $rootAccountFolder->id;

                    if ($file->remotePath !== null) {
                        $dir = trim(str_replace('\\', '/', dirname($file->remotePath)), '/');
                        if ($dir !== '' && $dir !== '.') {
                            $targetFolderId = $this->resolvePathFolder('/'.$dir, $rootAccountFolder->id, $account->user_id, $folderMap);
                        }
                    } elseif (! empty($file->parentId)) {
                        $targetFolderId = $resolveFolderByParentId($file->parentId, $resolveFolderByParentId);
                    }

                    $existing = VirtualFile::where('storage_account_id', $account->id)
                        ->where('remote_ref', $file->id)
                        ->first();

                    if ($existing) {
                        $existing->update([
                            'name' => $file->name,
                            'size' => $file->size ?? $existing->size,
                            'mime_type' => $file->mimeType ?: ($existing->mime_type ?: 'application/octet-stream'),
                        ]);
                    } else {
                        VirtualFile::create([
                            'user_id' => $account->user_id,
                            'virtual_folder_id' => $targetFolderId,
                            'storage_account_id' => $account->id,
                            'name' => $file->name,
                            'remote_ref' => $file->id,
                            'size' => $file->size ?? 0,
                            'mime_type' => $file->mimeType ?: 'application/octet-stream',
                            'is_chunked' => false,
                        ]);
                    }
                }
            });

            try {
                $quota = $driver->getQuotaUsage($account);
                $account->update([
                    'quota_total' => $quota->total,
                    'quota_used' => $quota->used,
                    'quota_synced_at' => now(),
                ]);
            } catch (Throwable $e) {
                Log::warning("Gagal sinkronisasi kuota setelah scan akun {$account->id}: {$e->getMessage()}");
            }

            $job?->update([
                'status' => JobStatus::Done,
                'progress' => 100,
            ]);
        } catch (Throwable $e) {
            Log::error("Scan akun {$account->id} gagal: {$e->getMessage()}", ['exception' => $e]);
            $job?->update([
                'status' => JobStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function resolvePathFolder(string $path, int $rootFolderId, int $userId, array &$folderMap): int
    {
        $normalized = '/'.trim(str_replace('\\', '/', $path), '/');
        if ($normalized === '/' || $normalized === '') {
            return $rootFolderId;
        }

        if (isset($folderMap[$normalized])) {
            return $folderMap[$normalized];
        }

        $segments = explode('/', trim($normalized, '/'));
        $currentParentId = $rootFolderId;
        $currentPath = '';

        foreach ($segments as $segment) {
            $currentPath .= '/'.$segment;
            if (isset($folderMap[$currentPath])) {
                $currentParentId = $folderMap[$currentPath];

                continue;
            }

            $folder = VirtualFolder::firstOrCreate([
                'user_id' => $userId,
                'parent_id' => $currentParentId,
                'name' => $segment,
            ]);

            $folderMap[$currentPath] = $folder->id;
            $currentParentId = $folder->id;
        }

        return $folderMap[$normalized] = $currentParentId;
    }
}
