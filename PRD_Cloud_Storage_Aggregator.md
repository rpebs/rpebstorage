# Product Requirements Document (PRD)
## Platform Agregator Cloud Storage Multi-Provider dengan Dukungan Telegram

| | |
|---|---|
| **Versi Dokumen** | 1.0 |
| **Tanggal** | 11 September 2026 |
| **Status** | Draft |
| **Pemilik Produk** | God Loki |

---

## 1. Latar Belakang & Masalah

Saat ini pengguna yang memiliki banyak akun cloud storage (Google Drive, Dropbox, OneDrive, dll) dan beberapa akun sekaligus per provider, harus:

- Membuka aplikasi/tab berbeda-beda untuk tiap provider
- Mengingat login dan mengelola kuota masing-masing akun secara manual
- Tidak bisa memanfaatkan penyimpanan gratis besar dari Telegram karena tidak ada tools resmi yang mendukungnya sebagai backend storage

Platform serupa seperti **Omniroute** sudah menjawab sebagian masalah ini (agregasi multi-provider, multi-akun), namun **tidak mendukung Telegram** sebagai salah satu backend penyimpanan.

## 2. Tujuan Produk

1. Menyatukan banyak akun dari berbagai provider cloud storage ke dalam satu antarmuka (satu "virtual filesystem").
2. Memungkinkan penambahan lebih dari satu akun per provider.
3. Menjadikan Telegram sebagai salah satu backend penyimpanan yang didukung secara native.
4. Mempermudah manajemen file (upload, download, hapus, pindah, cari) tanpa peduli file itu sebenarnya tersimpan di provider mana.
5. Load balancing otomatis: sistem memilih akun dengan sisa kuota terbanyak saat upload.

## 3. Target Pengguna

- **Primary user:** Pemilik produk sendiri (personal use), sebagai pengganti Omniroute yang tidak mendukung Telegram.
- **Karakteristik:** Familiar dengan teknis, punya banyak akun cloud storage & Telegram, butuh kontrol penuh atas datanya sendiri (self-hosted).

## 4. Ruang Lingkup (Scope)

### 4.1 Termasuk dalam Scope (Versi 1.0 / MVP)
- Login/autentikasi user (single user atau multi-user sederhana)
- Menghubungkan (connect) akun: Google Drive, Dropbox, OneDrive, Telegram
- Bisa menghubungkan lebih dari satu akun per provider
- Upload file ke virtual filesystem (sistem otomatis pilih akun tujuan)
- Download file
- Hapus file
- Buat folder virtual (folder tidak harus benar-benar ada di provider aslinya)
- Melihat sisa kuota tiap akun
- Dashboard total kapasitas gabungan semua akun

### 4.2 Tidak Termasuk dalam Scope (Out of Scope) — Versi 1.0
- Sharing file ke publik/user lain
- Sinkronisasi dua arah otomatis (real-time sync seperti Dropbox client)
- Preview file di dalam browser (versi awal cukup download)
- Mobile app native (web app dulu, responsive)
- Enkripsi end-to-end file (bisa jadi versi selanjutnya)

## 5. Definisi Istilah (Glossary)

| Istilah | Penjelasan |
|---|---|
| **Provider** | Layanan penyedia storage, misal Google Drive, Dropbox, Telegram |
| **Storage Account** | Satu akun spesifik dari sebuah provider yang sudah terhubung ke sistem (contoh: akun Gmail A yang terhubung ke Google Drive) |
| **Virtual Filesystem** | Struktur folder/file yang dilihat user di UI, terpisah dari lokasi fisik file aslinya |
| **Remote Reference (remote_ref)** | Data unik yang menunjuk ke lokasi asli file di provider (contoh: `file_id` Telegram, `path` di Google Drive) |
| **Driver** | Kode program yang tahu cara bicara dengan API sebuah provider tertentu |
| **Chunking** | Proses memecah file besar menjadi beberapa bagian kecil sebelum diupload (dibutuhkan untuk Telegram) |
| **MTProto** | Protokol asli Telegram (bukan Bot API biasa) yang memungkinkan transfer file lebih besar |

---

## 6. Functional Requirements (Kebutuhan Fungsional)

