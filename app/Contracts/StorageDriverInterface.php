<?php

namespace App\Contracts;

use App\Models\StorageAccount;
use App\Values\QuotaUsage;
use App\Values\UploadResult;

interface StorageDriverInterface
{
    /**
     * Upload a local file to the account's remote storage.
     * The local file is guaranteed to exist; the caller removes it afterwards.
     */
    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult;

    /**
     * Materialize the remote file to a local temp path and return the path.
     */
    public function download(StorageAccount $account, string $remoteRef): string;

    public function delete(StorageAccount $account, string $remoteRef): bool;

    /**
     * Remaining quota in bytes. Null means the provider does not report a quota
     * (e.g. Telegram), which the system treats as unlimited.
     */
    public function getRemainingQuota(StorageAccount $account): ?int;

    /**
     * Current total/used quota at the provider, used by the quota sync job.
     */
    public function getQuotaUsage(StorageAccount $account): QuotaUsage;
}
