<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Kredensial provider (OAuth client + Telegram API) disimpan di tabel settings,
 * sehingga instalasi baru tidak perlu menyentuh .env. Nilai .env tetap dibaca
 * sebagai fallback untuk instalasi lama: kalau keduanya terisi, database menang.
 */
class ProviderSettings
{
    public const TABLE = 'settings';

    public const SOURCE_APP = 'app';

    public const SOURCE_ENV = 'env';

    public const SOURCE_UNSET = 'unset';

    /**
     * Field kredensial per provider. Kunci field = path config tanpa prefix
     * `rpebs.`, jadi pemetaannya ke config() cukup menambah prefix tersebut.
     *
     * @var array<string, array{
     *     label: string,
     *     console_url: string,
     *     console_note: string,
     *     oauth: bool,
     *     fields: array<string, array{label: string, env: string, secret: bool, hint: string, placeholder: string}>
     * }>
     */
    public const PROVIDERS = [
        'google_drive' => [
            'label' => 'Google Drive',
            'console_url' => 'https://console.cloud.google.com/apis/credentials',
            'console_note' => 'Buat OAuth client ID bertipe Web application, lalu tempel Redirect URI di bawah ke kolom Authorized redirect URIs.',
            'oauth' => true,
            'fields' => [
                'oauth.google_drive.client_id' => [
                    'label' => 'Client ID',
                    'env' => 'GOOGLE_CLIENT_ID',
                    'secret' => false,
                    'hint' => 'API & Services → Credentials → OAuth client ID.',
                    'placeholder' => '1234567890-xxxxxxxx.apps.googleusercontent.com',
                ],
                'oauth.google_drive.client_secret' => [
                    'label' => 'Client Secret',
                    'env' => 'GOOGLE_CLIENT_SECRET',
                    'secret' => true,
                    'hint' => 'Ditampilkan saat client ID dibuat; bisa dibuat ulang dari console.',
                    'placeholder' => 'GOCSPX-...',
                ],
            ],
        ],
        'dropbox' => [
            'label' => 'Dropbox',
            'console_url' => 'https://www.dropbox.com/developers/apps',
            'console_note' => 'Pilih aplikasi bertipe Scoped access, lalu isi Redirect URI di bawah pada tab OAuth 2.',
            'oauth' => true,
            'fields' => [
                'oauth.dropbox.client_id' => [
                    'label' => 'App Key',
                    'env' => 'DROPBOX_CLIENT_ID',
                    'secret' => false,
                    'hint' => 'Ditampilkan di halaman aplikasi Dropbox sebagai App key.',
                    'placeholder' => 'abcdefghijklmnop',
                ],
                'oauth.dropbox.client_secret' => [
                    'label' => 'App Secret',
                    'env' => 'DROPBOX_CLIENT_SECRET',
                    'secret' => true,
                    'hint' => 'Tombol Show di samping App secret.',
                    'placeholder' => 'App secret dari Dropbox',
                ],
            ],
        ],
        'onedrive' => [
            'label' => 'OneDrive',
            'console_url' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
            'console_note' => 'Daftarkan aplikasi di Microsoft Entra, tambahkan Redirect URI bertipe Web dengan nilai di bawah, lalu buat client secret.',
            'oauth' => true,
            'fields' => [
                'oauth.onedrive.client_id' => [
                    'label' => 'Application (client) ID',
                    'env' => 'MICROSOFT_CLIENT_ID',
                    'secret' => false,
                    'hint' => 'Halaman Overview aplikasi di Microsoft Entra.',
                    'placeholder' => '00000000-0000-0000-0000-000000000000',
                ],
                'oauth.onedrive.client_secret' => [
                    'label' => 'Client Secret',
                    'env' => 'MICROSOFT_CLIENT_SECRET',
                    'secret' => true,
                    'hint' => 'Certificates & secrets → Client secrets → New client secret.',
                    'placeholder' => 'Nilai kolom Value dari client secret',
                ],
            ],
        ],
        'telegram' => [
            'label' => 'Telegram',
            'console_url' => 'https://my.telegram.org/apps',
            'console_note' => 'Ambil dari API development tools. Setelah terisi, daemon telegram:listen dijalankan otomatis oleh start.bat atau composer dev.',
            'oauth' => false,
            'fields' => [
                'telegram.api_id' => [
                    'label' => 'App api_id',
                    'env' => 'TELEGRAM_API_ID',
                    'secret' => false,
                    'hint' => 'Angka di halaman API development tools.',
                    'placeholder' => '12345678',
                ],
                'telegram.api_hash' => [
                    'label' => 'App api_hash',
                    'env' => 'TELEGRAM_API_HASH',
                    'secret' => true,
                    'hint' => '32 karakter heksadesimal di halaman yang sama.',
                    'placeholder' => '0123456789abcdef0123456789abcdef',
                ],
            ],
        ],
    ];