Setiap requirement diberi ID supaya mudah dilacak saat development.

### 6.1 Autentikasi & Manajemen Akun

| ID | Requirement | Detail |
|---|---|---|
| FR-01 | User bisa login ke sistem | Menggunakan Laravel Breeze/Jetstream, email + password |
| FR-02 | User bisa menghubungkan akun Google Drive | OAuth2 flow, redirect ke Google, simpan access_token & refresh_token terenkripsi |
| FR-03 | User bisa menghubungkan akun Dropbox | OAuth2 flow serupa |
| FR-04 | User bisa menghubungkan akun OneDrive | OAuth2 via Microsoft Identity Platform |
| FR-05 | User bisa menghubungkan akun Telegram | Login menggunakan nomor HP + kode OTP via MTProto (bukan cuma bot token) |
| FR-06 | User bisa menghubungkan lebih dari 1 akun per provider | Setiap akun disimpan sebagai baris terpisah di tabel `storage_accounts` |
| FR-07 | User bisa memberi nama alias ke tiap akun | Misal: "Gdrive Kerja", "Gdrive Pribadi" |
| FR-08 | User bisa memutuskan koneksi (disconnect) akun | Token dihapus, file yang sudah ada di akun tsb ditandai "tidak bisa diakses" |
| FR-09 | Sistem otomatis refresh token yang hampir kedaluwarsa | Scheduled job berjalan tiap X jam |

### 6.2 Manajemen File

| ID | Requirement | Detail |
|---|---|---|
| FR-10 | User bisa upload file lewat UI (drag & drop atau pilih file) | Upload masuk ke queue, tidak block halaman |
| FR-11 | Sistem otomatis memilih akun tujuan upload | Berdasarkan sisa kuota terbesar di antara akun yang aktif, kecuali user pilih manual |
| FR-12 | User bisa memilih manual akun tujuan upload | Dropdown pilihan akun saat upload |
| FR-13 | User bisa membuat folder virtual | Folder hanya ada di database, tidak perlu dibuat di provider asli |
| FR-14 | User bisa memindahkan file antar folder virtual | Hanya mengubah `folder_id`, tidak upload ulang |
| FR-15 | User bisa download file | Sistem ambil `remote_ref`, panggil driver terkait, stream ke user |
| FR-16 | User bisa menghapus file | Hapus dari provider asli + hapus record di database |
| FR-17 | User bisa mencari file berdasarkan nama | Query ke tabel `virtual_files` |
| FR-18 | File besar (melebihi limit sekali kirim provider) otomatis di-chunk | Khusus Telegram; chunk disimpan di tabel `file_chunks` |
| FR-19 | Saat download file yang di-chunk, sistem gabungkan otomatis | User tidak perlu tahu proses chunking terjadi |

### 6.3 Dashboard & Monitoring

| ID | Requirement | Detail |
|---|---|---|
| FR-20 | User bisa melihat total kapasitas gabungan semua akun | Sum dari `quota_total` semua `storage_accounts` aktif |
| FR-21 | User bisa melihat sisa kuota per akun | Diperbarui berkala lewat scheduled job |
| FR-22 | User mendapat notifikasi jika sebuah akun hampir penuh | Threshold, misal di atas 90% terpakai |

---

## 7. Non-Functional Requirements (Kebutuhan Non-Fungsional)

| ID | Kategori | Requirement |
|---|---|---|
| NFR-01 | Performa | Upload/download file besar tidak boleh membuat halaman freeze — wajib pakai queue (Laravel Horizon + Redis) |
| NFR-02 | Keamanan | Semua token OAuth & kredensial disimpan terenkripsi (Laravel encrypted cast) |
| NFR-03 | Reliabilitas | Jika satu provider down, provider lain tetap bisa dipakai (isolasi kegagalan per driver) |
| NFR-04 | Skalabilitas | Struktur driver harus mudah ditambah provider baru tanpa mengubah kode inti (`StorageManager`) |
| NFR-05 | Portabilitas | Bisa dijalankan di lokal (development) maupun VPS (production) tanpa perubahan kode, hanya `.env` |
| NFR-06 | Observability | Semua job upload/download dicatat log-nya (sukses/gagal) untuk keperluan debug |

