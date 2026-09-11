<?php

namespace App\Services\Storage\Drivers;

use App\Contracts\StorageDriverInterface;
use App\Models\StorageAccount;
use App\Models\FileChunk;
use App\Models\VirtualFile;
use App\Services\Storage\StorageManager;
use App\Services\Telegram\TelegramRpc;
use App\Services\Telegram\TelegramRpcException;
use App\Values\QuotaUsage;
use App\Values\UploadResult;

class TelegramDriver implements StorageDriverInterface
{
    public function __construct(
        private TelegramRpc $rpc,
        private StorageManager $manager,
    ) {}

    /**
     * @throws TelegramRpcException
     */
    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult
    {
        $result = $this->rpc->call('upload', [
            'account_id' => $account->id,
            'path' => $localFilePath,
            'name' => $fileName,
            'size' => filesize($localFilePath),
        ]);

        $size = filesize($localFilePath);

        return new UploadResult((string) $result['remote_ref'], $size, $result['chunks'] ?? null);
    }

    /**
     * @throws TelegramRpcException
     */
    public function download(StorageAccount $account, string $remoteRef): string
    {
        $this->manager->ensureTempDir();
        $dest = $this->manager->tempPath(uniqid('tg_', true));

        $chunks = FileChunk::whereHas('file', function ($query) use ($account, $remoteRef) {
            $query->where('storage_account_id', $account->id)->where('remote_ref', $remoteRef);
        })
            ->orderBy('chunk_index')
            ->get()
            ->map(fn (FileChunk $chunk) => [
                'remote_file_id' => $chunk->remote_file_id,
                'size' => $chunk->size,
                'checksum' => $chunk->checksum,
            ])
            ->all();

        $this->rpc->call('download', [
            'account_id' => $account->id,
            'remote_ref' => $remoteRef,
            'dest' => $dest,
            'chunks' => $chunks,
        ], timeout: 1800);

        return $dest;
    }

    /**
     * @throws TelegramRpcException
     */
    public function delete(StorageAccount $account, string $remoteRef): bool
    {
        $chunks = FileChunk::whereHas('file', function ($query) use ($account, $remoteRef) {
            $query->where('storage_account_id', $account->id)->where('remote_ref', $remoteRef);
        })
            ->get()
            ->map(fn (FileChunk $chunk) => ['remote_file_id' => $chunk->remote_file_id])
            ->all();

        $this->rpc->call('delete', [
            'account_id' => $account->id,
            'remote_ref' => $remoteRef,
            'chunks' => $chunks,
        ]);

        return true;
    }

    public function getRemainingQuota(StorageAccount $account): ?int
    {
        return null;
    }

    public function getQuotaUsage(StorageAccount $account): QuotaUsage
    {
        // Telegram reports no quota; used is derived from the files we track.
        $used = (int) VirtualFile::where('storage_account_id', $account->id)->sum('size');

        return new QuotaUsage(null, $used);
    }
}
