# Spec: rpebstorage, Platform Agregator Cloud Storage

|         |                                               |
| ------- | --------------------------------------------- |
| Tanggal | 2026-09-11                                    |
| Sumber  | `PRD_Cloud_Storage_Aggregator.md` v1.0        |
| Status  | Disetujui user (desain presentasi 2026-09-11) |

## 1. Ringkasan

Platform web self-hosted yang menyatukan banyak akun cloud storage (Google Drive, Dropbox, OneDrive, Telegram) ke satu virtual filesystem. Upload otomatis dipilihkan ke akun dengan sisa kuota terbesar. Telegram didukung native via MTProto (MadelineProto). Single user, Laravel monolith dengan Inertia + Vue.

## 2. Goals / Non-goals

**Goals (MVP, sesuai PRD §4.1):** auth single user; connect multi-akun per provider (GDrive, Dropbox, OneDrive, Telegram); upload (otomatis/manual account pick) dengan queue; download; hapus; folder virtual; pindah file antar folder; cari nama file; kuota per akun; dashboard total gabungan; notifikasi akun >90%.

**Non-goals (v1):** sharing, sync dua arah, preview di browser, mobile native, E2E encryption.

## 3. Stack & Environment

| Layer       | Pilihan                                                                                                                  |
| ----------- | ------------------------------------------------------------------------------------------------------------------------ |
| Framework   | Laravel 13 (PHP 8.5, Laragon; `php85` di PATH)                                                                           |
| Starter kit | Resmi **Vue starter kit**: Inertia 3, Vue 3 Composition API, TypeScript, Tailwind 4, shadcn-vue, Fortify auth, Wayfinder |
| Auth        | Fortify; **registration dimatikan** (hapus `Features::registration()`); user dibuat via seeder                           |
| DB          | MySQL (Laragon), DB `rpebstorage`, dibuat via migrasi resmi repo ini                                                     |
| Queue       | Horizon + Redis, client **predis** (ext-redis PHP belum terpasang)                                                       |
| OAuth       | Laravel Socialite: google, dropbox, microsoft (onedrive)                                                                 |
| Telegram    | danog/madelineproto sebagai **daemon proses terpisah**                                                                   |
| UI bahasa   | Indonesia                                                                                                                |
| Dev         | Laragon (PHP 8.5, MySQL, Redis bundled), Node 24                                                                         |

Catatan: PRD menyebut Breeze/Jetstream; di Laravel 13 itu legacy, starter kit Vue adalah penerus resminya (Fortify di belakangnya).

## 4. Arsitektur

```
[Browser / Inertia Vue]
        |
[Laravel (web, session auth)]
        |
[StorageManager]  -- pilih driver & akun
   |-- GoogleDriveDriver (Socialite + google/apiclient / HTTP)
   |-- DropboxDriver     (HTTP client / spatie/dropbox-api)
   |-- OneDriveDriver    (HTTP client ke Microsoft Graph)
   |-- TelegramDriver    (Redis RPC --> telegram daemon)
        |
[Horizon worker] --> UploadFileJob, DownloadFileJob (chunked only), RefreshProviderTokenJob, SyncStorageQuotaJob
        |
[php artisan telegram:listen]  -- proses daemon, satu-satunya pemilik session MadelineProto
```

### 4.1 Keputusan arsitektur kunci

1. **Telegram daemon terpisah.** File session MadelineProto hanya boleh dipegang satu proses. Queue job TIDAK memuat MadelineProto; mereka mengirim RPC via Redis ke daemon `telegram:listen`. Alur OTP connect juga lewat daemon.
2. **Download streaming langsung**, bukan job untuk semua file. Controller stream dari provider ke browser (NFR-01 tetap terpenuhi: browser yang menampilkan progres, halaman tidak freeze; tidak ada duplikasi 2GB di disk). **`DownloadFileJob` hanya untuk file chunked Telegram** yang harus digabung di temp dulu.
3. **Upload selalu via queue** (PRD FR-10, NFR-01): controller simpan ke `storage/app/temp`, dispatch `UploadFileJob`, UI polling status.
4. **pickBestAccount rule:** dari akun `active`, pilih sisa kuota terbesar. Akun Telegram (kuota unlimited) dipakai sebagai fallback hanya jika TIDAK ada akun berkuota-limited dengan sisa > 1GB, atau jika user pilih manual. Alasan: kalau unlimited ikut "sisa terbesar", semua file masuk Telegram.
5. **Kuota Telegram:** API Telegram tidak menyediakan kuota total. `quota_total` akun Telegram = `NULL` (unlimited). Dashboard menampilkannya terpisah ("unlimited"), agregasi total hanya menjumlah akun berkuota.

## 5. Kontrak Driver

```php
interface StorageDriverInterface
{
    public function upload(string $account, string $localFilePath, string $fileName): UploadResult; // UploadResult{ remoteRef, size }
    public function download(string $account, string $remoteRef): string;   // path lokal temp
    public function delete(string $account, string $remoteRef): bool;
    public function getRemainingQuota(string $account): ?int;               // null = unlimited
}
```

