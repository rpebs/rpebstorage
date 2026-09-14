<?php

namespace Tests\Unit;

use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Storage\Drivers\DropboxDriver;
use App\Services\Storage\OAuth\ProviderOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DropboxDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_quota_usage_sends_empty_body_and_parses_response(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/2/users/get_space_usage' => Http::response([
                'used' => 314159265,
                'allocation' => [
                    '.tag' => 'individual',
                    'allocated' => 10000000000,
                ],
            ], 200),
        ]);

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
        ]);

        $provider = StorageProvider::create([
            'name' => 'dropbox',
            'driver_class' => DropboxDriver::class,
            'is_active' => true,
        ]);

        $account = StorageAccount::create([
            'user_id' => $user->id,
            'storage_provider_id' => $provider->id,
            'alias' => 'Dropbox Test',
            'credentials' => [
                'access_token' => 'fake-dropbox-token',
                'refresh_token' => 'fake-refresh-token',
                'expires_at' => time() + 3600,
            ],
            'status' => 'active',
        ]);

        $driver = app(DropboxDriver::class);
        $usage = $driver->getQuotaUsage($account);

        $this->assertSame(10000000000, $usage->total);
        $this->assertSame(314159265, $usage->used);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dropboxapi.com/2/users/get_space_usage'
                && $request->method() === 'POST'
                && $request->body() === '';
        });
    }

    public function test_download_sends_empty_body(): void
    {
        Http::fake([
            'https://content.dropboxapi.com/2/files/download' => Http::response('file-contents-here', 200),
        ]);

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
        ]);

        $provider = StorageProvider::create([
            'name' => 'dropbox',
            'driver_class' => DropboxDriver::class,
            'is_active' => true,
        ]);

        $account = StorageAccount::create([
            'user_id' => $user->id,
            'storage_provider_id' => $provider->id,
            'alias' => 'Dropbox Test',
            'credentials' => [
                'access_token' => 'fake-dropbox-token',
                'refresh_token' => 'fake-refresh-token',
                'expires_at' => time() + 3600,
            ],
            'status' => 'active',
        ]);

        $driver = app(DropboxDriver::class);
        $dest = $driver->download($account, '/remote/path.txt');

        $this->assertFileExists($dest);
        $this->assertSame('file-contents-here', file_get_contents($dest));
        @unlink($dest);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://content.dropboxapi.com/2/files/download'
                && $request->method() === 'POST'
                && $request->body() === ''
                && $request->hasHeader('Dropbox-API-Arg');
        });
    }

    public function test_oauth_fetch_email_sends_empty_body_for_dropbox(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/2/users/get_current_account' => Http::response([
                'account_id' => 'dbid:AAH4f-D_WhAhduFeAno7xAAA',
                'email' => 'user@dropbox.local',
            ], 200),
        ]);

        $oauth = new ProviderOAuth;
        $email = $oauth->fetchEmail('dropbox', 'fake-token');

        $this->assertSame('user@dropbox.local', $email);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.dropboxapi.com/2/users/get_current_account'
                && $request->method() === 'POST'
                && $request->body() === '';
        });
    }
}
