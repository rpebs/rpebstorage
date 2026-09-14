# rpebstorage - Windows PowerShell Dev Runner
$ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "           rpebstorage - Windows Dev Runner" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Periksa versi PHP di PATH
$phpVersion = try { (& php -r "echo PHP_VERSION_ID;" 2>$null) } catch { 0 }
if ([int]$phpVersion -lt 80300) {
    Write-Host "[!] PHP di system PATH masih versi lama / belum PHP 8.3+." -ForegroundColor Yellow
    Write-Host "[i] Mencari PHP 8.x di instalasi Laragon..." -ForegroundColor Gray
    
    $candidates = @(
        "C:\laragon\bin\php\php-8.5.3",
        "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64"
    )
    if (Test-Path "C:\laragon\bin\php") {
        $candidates += Get-ChildItem -Path "C:\laragon\bin\php\php-8*" -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName
    }

    $found = $false
    foreach ($dir in $candidates) {
        if (Test-Path "$dir\php.exe") {
            $env:PATH = "$dir;$env:PATH"
            Write-Host "[+] Berhasil mengaktifkan PHP Laragon: $dir" -ForegroundColor Green
            $found = $true
            break
        }
    }

    if (-not $found) {
        Write-Host "[X] Gagal menemukan PHP 8.3+ di Laragon atau PATH." -ForegroundColor Red
        Write-Host "    Silakan pastikan Laragon PHP 8.x sudah terinstall." -ForegroundColor Red
        exit 1
    }
}

# 2. Pastikan file .env ada
if (-not (Test-Path ".env")) {
    Write-Host "[+] Menyiapkan .env dari .env.example..." -ForegroundColor Cyan
    Copy-Item ".env.example" ".env"
    & php artisan key:generate
}

# 3. Setup SQLite otomatis jika DB_CONNECTION=sqlite
$envContent = Get-Content ".env" -Raw
if ($envContent -match "DB_CONNECTION=sqlite") {
    if (-not (Test-Path "database/database.sqlite")) {
        Write-Host "[+] Membuat database/database.sqlite..." -ForegroundColor Cyan
        New-Item -ItemType File -Path "database/database.sqlite" -Force | Out-Null
        Write-Host "[+] Menjalankan migrasi database dan seeder..." -ForegroundColor Cyan
        & php artisan migrate --seed --force
    }
}

# 4. Pastikan Redis Server berjalan (dibutuhkan jika menggunakan Telegram / Redis Queue)
if (-not (Get-Process redis-server -ErrorAction SilentlyContinue)) {
    $redisPath = "C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe"
    if (Test-Path $redisPath) {
        Write-Host "[+] Menjalankan Redis Server di background ($redisPath)..." -ForegroundColor Green
        Start-Process -FilePath $redisPath -WindowStyle Hidden
    }
}

Write-Host "`n[+] Menjalankan Server, Queue Worker, Vite, dan Telegram (jika aktif)..." -ForegroundColor Green
Write-Host "    Akses web: http://127.0.0.1:8000" -ForegroundColor Cyan
Write-Host "    Tekan Ctrl+C untuk menghentikan semua proses.`n" -ForegroundColor DarkGray

& php artisan dev
