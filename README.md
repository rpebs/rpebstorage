# rpebstorage

Agregator cloud storage multi-provider self-hosted: Google Drive, Dropbox, OneDrive, dan Telegram (MTProto) tampil sebagai satu virtual filesystem. Upload otomatis dipilihkan akun dengan sisa kuota terbesar. Berbasis PRD v1.0 (`PRD_Cloud_Storage_Aggregator.md` di root repo).

## Prasyarat

- PHP 8.5 (Windows: Laragon), ekstensi: openssl, pdo_mysql, mbstring, curl, fileinfo, gd, zip, sodium, bcmath
- MySQL 8 / MariaDB
- Redis (Laragon: `c:\laragon\bin\redis`)
- Node 20+
- Horizon hanya jalan di Linux/macOS (butuh pcntl/posix); di Windows dev pakai `composer dev` (queue:listen), Horizon aktif di VPS

## Setup

```bash
cp .env.example .env            # lalu sesuaikan DB_*, ADMIN_EMAIL, ADMIN_PASSWORD
composer install
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
composer dev                    # server + queue worker + vite
```

User pertama dibuat dari seeder (`php artisan db:seed`), memakai `ADMIN_EMAIL` / `ADMIN_PASSWORD` di `.env`. Registrasi publik dimatikan (single-user).

Kredensial provider diisi di `.env`: `GOOGLE_CLIENT_ID/SECRET`, `DROPBOX_CLIENT_ID/SECRET`, `MICROSOFT_CLIENT_ID/SECRET`, `TELEGRAM_API_ID/API_HASH` (dari [my.telegram.org](https://my.telegram.org)). Redirect URI OAuth: `/accounts/callback/{google_drive|dropbox|onedrive}`.

## Telegram

Telegram butuh daemon tersendiri (MadelineProto memegang file session, tidak boleh dipakai dua proses):

```bash
composer telegram               # php artisan telegram:listen
```

Alur: Connect Telegram -> OTP ke aplikasi -> sistem membuat private channel "rpebstorage bucket" -> file diunggah sebagai dokumen ke channel. File > 1.5 GB otomatis di-chunk (index + checksum di `file_chunks`). Tanpa daemon, UI gagal cepat dengan pesan jelas.

Catatan: ini use-case API Telegram di luar bot resmi; best-effort, throttle per akun sudah terpasang (delay antar upload + backoff `FLOOD_WAIT`).

## Upload besar (php.ini)

Naikkan `upload_max_filesize` / `post_max_size` (mis. 4G) dan `max_execution_time=0` untuk file besar. Upload selalu async via queue, halaman tidak freeze.

## Queue & monitoring

- Dev: `composer dev` (server + queue worker + vite)
- Produksi: Horizon (`php artisan horizon`) + supervisor, dashboard di `/horizon` (hanya user login)
- Scheduler: `php artisan schedule:work` (dev) atau cron `* * * * * php artisan schedule:run` (VPS) -> refresh token tiap jam, sync kuota tiap 6 jam

## Deploy VPS (Fase 7 PRD)

Tanpa perubahan kode, hanya `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://domainmu`, kredensial provider. Yang perlu jalan sebagai proses:

1. PHP-FPM + nginx (root `public/`)
2. `php artisan horizon` (via supervisor)
3. `php artisan telegram:listen` (via supervisor)
4. cron `schedule:run`
5. `php artisan migrate --force` + `composer install --no-dev` saat rilis

## Test

```bash
php artisan test
composer types:check            # phpstan (baseline untuk dynamic Eloquent noise)
composer lint
```

OAuth provider & Telegram nyata diuji manual (butuh kredensial); seluruh logika inti (pickBestAccount, chunking+checksum, upload/download/folder/move/search/hapus, disconnect, queue flow) tercakup suite.

## Struktur penting

```
app/
├── Contracts/StorageDriverInterface.php   # kontrak driver (upload/download/delete/quota)
├── Services/Storage/
│   ├── StorageManager.php                 # resepsionis: pilih driver + akun
│   ├── Concerns/HandlesChunking.php       # split/merge + checksum
│   ├── OAuth/ProviderOAuth.php            # OAuth2 manual 3 cloud
│   └── Drivers/{GoogleDrive,Dropbox,OneDrive,Telegram}Driver.php
├── Services/Telegram/TelegramRpc.php      # klien Redis RPC -> daemon
├── Console/Commands/TelegramListenCommand.php  # daemon pemilik session
└── Jobs/{UploadFileJob,DownloadFileJob,SyncStorageQuotaJob,RefreshProviderTokenJob}.php
```
