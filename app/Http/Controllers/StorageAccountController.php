<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Services\Storage\OAuth\ProviderOAuth;
use App\Services\Telegram\TelegramRpc;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StorageAccountController extends Controller
{
    public function index(Request $request)
    {
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
            ]);

        $providers = StorageProvider::where('is_active', true)->orderBy('id')->get()
            ->map(fn (StorageProvider $provider) => [
                'name' => $provider->name,
                'label' => $provider->label(),
                'connectable' => in_array($provider->name, ['telegram', 'mega'], true) || ProviderOAuth::supported($provider->name),
                'credentials_missing' => ! in_array($provider->name, ['telegram', 'mega'], true) && ! ProviderOAuth::supported($provider->name),
            ]);

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
}
