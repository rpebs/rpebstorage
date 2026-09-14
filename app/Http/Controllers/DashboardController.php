<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\JobStatus;
use App\Jobs\SyncStorageQuotaJob;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\VirtualFile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $accounts = $user->storageAccounts()
            ->where('status', '!=', AccountStatus::Disconnected)
            ->with('provider')
            ->withCount('files')
            ->orderBy('created_at')
            ->get()
            ->reject(fn (StorageAccount $account) => $account->meta['connecting'] ?? false)
            ->values();

        $activeAccounts = $accounts->filter(fn (StorageAccount $a) => $a->status === AccountStatus::Active);

        // FR-21: refresh stale quota numbers in the background; the page
        // always renders with what we already have.
        $stale = $activeAccounts->contains(fn (StorageAccount $account) => $account->quota_synced_at === null
            || $account->quota_synced_at->lt(now()->subHour()));

        if ($stale && $activeAccounts->isNotEmpty()) {
            SyncStorageQuotaJob::dispatch();
        }

        $limited = $activeAccounts->filter(fn (StorageAccount $a) => ! $a->isUnlimited());

        $totalQuota = (int) $limited->sum('quota_total');
        $totalUsed = (int) $activeAccounts->sum('quota_used');
        $hasUnlimited = $activeAccounts->contains(fn (StorageAccount $a) => $a->isUnlimited());
        $freeQuota = $totalQuota > 0 ? max(0, $totalQuota - (int) $limited->sum('quota_used')) : 0;

        $fileCount = $user->virtualFiles()->count();
        $folderCount = $user->virtualFolders()->count();

        $activeJobsCount = FileJob::where('user_id', $user->id)
            ->whereIn('status', [JobStatus::Pending, JobStatus::Processing])
            ->count();

        // Breakdown per provider
        $providerBreakdown = $activeAccounts->groupBy('storage_provider_id')->map(function ($group) {
            /** @var StorageAccount $first */
            $first = $group->first();
            $provider = $first->provider;
            $hasUnlimited = $group->contains(fn (StorageAccount $a) => $a->isUnlimited());
            $limitedGroup = $group->filter(fn (StorageAccount $a) => ! $a->isUnlimited());
            $quotaTotal = (int) $limitedGroup->sum('quota_total');
            $quotaUsed = (int) $group->sum('quota_used');

            return [
                'provider_id' => $provider->id,
                'name' => $provider->name,
                'label' => $provider->label(),
                'account_count' => $group->count(),
                'quota_total' => $hasUnlimited && $quotaTotal === 0 ? null : $quotaTotal,
                'quota_used' => $quotaUsed,
                'is_unlimited' => $hasUnlimited,
                'percent' => $quotaTotal > 0 ? round(($quotaUsed / $quotaTotal) * 100, 1) : null,
                'files_count' => (int) $group->sum('files_count'),
            ];
        })->values();

        // 5 berkas terbaru
        $recentFiles = $user->virtualFiles()
            ->with(['account.provider', 'folder'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (VirtualFile $file) => [
                'id' => $file->id,
                'name' => $file->name,
                'size' => $file->size,
                'mime_type' => $file->mime_type,
                'folder_id' => $file->virtual_folder_id,
                'folder_name' => $file->folder?->name ?? 'Root',
                'account_alias' => $file->account?->alias ?? 'Akun tidak tersedia',
                'account_status' => $file->account?->status?->value ?? 'unknown',
                'provider_name' => $file->account?->provider?->name ?? 'unknown',
                'provider_label' => $file->account?->provider?->label() ?? 'Unknown',
                'is_accessible' => $file->isAccessible(),
                'created_at_human' => $file->created_at?->diffForHumans(),
                'created_at' => $file->created_at?->toIso8601String(),
            ]);

        $lastSyncedAt = $activeAccounts->pluck('quota_synced_at')->filter()->sortDesc()->first();

        return Inertia::render('dashboard', [
            'total' => [
                'quota' => $totalQuota,
                'used' => $totalUsed,
                'free' => $freeQuota,
                'has_unlimited' => $hasUnlimited,
                'account_count' => $activeAccounts->count(),
                'total_account_count' => $accounts->count(),
                'expired_account_count' => $accounts->where('status', AccountStatus::Expired)->count(),
                'file_count' => $fileCount,
                'folder_count' => $folderCount,
                'active_jobs_count' => $activeJobsCount,
                'last_synced_at_human' => $lastSyncedAt?->diffForHumans(),
            ],
            'providers' => $providerBreakdown,
            'accounts' => $accounts->map(fn (StorageAccount $account) => [
                'id' => $account->id,
                'alias' => $account->alias,
                'provider' => $account->provider->name,
                'provider_label' => $account->provider->label(),
                'status' => $account->status->value,
                'is_active' => $account->status === AccountStatus::Active,
                'is_unlimited' => $account->isUnlimited(),
                'quota_total' => $account->quota_total,
                'quota_used' => $account->quota_used,
                'remaining_quota' => $account->remainingQuota(),
                'used_percent' => $account->usedPercent(),
                'nearly_full' => $account->isNearlyFull(),
                'file_count' => $account->files_count ?? 0,
                'quota_synced_at_human' => $account->quota_synced_at?->diffForHumans(),
            ]),
            'recent_files' => $recentFiles,
        ]);
    }
}