Driver menerima kredensial/session akun yang sudah di-decrypt oleh `StorageManager` (credentials `encrypted` cast di model). Deviasi kecil dari PRD §8.2: parameter `$account` eksplisit karena satu driver class dipakai banyak akun.

- Concern `HandlesChunking`: split file > limit (Telegram 1.5GB per chunk), kirim per chunk, simpan `chunk_index` + `remote_file_id` + `size` + `checksum` (md5) per chunk; merge saat download dengan validasi checksum.
- Concern `RefreshesToken`: refresh access_token via refresh_token, tulis balik ke `credentials`.
- `EnsureAccountTokenValid` middleware: tandai akun `expired` saat refresh gagal (401/invalid_grant) dan tampilkan reconnect di UI.

## 6. Telegram Daemon

**Proses:** `php artisan telegram:listen` (dijalankan via `composer dev` / supervisor nanti).

**RPC via Redis:**

- Laravel `LPUSH telegram:rpc` JSON `{id, action, account_id, payload}`.
- Daemon `BRPOP`, eksekusi, reply `SETEX telegram:rpc:reply:{id} 300 <json>`; Laravel polling/blpop dengan timeout.
- Actions:
    - `connect.request_code` `{phone}` -> `{request_token}` (dialog web minta OTP)
    - `connect.complete` `{phone, code, password?}` -> session disimpan terenkripsi di `storage/telegram-sessions/{account_id}.session` (enkripsi via Laravel `Crypt`)
    - `connect.create_bucket` -> buat private channel, simpan channel id di `storage_accounts.meta`
    - `upload` `{temp_path, file_name, size}` -> `{remote_ref}` (message_id + channel id; chunking ditangani daemon sesuai meta akun)
    - `download` `{remote_ref, dest_path}` -> merge chunk otomatis jika perlu
    - `delete` `{remote_ref}` -> hapus pesan channel
- Progres: daemon adalah command Laravel penuh (punya akses DB), jadi ia update `file_jobs.progress` langsung saat upload berjalan. UI polling `/files/jobs` sudah cukup, tanpa pubsub tambahan.

**Throttle:** delay antar upload per akun (default 2 detik, via `sleep`/rate limiter di daemon) untuk mitigaasi FLOOD_WAIT. FLOOD_WAIT diteruskan sebagai error terstruktur; job retry dengan backoff.

## 7. Skema Database

Sesuai PRD §9, plus perubahan berikut (detail kolom di migrasi):

| Tabel                  | Catatan                                                                                                                                                                                                                                   |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users`                | bawaan starter kit                                                                                                                                                                                                                        |
| `storage_providers`    | `name` (unique: google_drive/dropbox/onedrive/telegram), `driver_class`, `is_active`; seeder 4 baris                                                                                                                                      |
| `storage_accounts`     | `credentials` text encrypted-cast (JSON), `meta` JSON (channel id Telegram), `status` enum(active/expired/disconnected), `quota_total` bigint NULL (NULL=unlimited), `quota_used` bigint, `quota_synced_at` timestamp                     |
| `virtual_folders`      | `parent_id` self-FK nullable, unique(`user_id`,`parent_id`,`name`)                                                                                                                                                                        |
| `virtual_files`        | `virtual_folder_id` nullable (null=root), `storage_account_id`, `name`, `remote_ref` text, `size`, `mime_type`, `is_chunked` bool; index `(user_id via folder?)` name index untuk search                                                  |
| `file_chunks`          | `virtual_file_id`, `chunk_index`, `remote_file_id`, `size`, `checksum`                                                                                                                                                                    |
| `file_jobs` **(baru)** | `user_id`, `type` enum(upload), `virtual_folder_id`, `storage_account_id` nullable, `original_name`, `size`, `mime_type`, `status` enum(pending/processing/done/failed), `progress` tinyint, `error` text nullable; dibutuhkan polling UI |

Catatan search: `virtual_files.name` di-query LIKE + index; Scout/Meilisearch tidak untuk MVP.

## 8. Routes

```
GET    /dashboard                      Inertia (total kapasitas, bar per akun, banner >90%)
GET    /files?folder=                  Inertia file manager
POST   /files/folders                  buat folder virtual
PATCH  /files/folders/{folder}         rename
DELETE /files/folders/{folder}         hapus (hanya kosong)
POST   /files/upload                   multipart -> temp -> UploadFileJob -> file_jobs
GET    /files/jobs                     polling status job aktif
GET    /files/{file}/download          stream (chunked: 202 + job, redirect saat siap)
PATCH  /files/{file}/move              ubah virtual_folder_id
DELETE /files/{file}                   hapus provider + record
GET    /files/search?q=                cari nama
GET    /accounts                       Inertia daftar akun
GET    /accounts/connect/{provider}    redirect OAuth (Socialite)
GET    /accounts/callback/{provider}   callback, simpan token terenkripsi, ambil kuota awal
POST   /accounts/telegram              mulai OTP (RPC connect.request_code)
POST   /accounts/telegram/verify       complete OTP (+password 2FA jika perlu)
PATCH  /accounts/{account}             alias
DELETE /accounts/{account}             disconnect: token/session dihapus, akun disconnected,
                                       file terkait ditandai tidak bisa diakses (FR-08)
