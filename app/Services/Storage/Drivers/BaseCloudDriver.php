<?php

namespace App\Services\Storage\Drivers;

use App\Contracts\StorageDriverInterface;
use App\Models\StorageAccount;
use App\Services\Storage\OAuth\ProviderOAuth;
use App\Services\Storage\StorageManager;

/**
 * Shared plumbing for the three OAuth-based cloud drivers.
 */
abstract class BaseCloudDriver implements StorageDriverInterface
{
    public function __construct(
        protected ProviderOAuth $oauth,
        protected StorageManager $manager,
    ) {}

    public function getRemainingQuota(StorageAccount $account): ?int
    {
        $usage = $this->getQuotaUsage($account);

        return $usage->total === null ? null : max(0, $usage->total - $usage->used);
    }

    protected function token(StorageAccount $account): string
    {
        $this->oauth->ensureFresh($account);

        return $account->credentials['access_token'];
    }

    protected function newTempPath(): string
    {
        $this->manager->ensureTempDir();

        return $this->manager->tempPath(uniqid('dl_', true));
    }

    protected function mimeOf(string $localFilePath): string
    {
        return mime_content_type($localFilePath) ?: 'application/octet-stream';
    }
}
