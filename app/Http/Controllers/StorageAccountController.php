<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\JobStatus;
use App\Jobs\ScanStorageAccountJob;
use App\Models\FileJob;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Services\Settings\ProviderSettings;
use App\Services\Telegram\TelegramRpc;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StorageAccountController extends Controller
{
    public function index(Request $request)
    {
        $settings = app(ProviderSettings::class);
        $activeScanAccounts = FileJob::where('user_id', $request->user()->id)
            ->where('type', 'scan')
            ->whereIn('status', [JobStatus::Pending->value, JobStatus::Processing->value])
            ->pluck('storage_account_id')
            ->all();

        $accounts = $request->user()->storageAccounts()
            ->where('status', '!=', AccountStatus::Disconnected)
            ->with('provider')
            ->orderBy('created_at')
            ->get()
            // Hide Telegram rows whose OTP wizard never finished.
            ->reject(fn (StorageAccount $account) => $account->meta['connecting'] ?? false)
            ->values()
            ->map(fn (StorageAccount $account) => [
                'id' => $account->id,
                'alias' => $account->alias,
                'provider' => $account->provider->name,
                'provider_label' => $account->provider->label(),
                'status' => $account->status->value,
                'quota_total' => $account->quota_total,
                'quota_used' => $account->quota_used,
                'file_count' => $account->files()->count(),
                'supports_scan' => $account->provider->name !== 'telegram',
                'is_scanning' => in_array($account->id, $activeScanAccounts, true),
            ]);

        $providers = StorageProvider::where('is_active', true)->orderBy('id')->get()
            ->map(function (StorageProvider $provider) use ($settings): array {
                // MEGA login memakai kredensial akun (email + password), bukan kredensial aplikasi.
                $configured = $provider->name === 'mega' || $settings->configured($provider->name);

                return [
                    'name' => $provider->name,
                    'label' => $provider->label(),
                    'connectable' => $configured,
                    'credentials_missing' => ! $configured,
                ];
            });

        return Inertia::render('accounts/index', [
            'accounts' => $accounts,
            'providers' => $providers,
        ]);
    }

    public function update(Request $request, StorageAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:100'],
        ]);

        $account->update(['alias' => $validated['alias']]);

        return $this->toast([
            'type' => 'success',
            'message' => 'Alias akun diperbarui.',
        ]);
    }

    public function destroy(Request $request, StorageAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 404);

        // FR-08: drop the stored tokens/session, mark files as inaccessible.
        if ($account->provider->name === 'telegram') {
            // Release the daemon's hold on the session first (it locks the dir),
            // then delete the session dir as a fallback for when it's not running.
            try {
                app(TelegramRpc::class)->call('disconnect', ['account_id' => $account->id], timeout: 30);
            } catch (\Throwable $e) {
                report($e);
            }

            $session = storage_path('app/'.config('rpebs.telegram.session_path').'/'.$account->id.'.madeline');

            if (is_dir($session)) {
                app(Filesystem::class)->deleteDirectory($session);
            }
        }

        $account->forceFill([
            'credentials' => null,
            'status' => AccountStatus::Disconnected,
        ])->save();

        return $this->toast([
            'type' => 'success',
            'message' => "Akun {$account->alias} diputuskan. File yang tersimpan di akun ini tidak bisa diakses lagi.",
        ]);
    }

    public function scan(Request $request, StorageAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 404);

        if ($account->status !== AccountStatus::Active) {
            return $this->toast([
                'type' => 'error',
                'message' => 'Hanya akun aktif yang dapat dipindai.',
            ]);
        }

        if ($account->provider->name === 'telegram') {
            return $this->toast([
                'type' => 'error',
                'message' => 'Provider Telegram tidak mendukung pemindaian berkas luar.',
            ]);
        }

        $existing = FileJob::where('user_id', $request->user()->id)
            ->where('storage_account_id', $account->id)
            ->where('type', 'scan')
            ->whereIn('status', [JobStatus::Pending->value, JobStatus::Processing->value])
            ->first();

        if ($existing) {
            return $this->toast([
                'type' => 'info',
                'message' => 'Pemindaian untuk akun ini sedang berjalan.',
            ]);
        }

        $job = FileJob::create([
            'user_id' => $account->user_id,
            'type' => 'scan',
            'virtual_folder_id' => null,
            'storage_account_id' => $account->id,
            'original_name' => "Pindai {$account->alias}",
            'size' => 0,
            'mime_type' => null,
            'status' => JobStatus::Pending,
            'progress' => 0,
        ]);

        ScanStorageAccountJob::dispatch($account->id, $job->id);

        return $this->toast([
            'type' => 'success',
            'message' => "Pemindaian berkas {$account->alias} dimulai di latar belakang.",
        ]);
    }
}
