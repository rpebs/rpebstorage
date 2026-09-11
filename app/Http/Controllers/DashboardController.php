<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Jobs\SyncStorageQuotaJob;
use App\Models\StorageAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $accounts = $user->storageAccounts()
            ->where('status', AccountStatus::Active)
            ->with('provider')
            ->orderBy('created_at')
            ->get();

        // FR-21: refresh stale quota numbers in the background; the page
        // always renders with what we already have.
        $stale = $accounts->contains(fn (StorageAccount $account) => $account->quota_synced_at === null
            || $account->quota_synced_at->lt(now()->subHour()));

        if ($stale && $accounts->isNotEmpty()) {
            SyncStorageQuotaJob::dispatch();
        }

        $limited = $accounts->filter(fn (StorageAccount $a) => ! $a->isUnlimited());

        $totalQuota = (int) $limited->sum('quota_total');
        $totalUsed = (int) $accounts->sum('quota_used');

        return Inertia::render('dashboard', [
            'total' => [
                'quota' => $totalQuota,
                'used' => $totalUsed,
                'account_count' => $accounts->count(),
                'file_count' => $user->virtualFiles()->count(),
            ],
            'accounts' => $accounts->map(fn (StorageAccount $account) => [
                'id' => $account->id,
                'alias' => $account->alias,
                'provider_label' => $account->provider->label(),
                'quota_total' => $account->quota_total,
                'quota_used' => $account->quota_used,
                'nearly_full' => $account->isNearlyFull(),
            ]),
        ]);
    }
}
