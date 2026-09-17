<?php

namespace App\Services\Storage\OAuth;

use App\Models\StorageAccount;
use App\Services\Settings\ProviderSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2.0 (authorization code + refresh) for the three cloud providers.
 * Dropbox, Google and Microsoft all use the same simple shape, so one class
 * with a per-provider config map covers them without an OAuth library.
 */
class ProviderOAuth
{
    private const PROVIDERS = [
        'google_drive' => [
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/drive',
            'auth_extra' => ['access_type' => 'offline', 'prompt' => 'consent'],
        ],
        'dropbox' => [
            'auth_url' => 'https://www.dropbox.com/oauth2/authorize',
            'token_url' => 'https://api.dropboxapi.com/oauth2/token',
            'scope' => 'account_info.read files.metadata.read files.metadata.write files.content.read files.content.write',
            'auth_extra' => ['token_access_type' => 'offline'],
        ],
        'onedrive' => [
            'auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => 'offline_access files.readwrite.all',
            'auth_extra' => [],
        ],
    ];

    public static function supported(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]) && app(ProviderSettings::class)->configured($provider);
    }

    public static function providerNames(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * @throws ConnectionException
     */
    public function authorizeUrl(string $provider, string $state): string
    {
        $cfg = $this->config($provider);
        $oauth = self::PROVIDERS[$provider];

        $params = array_merge([
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'state' => $state,
        ], $oauth['auth_extra']);

        if ($oauth['scope']) {
            $params['scope'] = $oauth['scope'];
        }

        return $oauth['auth_url'].'?'.http_build_query($params);
    }

    /**
     * Exchange an authorization code for the initial credentials payload.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}
     *
     * @throws ConnectionException
     */
    public function exchangeCode(string $provider, string $code): array
    {
        $cfg = $this->config($provider);

        $response = Http::asForm()->post(self::PROVIDERS[$provider]['token_url'], [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $this->redirectUri($provider),
        ])->throw()->json();

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($response['expires_in'] ?? 3600),
        ];
    }

    /**
     * Refresh the account's access token in place when it is expired or
     * about to expire. Preserves the stored refresh token if the provider
     * does not return a new one.
     *
     * @throws ConnectionException
     */
    public function ensureFresh(StorageAccount $account, bool $force = false): void
    {
        $credentials = $account->credentials;

        if (! is_array($credentials)) {
            return;
        }

        if (! $force && ($credentials['expires_at'] ?? 0) > time() + 60) {
            return;
        }

        $account->credentials = $this->refresh($account->provider->name, $credentials);
        $account->save();
    }

    /**
     * True when an exception looks like a revoked/expired token instead of a
     * transient network or server failure.
     */
    public function isAuthError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return (int) ($e->getCode() ?? 0) === 401
            || str_contains($message, 'invalid_grant')
            || str_contains($message, 'invalid_client')
            || str_contains($message, 'unauthorized_client')
            || str_contains($message, 'invalid_token')
            || str_contains($message, 'expired_token')
            || str_contains($message, '401 unauthorized')
            || str_contains($message, 'refresh token is invalid');
    }

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_at: int}  $credentials
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}
     *
     * @throws ConnectionException
     */
    public function refresh(string $provider, array $credentials): array
    {
        $cfg = $this->config($provider);

        $response = Http::asForm()->post(self::PROVIDERS[$provider]['token_url'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials['refresh_token'],
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
        ])->throw()->json();

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $credentials['refresh_token'],
            'expires_at' => time() + (int) ($response['expires_in'] ?? 3600),
        ];
    }

    /**
     * Basic profile email of the connected account, used as the default alias.
     */
    public function fetchEmail(string $provider, string $accessToken): ?string
    {
        try {
            $response = match ($provider) {
                'google_drive' => Http::withToken($accessToken)
                    ->get('https://www.googleapis.com/oauth2/v3/userinfo'),
                'dropbox' => Http::withToken($accessToken)
                    ->send('POST', 'https://api.dropboxapi.com/2/users/get_current_account'),
                'onedrive' => Http::withToken($accessToken)
                    ->get('https://graph.microsoft.com/v1.0/me'),
                default => null,
            };
        } catch (ConnectionException) {
            return null;
        }

        if (! $response || ! $response->successful()) {
            return null;
        }

        return $response->json('email') ?? $response->json('mail') ?? $response->json('userPrincipalName');
    }

    private function redirectUri(string $provider): string
    {
        return rtrim(config('app.url'), '/').config('rpebs.oauth.'.$provider.'.redirect');
    }

    private function config(string $provider): array
    {
        $cfg = config('rpebs.oauth.'.$provider);

        if (! $cfg || ! $cfg['client_id'] || ! $cfg['client_secret']) {
            throw new \RuntimeException("Kredensial OAuth {$provider} belum diisi. Buka Settings → Provider lalu simpan Client ID dan Client Secret.");
        }

        return $cfg;
    }
}
