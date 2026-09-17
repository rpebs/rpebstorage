<?php

namespace App\Providers;

use App\Services\Settings\ProviderSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Menyalin kredensial provider dari tabel settings ke config sebelum provider
 * lain boot, jadi driver, daemon, dan controller tetap cukup membaca config().
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderSettings::class);
    }

    public function boot(): void
    {
        $this->app->make(ProviderSettings::class)->applyToConfig();
    }
}