GET    /horizon                        dashboard Horizon (protected)
```

Scheduler: `RefreshProviderTokenJob` per jam (akun OAuth aktif), `SyncStorageQuotaJob` per 6 jam; dashboard trigger sync on-demand bila `quota_synced_at` > 1 jam.

## 9. Alur Kunci

**Upload:** drop file -> POST /files/upload -> simpan `storage/app/temp/{uuid}` -> buat `file_jobs(pending)` -> dispatch UploadFileJob -> 201 + job id. Job: pickBestAccount -> driver->upload (Telegram: RPC ke daemon, chunking otomatis) -> insert `virtual_files` (+ `file_chunks`) -> update job done -> hapus temp. UI polling `/files/jobs` tiap 2 detik selama ada job aktif. Gagal: status failed + error, temp dibersihkan.

**Download:** GET /files/{id}/download -> if `is_chunked`: cek cache temp gabungan, kalau tidak ada dispatch DownloadFileJob, balas 202 + job id; UI poll lalu redirect. Single file: driver->download materialize ke temp lalu response streamed, temp dihapus setelah selesai. Biaya disk sementara = ukuran file; upgrade pasca-MVP: streaming passthrough per provider tanpa temp.

**Connect Telegram:** POST /accounts/telegram (phone) -> RPC request_code -> dialog OTP -> verify -> session tersimpan -> create_bucket -> akun active. Error OTP salah/2FA ditampilkan di dialog.

**Disconnect:** token/session dihapus (disk + DB), status `disconnected`, file tetap tercatat tapi aksi download/hapus diblok dengan pesan jelas.

## 10. UI

Lihat `DESIGN.md` (root). Halaman: Login (starter kit), Dashboard, Files, Accounts. Semua view data wajib empty/loading/error. Ikon semantik saja. Capacity bar = motif identitas. `file_jobs` jadi sumber progress panel.

## 11. Testing

- **Unit:** `pickBestAccount` (max sisa, fallback unlimited, manual override), `HandlesChunking` split/merge + checksum mismatch, format kuota.
- **Feature (FakeDriver dengan kontrak interface):** upload end-to-end (temp -> job -> virtual_files), polling jobs, download single & chunked (202->poll->200), folder CRUD + unique rule, move, delete, search, connect callback menyimpan token terenkripsi, disconnect menandai akun & blokir akses file, single-user gate (registration 404).
- **Manual per provider** (butuh kredensial user): OAuth GDrive/Dropbox/OneDrive, OTP Telegram, upload/download nyata. Langkah uji dicatat di plan Fase 2-3.
- Test jalan di CI lokal: `php artisan test` dengan SQLite :memory:.

## 12. Milestones (dari PRD §12)

1. Fondasi: laravel new (Vue kit), git, migrasi + model + seeder, kontrak driver, seeder user.
2. Driver cloud + OAuth: Socialite 3 provider, upload/download/delete/quota, halaman Accounts.
3. Telegram: daemon, RPC, OTP wizard, upload/download kecil, bucket channel.
4. Chunking: split/merge + checksum + DownloadFileJob.
5. UI & UX: file manager penuh, drag&drop, progress polling, dashboard.
6. Queue & reliability: Horizon, retry/backoff, throttle, logging (NFR-06), notifikasi 90%.
7. Deploy VPS: HTTPS, supervisor (horizon + telegram daemon), scheduler.

## 13. Prasyarat dari User

1. Google Cloud OAuth client + Drive API enabled
2. Dropbox app (redirect URI `/accounts/callback/dropbox`)
3. Azure app registration (Microsoft Graph, Files.Read.All + offline_access)
4. my.telegram.org: `api_id` + `api_hash`
5. php.ini Laragon: `upload_max_filesize`/`post_max_size` dinaikkan untuk file besar

## 14. Risiko & Mitigasi (dari PRD §13 + temuan)

| Risiko                                                          | Mitigasi                                                                                    |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| MadelineProto vs PHP 8.5 belum terverifikasi (fetch docs gagal) | Verifikasi awal Fase 3; fallback jalankan daemon via PHP 8.4 di Laragon (multi-PHP coexist) |
| FLOOD_WAIT / rate limit Telegram                                | Throttle per akun, backoff retry, batasi concurrency per akun                               |
| Chunk corrupt                                                   | checksum per chunk, validasi saat merge, gagal = error jelas per chunk                      |
| Token dicabut provider                                          | Middleware deteksi 401 -> status expired + banner reconnect                                 |
| Upload 2GB single POST                                          | Tuning php.ini; client-side chunking = upgrade pasca-MVP (ponytail)                         |