---

## 8. Arsitektur Sistem

### 8.1 Diagram Alur Tingkat Tinggi

```
[ User / Browser ]
        |
        v
[ Laravel Web App ] --- (Auth) --- [ Database: users, storage_accounts, virtual_files ]
        |
        v
[ StorageManager Service ]  <-- otak yang memilih driver & akun mana yang dipakai
        |
        +--> [ GoogleDriveDriver ] --> Google Drive API
        +--> [ DropboxDriver ]     --> Dropbox API
        +--> [ OneDriveDriver ]    --> Microsoft Graph API
        +--> [ TelegramDriver ]    --> Telegram MTProto (MadelineProto)
        |
        v
[ Queue Worker (Horizon) ] --> menjalankan UploadFileJob / DownloadFileJob di background
```

### 8.2 Prinsip Desain Utama: Driver Pattern

Semua provider **wajib** mengikuti kontrak (interface) yang sama, supaya kode di luar driver (controller, job) tidak perlu tahu detail tiap provider.

```php
interface StorageDriverInterface
{
    public function upload(string $localFilePath, string $fileName): UploadResult;
    public function download(string $remoteRef): string; // return local temp path
    public function delete(string $remoteRef): bool;
    public function getRemainingQuota(): int; // dalam bytes
}
```

**Kenapa ini penting dijelaskan ke junior programmer:**
Bayangkan `StorageManager` seperti resepsionis. Dia tidak perlu tahu cara kerja internal Google Drive atau Telegram. Dia cuma tahu: "saya butuh upload file ini", lalu dia panggil driver yang sesuai, dan driver itu yang urus detail teknisnya. Ini disebut **abstraksi** — memisahkan "apa yang harus dilakukan" dari "bagaimana caranya dilakukan".

### 8.3 Struktur Direktori Project

```
app/
├── Contracts/
│   └── StorageDriverInterface.php
│
├── Services/
│   └── Storage/
│       ├── StorageManager.php
│       ├── Drivers/
│       │   ├── GoogleDriveDriver.php
│       │   ├── DropboxDriver.php
│       │   ├── OneDriveDriver.php
│       │   └── TelegramDriver.php
│       └── Concerns/
│           ├── HandlesChunking.php
│           └── RefreshesToken.php
│
├── Models/
│   ├── StorageProvider.php
│   ├── StorageAccount.php
│   ├── VirtualFile.php
│   ├── VirtualFolder.php
│   └── FileChunk.php
│
├── Jobs/
│   ├── UploadFileJob.php
│   ├── DownloadFileJob.php
│   ├── RefreshProviderTokenJob.php
│   └── SyncStorageQuotaJob.php
│
├── Http/
│   ├── Controllers/
│   │   ├── Auth/ProviderOAuthController.php
│   │   ├── FileManagerController.php
│   │   └── StorageAccountController.php
│   └── Middleware/
│       └── EnsureAccountTokenValid.php
│
└── Console/Commands/
    └── TelegramPollingListen.php
```

---

## 9. Skema Database (Data Model)

### 9.1 Entity Relationship (penjelasan hubungan antar tabel)

- Satu **User** bisa punya banyak **Storage Account**
- Satu **Storage Account** dimiliki oleh satu **Storage Provider** (Google Drive/Dropbox/dst)
- Satu **Virtual File** disimpan di **satu Storage Account** tertentu
- Satu **Virtual File** bisa punya banyak **File Chunk** (jika dipecah)
- Satu **Virtual Folder** bisa punya banyak **Virtual File** dan banyak **Virtual Folder** anak (struktur pohon/tree)

### 9.2 Detail Tabel

**`storage_providers`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint, PK | |
| name | varchar | `google_drive`, `dropbox`, `onedrive`, `telegram` |
| driver_class | varchar | nama class driver, misal `App\Services\Storage\Drivers\TelegramDriver` |
| is_active | boolean | untuk matikan sementara provider tanpa hapus data |

