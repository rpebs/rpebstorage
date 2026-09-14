<?php

namespace Tests;

use App\Contracts\StorageDriverInterface;
use App\Models\StorageAccount;
use App\Values\QuotaUsage;
use App\Values\UploadResult;

/**
 * In-memory driver for feature tests: same contract as the real drivers,
 * no network. Registered as a provider's driver_class.
 */
class FakeDriver implements StorageDriverInterface
{
    /** @var array<string, string> remote ref => file content */
    public static array $storage = [];

    public static ?int $total = null;

    public static int $used = 0;

    public function upload(StorageAccount $account, string $localFilePath, string $fileName): UploadResult
    {
        $ref = 'fake-'.uniqid();
        self::$storage[$ref] = (string) file_get_contents($localFilePath);

        return new UploadResult($ref, (int) filesize($localFilePath));
    }

    public function download(StorageAccount $account, string $remoteRef): string
    {
        $dest = tempnam(sys_get_temp_dir(), 'fakedriver');
        file_put_contents($dest, self::$storage[$remoteRef] ?? '');

        return $dest;
    }

    public function delete(StorageAccount $account, string $remoteRef): bool
    {
        unset(self::$storage[$remoteRef]);

        return true;
    }

    public function getRemainingQuota(StorageAccount $account): ?int
    {
        return self::$total === null ? null : max(0, self::$total - self::$used);
    }

    public function getQuotaUsage(StorageAccount $account): QuotaUsage
    {
        return new QuotaUsage(self::$total, self::$used);
    }

    public static function reset(): void
    {
        self::$storage = [];
        self::$total = null;
        self::$used = 0;
    }
}
