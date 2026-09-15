<?php

namespace App\Services\Backup;

use App\Exceptions\BackupException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class BackupService
{
    /**
     * Tables included in the backup, in forward dependency order.
     *
     * @var array<int, string>
     */
    public const TABLES = [
        'users',
        'passkeys',
        'storage_providers',
        'storage_accounts',
        'virtual_folders',
        'virtual_files',
        'file_chunks',
        'labels',
        'labelables',
    ];

    public function __construct(
        private Filesystem $files,
        private EnvManager $envManager
    ) {}

    /**
     * Get system statistics for backup overview.
     *
     * @return array<string, mixed>
     */
    public function getSystemStats(): array
    {
        $stats = [];

        foreach (self::TABLES as $table) {
            try {
                $stats[$table.'_count'] = DB::table($table)->count();
            } catch (\Throwable) {
                $stats[$table.'_count'] = 0;
            }
        }

        $sessionDir = $this->telegramSessionDir();
        $sessionCount = 0;

        if (is_dir($sessionDir)) {
            $dirs = glob($sessionDir.'/*.madeline');
            $sessionCount = is_array($dirs) ? count($dirs) : 0;
        }

        $stats['telegram_sessions_count'] = $sessionCount;
        $stats['app_key_configured'] = ! empty(config('app.key'));

        return $stats;
    }

    /**
     * Create an encrypted backup archive (.zip) with master password protection.
     *
     * @throws BackupException
     */
    public function createBackup(string $password, ?string $outputPath = null): string
    {
        if (strlen($password) < 8) {
            throw new BackupException('Password pengaman backup minimal 8 karakter.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new BackupException('Ekstensi PHP ZipArchive tidak terpasang di sistem.');
        }

        $this->cleanOldTempBackups();

        $tempDir = storage_path('app/'.config('rpebs.temp_path', 'temp').'/backup_'.uniqid());
        $this->files->makeDirectory($tempDir, 0755, true, true);

        $outputPath = $outputPath ?? storage_path(
            'app/'.config('rpebs.temp_path', 'temp').'/rpebstorage-backup-'.date('Y-m-d-His').'.zip'
        );

        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir)) {
            $this->files->makeDirectory($outputDir, 0755, true, true);
        }

        try {
            // 1. Export database tables to array
            $databaseData = [];
            $tableCounts = [];

            foreach (self::TABLES as $table) {
                try {
                    $rows = DB::table($table)->get()->map(function ($row) {
                        return (array) $row;
                    })->toArray();

                    $databaseData[$table] = $rows;
                    $tableCounts[$table] = count($rows);
                } catch (\Throwable) {
                    $databaseData[$table] = [];
                    $tableCounts[$table] = 0;
                }
            }

            // 2. Collect Telegram sessions
            $sessionDir = $this->telegramSessionDir();
            $telegramFiles = [];

            if (is_dir($sessionDir)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sessionDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $filename = $file->getFilename();
                        if (str_ends_with($filename, '.lock') || $filename === 'lock') {
                            continue;
                        }

                        $realPath = $file->getRealPath();
                        $relativePath = ltrim(substr($realPath, strlen($sessionDir)), '/\\');
                        $telegramFiles[$relativePath] = $realPath;
                    }
                }
            }

            // 3. Prepare manifest
            $envValues = $this->envManager->getCriticalValues();

            $manifest = [
                'format_version' => '1.0',
                'version' => 1,
                'created_at' => now()->toISOString(),
                'app_name' => config('app.name', 'rpebstorage'),
                'app_key' => config('app.key'),
                'database_driver' => config('database.default'),
                'table_counts' => $tableCounts,
                'telegram_sessions_count' => count($telegramFiles),
                'env' => $envValues,
            ];

            // 4. Create encrypted ZipArchive
            $zip = new ZipArchive;
            $openResult = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($openResult !== true) {
                throw new BackupException("Gagal membuat arsip ZIP (Kode: {$openResult}).");
            }

            $zip->setPassword($password);

            // Add manifest
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $zip->addFromString('manifest.json', (string) $manifestJson);
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);

            // Add database dump
            $dbJson = json_encode($databaseData, JSON_UNESCAPED_SLASHES);
            $zip->addFromString('database.json', (string) $dbJson);
            $zip->setEncryptionName('database.json', ZipArchive::EM_AES_256);

            // Add telegram session files
            foreach ($telegramFiles as $relative => $fullPath) {
                $content = file_get_contents($fullPath);
                if ($content !== false) {
                    $entryName = 'telegram-sessions/'.str_replace('\\', '/', $relative);
                    $zip->addFromString($entryName, $content);
                    $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256);
                }
            }

            $zip->close();

            return $outputPath;
        } finally {
            if ($this->files->isDirectory($tempDir)) {
                $this->files->deleteDirectory($tempDir);
            }
        }
    }

    public function cleanOldTempBackups(): void
    {
        $tempDir = storage_path('app/'.config('rpebs.temp_path', 'temp'));
        if (! is_dir($tempDir)) {
            return;
        }

        $files = glob($tempDir.'/rpebstorage-backup-*.zip');
        if (! is_array($files)) {
            return;
        }

        $oneHourAgo = time() - 3600;
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $oneHourAgo) {
                @unlink($file);
            }
        }
    }

    /**
     * Verify backup integrity and password without restoring.
     *
     * @return array<string, mixed> The parsed manifest
     *
     * @throws BackupException
     */
    public function verifyBackup(string $backupPath, string $password): array
    {
        if (! file_exists($backupPath)) {
            throw new BackupException('Berkas arsip backup tidak ditemukan.');
        }

        if (filesize($backupPath) === 0) {
            throw new BackupException('Berkas arsip backup kosong (0 bytes). Pastikan berkas terunduh sempurna sebelum memulihkan.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new BackupException('Ekstensi PHP ZipArchive tidak terpasang di sistem.');
        }

        $zip = new ZipArchive;
        $res = $zip->open($backupPath);

        if ($res !== true) {
            throw new BackupException("Berkas backup tidak dapat dibuka (Kode error: {$res}).");
        }

        $zip->setPassword($password);

        $manifestContent = @$zip->getFromName('manifest.json');
        $zip->close();

        if ($manifestContent === false || $manifestContent === '') {
            throw new BackupException('Password salah atau berkas backup rusak.');
        }

        $manifest = json_decode($manifestContent, true);

        if (! is_array($manifest) || ! isset($manifest['version'])) {
            throw new BackupException('Struktur metadata arsip backup tidak valid.');
        }

        return $manifest;
    }

    /**
     * Restore system from an encrypted backup archive.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> Summary of restored resources
     *
     * @throws BackupException
     */
    public function restoreBackup(string $backupPath, string $password, array $options = []): array
    {
        // 1. Verify credentials and manifest
        $manifest = $this->verifyBackup($backupPath, $password);

        $extractDir = storage_path('app/'.config('rpebs.temp_path', 'temp').'/restore_'.uniqid());
        $this->files->makeDirectory($extractDir, 0755, true, true);

        try {
            $zip = new ZipArchive;
            $zip->open($backupPath);
            $zip->setPassword($password);

            $extracted = $zip->extractTo($extractDir);
            $zip->close();

            if (! $extracted) {
                throw new BackupException('Gagal mengekstrak berkas arsip backup.');
            }

            // 2. Restore database records FIRST
            $dbFile = $extractDir.'/database.json';
            $restoredTableCounts = [];

            if (! file_exists($dbFile)) {
                throw new BackupException('Berkas data database (database.json) tidak ditemukan di dalam arsip backup.');
            }

            $dbJson = file_get_contents($dbFile);
            $databaseData = json_decode((string) $dbJson, true);

            if (! is_array($databaseData)) {
                throw new BackupException('Data database dalam berkas backup tidak valid (format JSON rusak).');
            }

            // Run migrations if needed to ensure tables exist
            Artisan::call('migrate', ['--force' => true]);

            $connection = DB::connection();
            $driver = $connection->getDriverName();

            Log::info('BackupService: Memulai transaksi pemulihan database dari database.json...');

            DB::transaction(function () use ($connection, $driver, $databaseData, &$restoredTableCounts) {
                // Disable foreign key checks
                $this->setForeignKeyChecks($connection, $driver, false);

                try {
                    // Truncate/delete in reverse dependency order
                    $reverseTables = array_reverse(self::TABLES);
                    foreach ($reverseTables as $table) {
                        $connection->table($table)->delete();
                    }

                    // Insert in forward dependency order
                    foreach (self::TABLES as $table) {
                        if (! isset($databaseData[$table]) || ! is_array($databaseData[$table])) {
                            $restoredTableCounts[$table] = 0;

                            continue;
                        }

                        $rows = $databaseData[$table];
                        $count = count($rows);

                        if ($count > 0) {
                            foreach (array_chunk($rows, 500) as $chunk) {
                                $connection->table($table)->insert($chunk);
                            }
                        }

                        $restoredTableCounts[$table] = $count;
                    }
                } finally {
                    // Always re-enable foreign key checks
                    $this->setForeignKeyChecks($connection, $driver, true);
                }
            });

            Log::info('BackupService: Transaksi pemulihan database selesai.', $restoredTableCounts);

            // 3. Restore Telegram session files
            $sessionDir = $this->telegramSessionDir();
            $extractedSessionsDir = $extractDir.'/telegram-sessions';
            $restoredSessionsCount = 0;

            if (is_dir($extractedSessionsDir)) {
                if (! is_dir($sessionDir)) {
                    $this->files->makeDirectory($sessionDir, 0775, true, true);
                }

                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($extractedSessionsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($it as $item) {
                    $relative = substr($item->getRealPath(), strlen($extractedSessionsDir) + 1);
                    $targetPath = $sessionDir.DIRECTORY_SEPARATOR.$relative;

                    if ($item->isDir()) {
                        if (! is_dir($targetPath)) {
                            $this->files->makeDirectory($targetPath, 0775, true, true);
                        }
                    } else {
                        // Skip lock files
                        if (str_ends_with($item->getFilename(), '.lock') || $item->getFilename() === 'lock') {
                            continue;
                        }

                        $targetParent = dirname($targetPath);
                        if (! is_dir($targetParent)) {
                            $this->files->makeDirectory($targetParent, 0775, true, true);
                        }

                        try {
                            @copy($item->getRealPath(), $targetPath);
                            $restoredSessionsCount++;
                        } catch (\Throwable $e) {
                            Log::warning("Gagal menyalin session file {$targetPath}: ".$e->getMessage());
                        }
                    }
                }

                // Delete any dangling lock files in session directory
                $this->cleanLockFiles($sessionDir);
            }

            // 4. Restore environment variables (.env) LAST
            $restoreEnv = $options['restore_env'] ?? true;
            $restoredEnvKeys = [];

            if ($restoreEnv && isset($manifest['env']) && is_array($manifest['env'])) {
                $envUpdates = [];

                if (! empty($manifest['app_key'])) {
                    $envUpdates['APP_KEY'] = $manifest['app_key'];
                    $restoredEnvKeys[] = 'APP_KEY';
                }

                foreach ($manifest['env'] as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $envUpdates[$k] = $v;
                        if (! in_array($k, $restoredEnvKeys, true)) {
                            $restoredEnvKeys[] = $k;
                        }
                    }
                }

                $this->envManager->update($envUpdates);
            }

            // Clear cache config so updated APP_KEY is immediately active
            try {
                Artisan::call('config:clear');
            } catch (\Throwable) {
            }

            return [
                'manifest' => $manifest,
                'restored_env_keys' => $restoredEnvKeys,
                'restored_sessions_count' => $restoredSessionsCount,
                'restored_table_counts' => $restoredTableCounts,
            ];
        } finally {
            if ($this->files->isDirectory($extractDir)) {
                $this->files->deleteDirectory($extractDir);
            }
        }
    }

    private function telegramSessionDir(): string
    {
        return storage_path('app/'.config('rpebs.telegram.session_path', 'telegram-sessions'));
    }

    private function cleanLockFiles(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            if ($file->isFile()) {
                $name = $file->getFilename();
                if (str_ends_with($name, '.lock') || $name === 'lock') {
                    @unlink($file->getRealPath());
                }
            }
        }
    }

    private function setForeignKeyChecks(ConnectionInterface $connection, string $driver, bool $enable): void
    {
        if ($driver === 'sqlite') {
            $connection->statement($enable ? 'PRAGMA foreign_keys = ON;' : 'PRAGMA foreign_keys = OFF;');
        } elseif ($driver === 'mysql') {
            $connection->statement($enable ? 'SET FOREIGN_KEY_CHECKS = 1;' : 'SET FOREIGN_KEY_CHECKS = 0;');
        } elseif ($driver === 'pgsql') {
            $connection->statement($enable ? "SET session_replication_role = 'origin';" : "SET session_replication_role = 'replica';");
        }
    }
}