**`storage_accounts`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint, PK | |
| user_id | bigint, FK → users | |
| storage_provider_id | bigint, FK → storage_providers | |
| alias | varchar | nama yang dibuat user, misal "Gdrive Kerja" |
| credentials | text (encrypted) | JSON berisi access_token, refresh_token, dll |
| quota_total | bigint | dalam bytes |
| quota_used | bigint | dalam bytes |
| status | enum | `active`, `expired`, `disconnected` |
| created_at, updated_at | timestamp | |

**`virtual_folders`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint, PK | |
| user_id | bigint, FK | |
| parent_id | bigint, nullable, FK → virtual_folders.id | untuk struktur folder bertingkat |
| name | varchar | |

**`virtual_files`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint, PK | |
| virtual_folder_id | bigint, nullable, FK | null berarti di root |
| storage_account_id | bigint, FK | akun mana yang menyimpan file ini |
| name | varchar | nama file yang dilihat user |
| remote_ref | text | referensi ke file asli (path atau file_id) |
| size | bigint | dalam bytes |
| mime_type | varchar | |
| is_chunked | boolean | true jika file dipecah jadi beberapa chunk |
| created_at, updated_at | timestamp | |

**`file_chunks`**
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint, PK | |
| virtual_file_id | bigint, FK | |
| chunk_index | integer | urutan chunk (0, 1, 2, ...) |
| remote_file_id | varchar | file_id Telegram untuk chunk ini |
| size | bigint | |

---

## 10. Detail Integrasi per Provider

### 10.1 Google Drive
- **Metode Auth:** OAuth 2.0
- **Library:** `google/apiclient` (PHP)
- **Endpoint utama:** Drive API v3 (`files.create`, `files.get`, `files.delete`, `about.get` untuk cek kuota)
- **Catatan:** Refresh token wajib disimpan karena access_token expired dalam 1 jam

### 10.2 Dropbox
- **Metode Auth:** OAuth 2.0
- **Library:** `spatie/dropbox-api` atau HTTP client manual
- **Endpoint utama:** `/2/files/upload`, `/2/files/download`, `/2/files/delete_v2`, `/2/users/get_space_usage`

### 10.3 OneDrive
- **Metode Auth:** OAuth 2.0 via Microsoft Identity Platform (Azure App Registration)
- **Library:** Microsoft Graph SDK atau HTTP client manual ke Graph API
- **Endpoint utama:** `/me/drive/root:/path:/content` (upload), `/me/drive/quota`

### 10.4 Telegram (bagian paling krusial & berbeda)

**Kenapa tidak pakai Bot API biasa?**
Bot API standar Telegram membatasi ukuran file: maksimal **50MB untuk upload, 20MB untuk download** melalui bot biasa. Untuk memanfaatkan Telegram sebagai *cloud storage* yang layak (file besar), kita perlu login sebagai **akun user asli** menggunakan protokol **MTProto**, yang mendukung file hingga **2GB** (atau 4GB untuk akun Telegram Premium).

**Library yang digunakan:** `danog/madelineproto` (PHP, MTProto client paling matang)

**Alur Kerja Detail:**
1. User memasukkan nomor HP di form "Connect Telegram"
2. Sistem panggil MadelineProto untuk minta OTP dikirim ke akun Telegram tsb
3. User masukkan kode OTP yang diterima
4. Session Telegram tersimpan (biasanya berupa file session `.madeline` per akun) — ini disimpan terenkripsi
5. Sistem membuat/menggunakan sebuah **private channel/grup** sebagai "bucket" penyimpanan
6. Saat upload: file dikirim sebagai dokumen ke channel tsb → Telegram mengembalikan `message_id` dan `file_id` → kedua ID ini disimpan sebagai `remote_ref`
7. Saat download: sistem panggil `messages.getMessages` menggunakan `message_id` untuk ambil ulang file

**Penanganan File Besar (Chunking):**
Jika file yang diupload user melebihi 2GB (limit MTProto), file dipecah dulu menjadi beberapa bagian (misal per 1.5GB) sebelum dikirim, masing-masing chunk dikirim sebagai pesan terpisah ke channel yang sama, lalu index urutannya dicatat di tabel `file_chunks`. Saat didownload, sistem ambil semua chunk sesuai urutan `chunk_index`, lalu digabungkan kembali menjadi file utuh menggunakan `HandlesChunking` trait sebelum diserahkan ke user.

