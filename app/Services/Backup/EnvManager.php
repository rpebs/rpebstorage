<?php

namespace App\Services\Backup;

class EnvManager
{
    /**
     * Critical environment variables related to application encryption and OAuth providers.
     * APP_KEY selalu dari .env; kredensial provider boleh berasal dari tabel
     * settings karena ProviderSettings sudah menyalinnya ke config saat boot.
     *
     * @var array<int, string>
     */
    public const CRITICAL_KEYS = [
        'APP_KEY',
        'TELEGRAM_API_ID',
        'TELEGRAM_API_HASH',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'DROPBOX_CLIENT_ID',
        'DROPBOX_CLIENT_SECRET',
        'MICROSOFT_CLIENT_ID',
        'MICROSOFT_CLIENT_SECRET',
    ];

    public function __construct(
        private ?string $envFilePath = null
    ) {
        $this->envFilePath = $envFilePath ?? base_path('.env');
    }

    /**
     * Read the critical environment key-value pairs from .env or config.
     *
     * @return array<string, string|null>
     */
    public function getCriticalValues(): array
    {
        $configMap = [
            'APP_KEY' => config('app.key'),
            'TELEGRAM_API_ID' => config('rpebs.telegram.api_id'),
            'TELEGRAM_API_HASH' => config('rpebs.telegram.api_hash'),
            'GOOGLE_CLIENT_ID' => config('rpebs.oauth.google_drive.client_id'),
            'GOOGLE_CLIENT_SECRET' => config('rpebs.oauth.google_drive.client_secret'),
            'DROPBOX_CLIENT_ID' => config('rpebs.oauth.dropbox.client_id'),
            'DROPBOX_CLIENT_SECRET' => config('rpebs.oauth.dropbox.client_secret'),
            'MICROSOFT_CLIENT_ID' => config('rpebs.oauth.onedrive.client_id'),
            'MICROSOFT_CLIENT_SECRET' => config('rpebs.oauth.onedrive.client_secret'),
        ];

        $rawEnv = $this->parseEnvFile();
        $values = [];

        foreach (self::CRITICAL_KEYS as $key) {
            $val = $configMap[$key] ?? ($rawEnv[$key] ?? null);
            $values[$key] = $val !== null && $val !== '' ? (string) $val : null;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvFile(): array
    {
        if (! file_exists($this->envFilePath)) {
            return [];
        }

        $lines = file($this->envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * Update environment variables in the .env file.
     * Creates a timestamped backup before updating.
     *
     * @param  array<string, string|null>  $values
     */
    public function update(array $values): void
    {
        if (! file_exists($this->envFilePath)) {
            return;
        }

        $originalMtime = @filemtime($this->envFilePath);
        $content = file_get_contents($this->envFilePath);
        if ($content === false) {
            return;
        }

        $backupPath = $this->envFilePath.'.backup.'.date('Y-m-d_His');
        @file_put_contents($backupPath, $content);

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $escapedValue = $this->formatValue((string) $value);
            $pattern = "/^{$key}=.*/m";

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "{$key}={$escapedValue}", $content);
            } else {
                $content = rtrim($content)."\n{$key}={$escapedValue}\n";
            }

            // Sync with current runtime process
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        file_put_contents($this->envFilePath, $content);

        // Preserve original filemtime to prevent `artisan serve` from killing the running HTTP worker mid-request
        if ($originalMtime) {
            @touch($this->envFilePath, $originalMtime);
        }

        if (isset($values['APP_KEY'])) {
            config(['app.key' => $values['APP_KEY']]);
        }
    }

    private function formatValue(string $value): string
    {
        if (str_contains($value, ' ') || str_contains($value, '#') || str_contains($value, '"') || str_contains($value, "'")) {
            return '"'.addcslashes($value, '"\\').'"';
        }

        return $value;
    }
}
