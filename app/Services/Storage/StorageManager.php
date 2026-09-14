<?php

namespace App\Services\Storage;

use App\Contracts\StorageDriverInterface;
use App\Models\StorageAccount;
use App\Models\User;
use RuntimeException;

class StorageManager
{
    /**
     * Resolve the driver instance for an account.
     */
    public function driver(StorageAccount $account): StorageDriverInterface
    {
        return app($account->provider->driver_class);
    }

    /**
     * FR-11/FR-12: pick the upload target account. A manual pick wins when it
     * is valid; otherwise the quota-limited account with the most space free
     * is used. Unlimited accounts (Telegram) only serve as fallback when no
     * limited account has meaningful space left, so quota accounts get used
     * up before everything lands on Telegram.
     */
    public function pickBestAccount(User $user, ?int $preferredAccountId = null): StorageAccount
    {
        $accounts = $user->storageAccounts()
            ->where('status', 'active')
            ->where('is_active', true)
            ->join('storage_providers', 'storage_providers.id', '=', 'storage_accounts.storage_provider_id')
            ->where('storage_providers.is_active', true)
            ->select('storage_accounts.*')
            ->get();

        if ($preferredAccountId !== null) {
            $manual = $accounts->firstWhere('id', $preferredAccountId);

            if (! $manual) {
                throw new RuntimeException('Akun tujuan tidak valid atau tidak aktif.');
            }

            return $manual;
        }

        $limited = $accounts->filter(fn (StorageAccount $a) => ! $a->isUnlimited())
            ->sortByDesc(fn (StorageAccount $a) => $a->remainingQuota())
            ->values();

        if ($limited->isNotEmpty()) {
            if ($limited->first()->remainingQuota() >= config('rpebs.unlimited_fallback_threshold')) {
                return $limited->first();
            }
        }

        $unlimited = $accounts->first(fn (StorageAccount $a) => $a->isUnlimited());
        if ($unlimited) {
            return $unlimited;
        }

        if ($limited->isNotEmpty()) {
            return $limited->first();
        }

        throw new RuntimeException('Tidak ada akun storage yang aktif. Hubungkan minimal satu akun.');
    }

    public function tempPath(string $name): string
    {
        return storage_path('app/'.config('rpebs.temp_path').'/'.$name);
    }

    public function ensureTempDir(): void
    {
        $dir = $this->tempPath('');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
