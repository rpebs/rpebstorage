<?php

namespace App\Services\Storage\Drivers;

use App\Models\StorageAccount;
use App\Values\QuotaUsage;
use App\Values\UploadResult;

/**
 * Placeholder that keeps the driver registry complete. The real MTProto
 * implementation (MadelineProto daemon) lands in the Telegram phase.
 */
class TelegramDriver implements \App\Contracts\StorageDriverInterface
{
    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult
    {
        throw new \RuntimeException('Driver Telegram belum aktif.');
    }

    public function download(StorageAccount $account, string $remoteRef): string
    {
        throw new \RuntimeException('Driver Telegram belum aktif.');
    }

    public function delete(StorageAccount $account, string $remoteRef): bool
    {
        throw new \RuntimeException('Driver Telegram belum aktif.');
    }

    public function getRemainingQuota(StorageAccount $account): ?int
    {
        return null;
    }

    public function getQuotaUsage(StorageAccount $account): QuotaUsage
    {
        return new QuotaUsage(null, 0);
    }
}
