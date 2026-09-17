<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Settings\ProviderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\FakeDriver;
use Tests\TestCase;

class ProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Netralkan fallback .env milik mesin pengembang supaya status field
        // hanya ditentukan oleh tabel settings.
        foreach (ProviderSettings::keys() as $key) {
            config([ProviderSettings::configPath($key) => null]);
        }

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_settings_page_lists_every_provider_field(): void
    {
        $this->get(route('providers.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Providers')
                ->has('providers', 4)
                ->where('providers.0.name', 'google_drive')
                ->has('providers.0.fields', 2)
                ->where('providers.0.configured', false)
                ->where('providers.0.fields.0.source', ProviderSettings::SOURCE_UNSET)
                ->has('providers.3.fields', 2)
            );
    }

    public function test_guest_cannot_open_the_settings_page(): void
    {
        auth()->logout();

        $this->get(route('providers.edit'))->assertRedirect(route('login'));
    }

    public function test_credentials_are_saved_with_secrets_encrypted_at_rest(): void
    {
        $this->put(route('providers.update'), [
            'settings' => [
                ['key' => 'oauth.google_drive.client_id', 'value' => 'client-123'],
                ['key' => 'oauth.google_drive.client_secret', 'value' => 'rahasia-123'],
                ['key' => 'telegram.api_id', 'value' => '123456'],
                ['key' => 'telegram.api_hash', 'value' => 'abcdef0123456789abcdef0123456789'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Client ID bukan rahasia, disimpan apa adanya.
        $this->assertSame('client-123', Setting::query()->where('key', 'oauth.google_drive.client_id')->value('value'));

        // Secret terenkripsi dengan APP_KEY, bukan plaintext.
        $secret = (string) Setting::query()->where('key', 'oauth.google_drive.client_secret')->value('value');
        $this->assertNotSame('rahasia-123', $secret);
        $this->assertSame('rahasia-123', Crypt::decryptString($secret));

        // Nilai langsung dipakai di config tanpa perlu restart proses.
        $this->assertSame('client-123', config('rpebs.oauth.google_drive.client_id'));
        $this->assertSame('rahasia-123', config('rpebs.oauth.google_drive.client_secret'));
        $this->assertSame('123456', (string) config('rpebs.telegram.api_id'));
        $this->assertTrue(config('rpebs.telegram.dev_daemon'));
    }

    public function test_saved_credentials_are_reported_back_on_the_settings_page(): void
    {
        $settings = app(ProviderSettings::class);
        $settings->set([
            'oauth.dropbox.client_id' => 'db-app-key',
            'oauth.dropbox.client_secret' => 'db-app-secret',
        ]);
        $settings->flushCache();

        $this->get(route('providers.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providers.1.name', 'dropbox')
                ->where('providers.1.configured', true)
                ->where('providers.1.redirect_uri', rtrim(config('app.url'), '/').'/accounts/callback/dropbox')
                ->where('providers.1.fields.0.value', 'db-app-key')
                ->where('providers.1.fields.0.source', ProviderSettings::SOURCE_APP)
                ->where('providers.1.fields.1.value', 'db-app-secret')
            );
    }

    public function test_empty_value_clears_the_override_and_env_is_used_again(): void
    {
        config(['rpebs.oauth.dropbox.client_id' => 'kunci-dari-env']);

        $settings = app(ProviderSettings::class);
        $this->assertSame(ProviderSettings::SOURCE_ENV, $settings->source('oauth.dropbox.client_id'));
        $this->assertSame('kunci-dari-env', $settings->get('oauth.dropbox.client_id'));

        $settings->set(['oauth.dropbox.client_id' => 'kunci-dari-aplikasi']);
        $this->assertSame(ProviderSettings::SOURCE_APP, $settings->source('oauth.dropbox.client_id'));
        $this->assertSame('kunci-dari-aplikasi', $settings->get('oauth.dropbox.client_id'));

        $settings->set(['oauth.dropbox.client_id' => '']);
        $settings->flushCache();

        $this->assertSame(ProviderSettings::SOURCE_ENV, $settings->source('oauth.dropbox.client_id'));
        $this->assertSame('kunci-dari-env', $settings->get('oauth.dropbox.client_id'));
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_unknown_keys_are_ignored(): void
    {
        app(ProviderSettings::class)->set([
            'app.debug' => 'true',
            'rpebs.quota_warning_percent' => '1',
            'oauth.google_drive.client_id' => 'hanya-ini-yang-disimpan',
        ]);

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame(1, Setting::query()->where('key', 'oauth.google_drive.client_id')->count());
    }

    public function test_value_over_the_length_limit_is_rejected(): void
    {
        $this->put(route('providers.update'), [
            'settings' => [
                ['key' => 'oauth.dropbox.client_secret', 'value' => str_repeat('a', 501)],
            ],
        ])->assertSessionHasErrors('settings.0.value');

        $this->assertSame(0, Setting::query()->count());
    }

    public function test_saving_telegram_credentials_unlocks_the_accounts_page(): void
    {
        StorageProvider::create([
            'name' => 'telegram',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);
        StorageProvider::create([
            'name' => 'google_drive',
            'driver_class' => FakeDriver::class,
            'is_active' => true,
        ]);

        $this->get('/accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providers.0.connectable', false)
                ->where('providers.0.credentials_missing', true)
            );

        app(ProviderSettings::class)->set([
            'telegram.api_id' => '123456',
            'telegram.api_hash' => 'abcdef0123456789abcdef0123456789',
        ]);

        $this->get('/accounts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providers.0.name', 'telegram')
                ->where('providers.0.connectable', true)
                ->where('providers.0.credentials_missing', false)
            );
    }

    public function test_env_names_map_to_setting_keys_for_restoring_older_archives(): void
    {
        $this->assertSame('oauth.google_drive.client_id', ProviderSettings::keyForEnvName('GOOGLE_CLIENT_ID'));
        $this->assertSame('oauth.dropbox.client_secret', ProviderSettings::keyForEnvName('DROPBOX_CLIENT_SECRET'));
        $this->assertSame('oauth.onedrive.client_id', ProviderSettings::keyForEnvName('MICROSOFT_CLIENT_ID'));
        $this->assertSame('telegram.api_hash', ProviderSettings::keyForEnvName('TELEGRAM_API_HASH'));
        $this->assertNull(ProviderSettings::keyForEnvName('RESEND_API_KEY'));

        $this->assertContains('settings', BackupService::TABLES);
    }
}