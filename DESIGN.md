# DESIGN.md

Arah desain untuk **rpebstorage** (agregator cloud storage). Filter antislop diterapkan di atas arah ini.

## Identity

Aplikasi storage personal, self-hosted, dipakai harian oleh satu orang teknis. Bukan landing page: semua layar adalah layar aplikasi yang punya satu pekerjaan jelas per layar.

## Personality

**Utilitarian presisi.** Bersih, fokus fungsi, hierarki jelas, sedikit hiasan. Data adalah bintangnya; kerangka UI mundur ke belakang.

## Dials

`ENERGY 1 / RHYTHM 1 / MOTION 1`

- Motion: hover states + transisi status saja (progress bar bergerak karena datanya bergerak). Tanpa animasi dekoratif, tanpa loop tanpa trigger.
- Rhythm: layout aplikasi konsisten antar halaman; variasi hanya dari konten (dashboard vs file list vs accounts).

## Palette

- Inti: netral (light: putih/abu bertingkat; dark: setara gelap). Netral tidak dihitung core palette.
- Aksen: **teal** (hijau kebiruan). Alasan: identitas "storage/capacity", bukan biru-ungu default AI.
- Aksen dipakai hanya di momen kunci: tombol utama, progress upload, capacity bar. Tidak di mana-mana.
- Semua teks kontras WCAG AA (4.5:1 normal, 3:1 besar), di light dan dark.

## Motif identitas

**Capacity bar**: garis tipis rasio terpakai/total per akun, teal. Muncul konsisten di dashboard, daftar akun, dan indikator akun. Ini satu-satunya gesture visual yang berulang.

## Typography

**System font stack** (`system-ui, -apple-system, Segoe UI, Roboto, sans-serif`). Tanpa webfont. Alasan: utilitarian, load instan, identitas datang dari struktur dan hierarki, bukan font.

## Ikon

Hanya ikon semantik dari shadcn-vue/lucide (folder, file, upload, download, hapus, cloud, folder-plus, search). Tidak ada ikon dekoratif. Alasan relevansi per glyph: setiap ikon menamai aksi atau tipe objek nyata di UI.

## Radius & elevation

- Radius kecil konsisten (6px komponen input/button, 10px card/modal). Tidak ada pill untuk semua elemen.
- Shadow hanya penanda elevasi: dropdown/modal melayang di atas halaman; list, card, dan baris tabel flat dengan border tipis.

## Theme

Light default, toggle dark fungsional (kedua mode dijaga penuh, R-34).

## Bahasa UI

Indonesia. Label singkat, lugas, tanpa buzzword.

## Struktur layar

Setiap layar dibangun dari pekerjaannya (C-3), bukan template dashboard:

- **Dashboard**: satu pertanyaan, "kapasitasku total dan per akun bagaimana?" Total gabungan sebagai focal point, capacity bar per akun di bawahnya, banner peringatan akun >90% terpakai di atas jika ada.
- **Files**: file manager. Breadcrumb path, daftar file/folder, drag&drop overlay, panel progress upload saat ada job aktif, dropdown akun tujuan saat upload (default: otomatis), aksi per baris (download, pindah, hapus), search box.
- **Accounts**: daftar akun per provider dengan capacity bar + status, tombol connect per provider, wizard OTP Telegram dalam dialog, rename alias, disconnect dengan konfirmasi.

## States (wajib semua view data)

- **Empty**: menyebut sebab + satu aksi berikutnya (contoh: "Belum ada akun terhubung. Hubungkan Google Drive untuk mulai.")
- **Loading**: skeleton daftar atau spinner kontekstual dengan label.
- **Error**: sebab + aksi (contoh: "Kuota akun gagal dimuat. Coba lagi."), akun expired ditandai jelas dengan tombol reconnect.
