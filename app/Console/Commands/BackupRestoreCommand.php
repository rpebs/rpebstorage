<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
        {path : Lokasi berkas arsip backup (.zip)}
        {--password= : Master password arsip backup}
        {--force : Lewati konfirmasi penggantian data}';

    protected $description = 'Pulihkan sistem dari berkas arsip backup terenkripsi';

    public function handle(BackupService $backupService): int
    {
        $path = (string) $this->argument('path');

        if (! file_exists($path)) {
            $this->error("Berkas backup tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $password = (string) $this->option('password');

        if ($password === '') {
            $password = (string) $this->secret('Masukkan master password arsip backup');
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirm(
                'PERINGATAN: Memulihkan backup akan menimpa seluruh metadata database, kredensial APP_KEY, dan sesi Telegram saat ini. Apakah Anda yakin ingin melanjutkan?',
                false
            );

            if (! $confirmed) {
                $this->info('Pemulihan dibatalkan.');

                return self::SUCCESS;
            }
        }

        $this->info('Memverifikasi dan memulihkan data dari arsip...');

        try {
            $result = $backupService->restoreBackup($path, $password);

            $this->info('Pemulihan sistem berhasil diselesaikan.');

            $tableRows = [];
            foreach ($result['restored_table_counts'] as $table => $count) {
                $tableRows[] = [$table, $count];
            }
            $tableRows[] = ['telegram_sessions', $result['restored_sessions_count']];

            $this->table(['Tabel / Resource', 'Baris Dipulihkan'], $tableRows);

            if (! empty($result['restored_env_keys'])) {
                $this->info('Kunci .env diperbarui: '.implode(', ', $result['restored_env_keys']));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal memulihkan sistem: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