    /**
     * Cache per instance supaya tidak query berulang dalam satu request.
     *
     * @var array<string, string>|null
     */
    private ?array $cache = null;

    /**
     * Nilai config asli (dari .env atau config default) sebelum ditimpa
     * pengaturan aplikasi, supaya bisa dikembalikan saat pengaturan dihapus.
     *
     * @var array<string, string|null>
     */
    private array $baseline = [];

    /**
     * Nilai kosong atau bukan string diperlakukan sebagai tidak terisi (null).
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Semua kunci pengaturan kredensial yang dikenal.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::PROVIDERS as $definition) {
            foreach (array_keys($definition['fields']) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function configPath(string $key): string
    {
        return 'rpebs.'.$key;
    }

    public static function isSecret(string $key): bool
    {
        foreach (self::PROVIDERS as $definition) {
            if (isset($definition['fields'][$key])) {
                return $definition['fields'][$key]['secret'];
            }
        }

        return false;
    }

    public function flushCache(): void
    {
        $this->cache = null;
    }

    /**
     * Nilai efektif sebuah field: pengaturan aplikasi dulu, lalu .env/config.
     */
    public function get(string $key): ?string
    {
        $stored = $this->stored()[$key] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fromConfig = config(self::configPath($key));

        return $this->stringOrNull($fromConfig);
    }

    /**
     * Asal nilai yang sedang dipakai: 'app', 'env', atau 'unset'.
     */
    public function source(string $key): string
    {
        $stored = $this->stored()[$key] ?? null;

        if (is_string($stored) && $stored !== '') {
            return self::SOURCE_APP;
        }

        return $this->get($key) === null ? self::SOURCE_UNSET : self::SOURCE_ENV;
    }

