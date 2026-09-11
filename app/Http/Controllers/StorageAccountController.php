<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Services\Storage\OAuth\ProviderOAuth;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StorageAccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = $request->user()->storageAccounts()
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
                'connectable' => $provider->name === 'telegram' || ProviderOAuth::supported($provider->name),
                'credentials_missing' => $provider->name !== 'telegram' && ! ProviderOAuth::supported($provider->name),
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

        return back()->with('flash', ['toast' => [
            'type' => 'success',
            'message' => 'Alias akun diperbarui.',
        ]]);
    }

    public function destroy(Request $request, StorageAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 404);

        // FR-08: drop the stored tokens/session, mark files as inaccessible.
        $account->forceFill([
            'credentials' => null,
            'status' => AccountStatus::Disconnected,
        ])->save();

        return back()->with('flash', ['toast' => [
            'type' => 'success',
            'message' => "Akun {$account->alias} diputuskan. File yang tersimpan di akun ini tidak bisa diakses lagi.",
        ]]);
    }
}