**Catatan Risiko:**
- Ini menggunakan API Telegram di luar use-case resmi yang didukung. Bukan tindakan ilegal, tapi bukan fitur resmi — anggap sebagai penyimpanan best-effort, bukan untuk data kritis/production penting.
- Rate limit Telegram bisa terpicu jika upload dalam jumlah sangat besar secara berurutan cepat — perlu delay/throttle di dalam job.

---

## 11. Alur Pengguna (User Flow) — Contoh: Upload File

1. User membuka halaman "File Manager"
2. User drag & drop file (atau klik tombol upload)
3. Frontend kirim file ke endpoint `POST /files/upload`
4. `FileManagerController` menyimpan file sementara di storage lokal server (`storage/app/temp`)
5. Controller dispatch `UploadFileJob` ke queue, lalu langsung mengembalikan respons "Upload sedang diproses" ke user (tidak menunggu selesai)
6. Di background, `UploadFileJob` jalan:
   a. Panggil `StorageManager::pickBestAccount()` untuk menentukan akun tujuan
   b. Panggil driver yang sesuai, misal `TelegramDriver::upload()`
   c. Jika file > limit, `HandlesChunking` split file dulu
   d. Simpan hasil (`remote_ref`) ke tabel `virtual_files`
   e. Hapus file sementara dari server
7. Frontend polling status job (atau pakai Laravel Echo/websocket) untuk update UI ketika selesai

---

## 12. Rencana Pengembangan Bertahap (Milestones)

| Fase | Fokus | Output |
|---|---|---|
| **Fase 1 — Fondasi** | Setup project Laravel, migration, model, `StorageDriverInterface` | Struktur project siap, database jadi |
| **Fase 2 — Driver Provider Cloud Umum** | Implementasi Google Drive, Dropbox, OneDrive driver + OAuth flow | Bisa connect & upload/download ke 3 provider ini |
| **Fase 3 — Integrasi Telegram** | Setup MadelineProto, login via OTP, upload/download dasar | Bisa upload file kecil ke Telegram |
| **Fase 4 — Chunking** | Implementasi split & gabung file besar khusus Telegram | File besar bisa diupload/download utuh |
| **Fase 5 — UI & UX** | Dashboard, file manager UI, drag & drop, progress bar | Aplikasi siap dipakai sehari-hari |
| **Fase 6 — Queue & Reliability** | Setup Horizon, retry logic, error handling, logging | Sistem stabil untuk file besar & banyak job |
| **Fase 7 — Deploy** | Migrasi dari lokal ke VPS, setup HTTPS, scheduler | Aplikasi jalan 24/7 di VPS |

---

## 13. Risiko & Asumsi

| Risiko/Asumsi | Dampak | Mitigasi |
|---|---|---|
| Telegram bisa membatasi/menganggap penyalahgunaan API jika volume sangat besar | Akun bisa dibatasi sementara | Throttle upload, jangan agresif, gunakan lebih dari satu akun Telegram jika perlu |
| OAuth token provider bisa dicabut sewaktu-waktu oleh user dari sisi provider | File jadi tidak bisa diakses | Deteksi status token, tandai akun `expired`, beri notifikasi ke user |
| File yang di-chunk lebih rumit untuk di-debug jika corrupt | Data tidak bisa dipulihkan | Simpan checksum tiap chunk untuk validasi saat digabung |
| Berjalan di lokal berarti tidak selalu online | Upload/download terputus jika laptop mati | Rencanakan migrasi ke VPS setelah fitur stabil (lihat Fase 7) |

---

## 14. Referensi Teknis untuk Development

- Google Drive API: https://developers.google.com/drive/api
- Dropbox API: https://www.dropbox.com/developers/documentation/http/documentation
- Microsoft Graph API (OneDrive): https://learn.microsoft.com/en-us/graph/api/resources/onedrive
- MadelineProto (Telegram MTProto PHP): https://docs.madelineproto.xyz/
- Laravel Horizon (queue monitoring): https://laravel.com/docs/horizon
