<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Services\Storage\OAuth\ProviderOAuth;
use App\Services\Storage\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProviderOAuthController extends Controller
{
    public function __construct(
        private ProviderOAuth $oauth,
        private StorageManager $manager,
    ) {}

    public function redirect(Request $request, StorageProvider $provider)
    {
        if (! ProviderOAuth::supported($provider->name)) {
            return $this->toastRoute('providers.edit', [
                'type' => 'error',
                'message' => "Kredensial OAuth {$provider->label()} belum lengkap. Isi di Settings → Provider.",
            ]);
        }

        $state = bin2hex(random_bytes(16));
        $request->session()->put('oauth_state', $state);
        $request->session()->put('oauth_provider', $provider->name);

        return redirect()->away($this->oauth->authorizeUrl($provider->name, $state));
    }

    public function callback(Request $request, StorageProvider $provider)
    {
        $expectedState = $request->session()->pull('oauth_state');
        $expectedProvider = $request->session()->pull('oauth_provider');

        if ($request->input('state') !== $expectedState || $expectedProvider !== $provider->name) {
            return $this->toastRoute('accounts.index', [
                'type' => 'error',
                'message' => 'Sesi OAuth tidak valid. Coba hubungkan lagi.',
            ]);
        }

        if ($request->filled('error')) {
            return $this->toastRoute('accounts.index', [
                'type' => 'error',
                'message' => 'Koneksi dibatalkan di sisi provider.',
            ]);
        }

        try {
            $credentials = $this->oauth->exchangeCode($provider->name, (string) $request->input('code'));
            $email = $this->oauth->fetchEmail($provider->name, $credentials['access_token']);

            $account = DB::transaction(function () use ($request, $provider, $credentials, $email) {
                $account = StorageAccount::create([
                    'user_id' => $request->user()->id,
                    'storage_provider_id' => $provider->id,
                    'alias' => $provider->label().($email ? ' ('.$email.')' : ''),
                    'credentials' => $credentials,
                    'status' => 'active',
                ]);

                $usage = $this->manager->driver($account)->getQuotaUsage($account);

                $account->forceFill([
                    'quota_total' => $usage->total,
                    'quota_used' => $usage->used,
                    'quota_synced_at' => now(),
                ])->save();

                return $account;
            });

            return $this->toastRoute('accounts.index', [
                'type' => 'success',
                'message' => "Akun {$provider->label()} terhubung.",
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->toastRoute('accounts.index', [
                'type' => 'error',
                'message' => 'Gagal menghubungkan akun: '.$e->getMessage(),
            ]);
        }
    }
}
