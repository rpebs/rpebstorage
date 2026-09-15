<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupExportCommand extends Command
{
    protected $signature = 'backup:export
        {--password= : Master password untuk enkripsi arsip backup}
        {--output= : Lokasi path berkas output .zip}';

    protected $description = 'Ekspor seluruh konfigurasi, metadata database, dan sesi Telegram ke berkas arsip terenkripsi';

    public function handle(BackupService $backupService): int
    {
        $password = (string) $this->option('password');

        if ($password === '') {
            $password = (string) $this->secret('Masukkan master password untuk proteksi enkripsi backup (minimal 8 karakter)');
            $confirm = (string) $this->secret('Konfirmasi master password');

            if ($password !== $confirm) {
                $this->error('Konfirmasi password tidak cocok.');

                return self::FAILURE;
            }
        }

        if (strlen($password) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        $output = $this->option('output') ? (string) $this->option('output') : null;

        $this->info('Membuat arsip backup terenkripsi...');

        try {
            $filePath = $backupService->createBackup($password, $output);
            $size = file_exists($filePath) ? round(filesize($filePath) / 1024, 2).' KB' : '0 KB';

            $this->info("Backup berhasil dibuat di: {$filePath} ({$size})");

            $stats = $backupService->getSystemStats();
            $tableRows = [];
            foreach (BackupService::TABLES as $table) {
                $tableRows[] = [$table, $stats[$table.'_count'] ?? 0];
            }
            $tableRows[] = ['telegram_sessions', $stats['telegram_sessions_count'] ?? 0];

            $this->table(['Tabel / Resource', 'Jumlah Baris'], $tableRows);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal membuat backup: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
