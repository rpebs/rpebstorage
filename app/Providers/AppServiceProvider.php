<?php

namespace App\Providers;

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
        $this->configureDefaults();
        $this->configureDevCommands();
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
