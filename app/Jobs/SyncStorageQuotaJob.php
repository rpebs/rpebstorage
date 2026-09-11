<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Models\StorageAccount;
use App\Services\Storage\OAuth\ProviderOAuth;
use App\Services\Storage\StorageManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FR-09 / FR-21: refresh tokens and pull fresh quota numbers from providers.
 */
class SyncStorageQuotaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 2;

    public function handle(StorageManager $manager, ProviderOAuth $oauth): void
    {
        StorageAccount::where('status', AccountStatus::Active)->with('provider')->chunkById(50, function ($accounts) use ($manager, $oauth) {
            foreach ($accounts as $account) {
                try {
                    if ($account->provider->name === 'telegram') {
                        continue; // driver computes used locally; total stays null
                    }

                    $usage = $manager->driver($account)->getQuotaUsage($account);

                    $account->forceFill([
                        'quota_total' => $usage->total,
                        'quota_used' => $usage->used,
                        'quota_synced_at' => now(),
                    ])->save();
                } catch (\Throwable $e) {
                    Log::warning('Sync kuota gagal', [
                        'account' => $account->id,
                        'provider' => $account->provider->name,
                        'error' => $e->getMessage(),
                    ]);

                    // Invalid/revoked tokens surface as auth failures.
                    if ($oauth->isAuthError($e)) {
                        $account->forceFill(['status' => AccountStatus::Expired])->save();
                    }
                }
            }
        });
    }
}
