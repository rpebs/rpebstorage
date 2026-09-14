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

## Mengisi kredensial provider (per provider)

Semua variabel di bawah ada di `.env` (template di `.env.example`). Setelah mengubah `.env`, selalu jalankan `php artisan config:clear` (dan `php artisan optimize:clear` kalau pernah `php artisan optimize`). Nilai `APP_URL` menentukan redirect URI yang didaftarkan, jadi samakan.

Pastikan `APP_URL` sudah sesuai sebelum mendaftarkan redirect URI di masing-masing portal. Dev default: `APP_URL=http://127.0.0.1:8000`.

### Google Drive (`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`)
1. https://console.cloud.google.com/ -> buat project (mis. "rpebstorage").
2. **APIs & Services -> Library** -> enable **Google Drive API**.
3. **APIs & Services -> OAuth consent screen** -> External -> isi app name + email -> scopes: `drive` (dan `openid`, `email`, `profile` otomatis). Tambahkan akun kamu di **Test users** selagi status Testing.
4. **APIs & Services -> Credentials -> Create Credentials -> OAuth client ID** -> type **Web application**.
   - Authorized JavaScript origins: `http://127.0.0.1:8000` (opsional).
   - **Authorized redirect URIs**: `http://127.0.0.1:8000/accounts/callback/google_drive`
   - Production: tambahkan juga `https://domainmu/accounts/callback/google_drive`.
5. Salin Client ID + Client Secret ke `.env`.

### Dropbox (`DROPBOX_CLIENT_ID` / `DROPBOX_CLIENT_SECRET`)
1. https://www.dropbox.com/developers/apps -> **Create app** -> **Dropbox API** -> scope **Full Dropbox** (atau App folder).
2. Di halaman app: **OAuth2** -> **Add redirect URI**: `http://127.0.0.1:8000/accounts/callback/dropbox` (dan versi production).
3. **Grant type**: Authorization code (dengan refresh token). Sistem sudah memakai `token_access_type=offline`, jadi tidak perlu diubah manual.
4. Salin **App key** -> `DROPBOX_CLIENT_ID`, **App secret** -> `DROPBOX_CLIENT_SECRET`.

### OneDrive / Microsoft (`MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET`)
1. https://entra.microsoft.com/ (atau portal.azure.com) -> **App registrations -> New registration**.
2. Supported account types: "Accounts in any organizational directory and personal Microsoft accounts" (perlu buat akun personal OneDrive).
3. **Redirect URI**: platform **Web**, `http://127.0.0.1:8000/accounts/callback/onedrive` (+ production).
4. **API permissions -> Add a permission -> Microsoft Graph -> Delegated**: `Files.ReadWrite.All` dan `offline_access` (wajib untuk refresh token).
5. **Certificates & secrets -> New client secret** -> salin nilainya.
6. `MICROSOFT_CLIENT_ID` = Application (client) ID, `MICROSOFT_CLIENT_SECRET` = client secret.

### Telegram (`TELEGRAM_API_ID` / `TELEGRAM_API_HASH`)
1. Login https://my.telegram.org/ dengan nomor Telegram (pakai kode login, bukan OTP upload).
2. **API development tools** -> isi app title/short name -> Create.
3. Salin `Api_id` -> `TELEGRAM_API_ID`, `Api_hash` -> `TELEGRAM_API_HASH`.
4. Perlu daemon Telegram jalan (`composer telegram`), lihat bagian Telegram di bawah.

### Ringkas variabel `.env`

```dotenv
APP_URL=http://127.0.0.1:8000

GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
DROPBOX_CLIENT_ID=...
DROPBOX_CLIENT_SECRET=...
MICROSOFT_CLIENT_ID=...
MICROSOFT_CLIENT_SECRET=...
TELEGRAM_API_ID=...
TELEGRAM_API_HASH=...
```

Provider yang belum diisi kredensialnya tampil **nonaktif** di halaman Akun dengan keterangan "butuh kredensial .env" (bukan tombol mati senyap). Setelah mengisi, jalankan `php artisan config:clear`.

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
