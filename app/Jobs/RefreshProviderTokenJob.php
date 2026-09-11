<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Models\StorageAccount;
use App\Services\Storage\OAuth\ProviderOAuth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshProviderTokenJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 2;

    public function handle(ProviderOAuth $oauth): void
    {
        StorageAccount::where('status', AccountStatus::Active)
            ->whereHas('provider', fn ($query) => $query->where('name', '!=', 'telegram'))
            ->with('provider')
            ->chunkById(50, function ($accounts) use ($oauth) {
                foreach ($accounts as $account) {
                    try {
                        $oauth->ensureFresh($account, force: true);
                    } catch (\Throwable $e) {
                        Log::warning('Refresh token gagal', [
                            'account' => $account->id,
                            'provider' => $account->provider->name,
                            'error' => $e->getMessage(),
                        ]);

                        if ($oauth->isAuthError($e)) {
                            $account->forceFill(['status' => AccountStatus::Expired])->save();
                        }
                    }
                }
            });
    }
}