    /**
     * True bila semua field provider sudah terisi dari mana pun asalnya.
     */
    public function configured(string $provider): bool
    {
        if (! isset(self::PROVIDERS[$provider])) {
            return false;
        }

        foreach (array_keys(self::PROVIDERS[$provider]['fields']) as $key) {
            if ($this->get($key) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kunci pengaturan untuk nama variabel .env, mis. 'GOOGLE_CLIENT_ID'.
     * Dipakai saat restore arsip lama yang kredensialnya masih di manifest env.
     */
    public static function keyForEnvName(string $envName): ?string
    {
        foreach (self::PROVIDERS as $definition) {
            foreach ($definition['fields'] as $key => $field) {
                if ($field['env'] === $envName) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Salin nilai dari tabel settings ke config, supaya seluruh pemakai
     * (driver, daemon, controller) tetap cukup membaca config().
     */
    public function applyToConfig(): void
    {
        $stored = $this->stored();

        foreach (self::keys() as $key) {
            $value = $stored[$key] ?? null;

            if (is_string($value) && $value !== '') {
                if (! array_key_exists($key, $this->baseline)) {
                    $this->baseline[$key] = $this->stringOrNull(config(self::configPath($key)));
                }

                config([self::configPath($key) => $value]);

                continue;
            }

            // Pengaturan dihapus: kembalikan nilai asli .env/config.
            if (array_key_exists($key, $this->baseline)) {
                config([self::configPath($key) => $this->baseline[$key]]);
            }
        }

        if (config('rpebs.telegram.dev_daemon') === null) {
            config(['rpebs.telegram.dev_daemon' => $this->configured('telegram')]);
        }
    }

    /**
     * Simpan nilai dari form Settings. Kunci yang tidak dikenal diabaikan agar
     * request tidak bisa menulis config sembarangan; nilai kosong menghapus
     * override sehingga .env kembali dipakai.
     *
     * @param  array<string, string|null>  $values
     * @return array<int, string> Kunci yang benar-benar berubah
     */
    public function set(array $values): array
    {
        $allowed = self::keys();
        $changed = [];

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true) || ! is_string($value)) {
                continue;
            }

            $value = trim($value);
            $existing = Setting::query()->where('key', $key)->first();
            $current = $existing instanceof Setting ? $this->decode($key, (string) $existing->value) : null;

            if ($value === '') {
                if ($existing instanceof Setting) {
                    $existing->delete();
                    $changed[] = $key;
                }

                continue;
            }

            if ($current === $value) {
                continue;
            }

            if ($existing instanceof Setting) {
                $existing->update(['value' => $this->encode($key, $value)]);
            } else {
                Setting::query()->create(['key' => $key, 'value' => $this->encode($key, $value)]);
            }

            $changed[] = $key;
        }

        $this->cache = null;
        $this->applyToConfig();

        return $changed;
    }

    /**
     * Isi tabel settings setelah didekripsi. Kunci di luar daftar kredensial
     * diabaikan, dan kegagalan baca (mis. tabel belum ada) menghasilkan array kosong.
     *
     * @return array<string, string>
     */
    public function stored(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $values = [];

        if ($this->tableExists()) {
            foreach (Setting::query()->get() as $row) {
                $key = (string) $row->key;

                if (! in_array($key, self::keys(), true)) {
                    continue;
                }

                $value = $this->decode($key, (string) $row->value);

                if ($value !== null && $value !== '') {
                    $values[$key] = $value;
                }
            }
        }

        return $this->cache = $values;
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            // Belum ada koneksi/tabel (mis. instalasi baru) — anggap kosong.
            return false;
        }
    }

    private function encode(string $key, string $value): string
    {
        return self::isSecret($key) ? Crypt::encryptString($value) : $value;
    }

    private function decode(string $key, string $value): ?string
    {
        if (! self::isSecret($key)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            Log::warning("Nilai pengaturan rahasia tidak bisa dibaca, APP_KEY diduga berubah: {$key}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Data untuk halaman Settings: field per provider, status keterisian, dan
     * asal nilainya (aplikasi atau .env) supaya UI tidak menampilkan klaim palsu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function providersForUi(): array
    {
        $providers = [];

        foreach (self::PROVIDERS as $name => $definition) {
            $fields = [];

            foreach ($definition['fields'] as $key => $field) {
                $fields[] = [
                    'key' => $key,
                    'label' => $field['label'],
                    'secret' => $field['secret'],
                    'value' => $this->get($key) ?? '',
                    'env' => $field['env'],
                    'source' => $this->source($key),
                    'hint' => $field['hint'],
                    'placeholder' => $field['placeholder'],
                ];
            }

            $providers[] = [
                'name' => $name,
                'label' => $definition['label'],
                'configured' => $this->configured($name),
                'oauth' => $definition['oauth'],
                'redirect_uri' => $this->redirectUri($name),
                'console_url' => $definition['console_url'],
                'console_note' => $definition['console_note'],
                'fields' => $fields,
            ];
        }

        return $providers;
    }

    private function redirectUri(string $provider): ?string
    {
        if (! self::PROVIDERS[$provider]['oauth']) {
            return null;
        }

        $path = config('rpebs.oauth.'.$provider.'.redirect');

        return is_string($path) ? rtrim((string) config('app.url'), '/').$path : null;
    }
}