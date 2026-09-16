<?php

namespace App\Providers;

use App\Services\Storage\Drivers\MegaDriver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureSslCertificates();
        $this->configureDefaults();
        $this->configureDevCommands();
    }

    /**
     * Ensure SSL CA bundle environment variables are populated for cURL and OpenSSL.
     */
    protected function configureSslCertificates(): void
    {
        $caBundle = MegaDriver::resolveCaBundlePath();
        if ($caBundle !== null) {
            putenv("SSL_CERT_FILE={$caBundle}");
            putenv("CURL_CA_BUNDLE={$caBundle}");
            $_ENV['SSL_CERT_FILE'] = $caBundle;
            $_ENV['CURL_CA_BUNDLE'] = $caBundle;
            $_SERVER['SSL_CERT_FILE'] = $caBundle;
            $_SERVER['CURL_CA_BUNDLE'] = $caBundle;
        }
    }

    /**
     * Configure local development process runner.
     * On Windows, Horizon requires pcntl/posix which is unsupported; replace it with queue:listen.
     */
    protected function configureDevCommands(): void
    {
        if (class_exists(DevCommands::class)) {
            if (PHP_OS_FAMILY === 'Windows') {
                DevCommands::except('horizon');
                DevCommands::artisan('queue:listen --tries=1 --timeout=0', 'queue');
            }

            if (config('rpebs.telegram.dev_daemon')) {
                DevCommands::artisan('telegram:listen', 'telegram');
            }
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
