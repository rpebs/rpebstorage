# ==========================================================
# rpebstorage - Windows Portable Zero-Install Runner
# ==========================================================
$ErrorActionPreference = "Continue"

try {
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
} catch {}

$Host.UI.RawUI.WindowTitle = "rpebstorage Portable Runner (Port 8123)"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "       rpebstorage - Windows Portable Zero-Install        " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$root = $PSScriptRoot
Set-Location $root

# Fungsi pembersihan proses dan lock rpebstorage
function Clean-RpebstorageProcesses {
    param([string]$repoPath)

    # 1. Hentikan proses yang menempati Port 8123
    try {
        $staleConnections = Get-NetTCPConnection -LocalPort 8123 -ErrorAction SilentlyContinue
        if ($staleConnections) {
            $pids = $staleConnections | Select-Object -ExpandProperty OwningProcess -Unique
            foreach ($p in $pids) {
                if ($p -and $p -ne $PID) {
                    Write-Host "[i] Menghentikan proses port 8123 (PID: $p)..." -ForegroundColor Yellow
                    Stop-Process -Id $p -Force -ErrorAction SilentlyContinue
                }
            }
        }
    } catch {}

    # 2. Hentikan PHP background worker rpebstorage yang masih aktif
    try {
        $allPhp = Get-CimInstance Win32_Process -Filter "name = 'php.exe'" -ErrorAction SilentlyContinue
        foreach ($p in $allPhp) {
            if ($p.ProcessId -eq $PID) { continue }
            $cmd = $p.CommandLine
            $path = $p.ExecutablePath

            $isRpebs = $false
            if ($path -and $path.StartsWith($repoPath, [System.StringComparison]::OrdinalIgnoreCase)) {
                $isRpebs = $true
            } elseif ($cmd -and ($cmd.IndexOf($repoPath, [System.StringComparison]::OrdinalIgnoreCase) -ge 0)) {
                $isRpebs = $true
            } elseif ($cmd -and ($cmd -match "telegram:listen")) {
                $isRpebs = $true
            } elseif ($cmd -and ($cmd -match "artisan\s+(queue:listen|queue:work)") -and ($path -like "*php-8.5*" -or $cmd -like "*rpebstorage*")) {
                $isRpebs = $true
            }

            if ($isRpebs) {
                Write-Host "[i] Menghentikan PHP worker rpebstorage lama (PID: $($p.ProcessId))..." -ForegroundColor Yellow
                Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
            }
        }
    } catch {}

    # 3. Bersihkan file lock MadelineProto yang tertinggal
    try {
        Get-ChildItem -Path "$repoPath\storage\app\telegram-sessions\*\lock" -Recurse -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
    } catch {}
}

# Jalankan pembersihan awal sebelum mulai
Clean-RpebstorageProcesses -repoPath $root

# 1. Deteksi PHP Portable
$phpExe = ""
$phpDir = ""

if (Test-Path "$root\runtime\php\php.exe") {
    $phpExe = "$root\runtime\php\php.exe"
    $phpDir = "$root\runtime\php"
    Write-Host "[+] Runtime PHP: Portable ($phpDir)" -ForegroundColor Green
} elseif (Test-Path "$root\bin\php\php.exe") {
    $phpExe = "$root\bin\php\php.exe"
    $phpDir = "$root\bin\php"
    Write-Host "[+] Runtime PHP: Portable ($phpDir)" -ForegroundColor Green
} else {
    Write-Host "[!] PHP portable tidak ditemukan di folder runtime\php." -ForegroundColor Yellow
    Write-Host "[i] Mencari instalasi PHP di Laragon..." -ForegroundColor Gray
    
    $candidates = @(
        "C:\laragon\bin\php\php-8.5.3",
        "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64"
    )
    foreach ($c in $candidates) {
        if (Test-Path "$c\php.exe") {
            $phpExe = "$c\php.exe"
            $phpDir = $c
            Write-Host "[+] Menggunakan PHP Laragon: $phpDir" -ForegroundColor Green
            break
        }
    }

    if (-not $phpExe) {
        $sysPhpVer = try { (& php -r "echo PHP_VERSION_ID;" 2>$null) } catch { 0 }
        if ([int]$sysPhpVer -ge 80300) {
            $phpExe = (Get-Command php).Source
            $phpDir = Split-Path $phpExe
            Write-Host "[+] Menggunakan PHP System PATH: $phpExe" -ForegroundColor Green
        }
    }
}

if (-not $phpExe) {
    Write-Host "[X] Gagal menemukan PHP 8.3+!" -ForegroundColor Red
    Write-Host "    Pastikan folder runtime\php tersedia atau PHP terinstall di komputer." -ForegroundColor Red
    Read-Host "Tekan Enter untuk keluar"
    exit 1
}

# Konfigurasi Environment Variable untuk PHP & SSL
$env:PATH = "$phpDir;$env:PATH"
$env:PHPRC = $phpDir

$caCertPath = ""
if (Test-Path "$phpDir\cacert.pem") {
    $caCertPath = "$phpDir\cacert.pem"
} elseif (Test-Path "$root\cacert.pem") {
    $caCertPath = "$root\cacert.pem"
} elseif (Test-Path "C:\laragon\etc\ssl\cacert.pem") {
    $caCertPath = "C:\laragon\etc\ssl\cacert.pem"
}

if ($caCertPath) {
    if (-not (Test-Path "$root\cacert.pem")) {
        Copy-Item -Path $caCertPath -Destination "$root\cacert.pem" -Force
    }
    if ($phpDir -and (-not (Test-Path "$phpDir\cacert.pem"))) {
        Copy-Item -Path $caCertPath -Destination "$phpDir\cacert.pem" -Force
    }
    $effectiveCert = if ($phpDir -and (Test-Path "$phpDir\cacert.pem")) { "$phpDir\cacert.pem" } else { "$root\cacert.pem" }
    $env:SSL_CERT_FILE = $effectiveCert
    $env:CURL_CA_BUNDLE = $effectiveCert

    $iniPath = "$phpDir\php.ini"
    if (Test-Path $iniPath) {
        $caCertNormalized = ($effectiveCert -replace '\\', '/')
        $ini = Get-Content $iniPath -Raw
        $escapedCa = [regex]::Escape($caCertNormalized)
        if ($ini -notmatch "curl\.cainfo\s*=\s*`"$escapedCa`"") {
            $ini = $ini -replace '(?m)^;?curl\.cainfo\s*=.*$', "curl.cainfo = `"$caCertNormalized`""
            $ini = $ini -replace '(?m)^;?openssl\.cafile\s*=.*$', "openssl.cafile = `"$caCertNormalized`""
            Set-Content -Path $iniPath -Value $ini
        }
    }
}

# 2. Deteksi Redis Portable
$redisExe = ""
$redisDir = ""
$redisConf = ""

if (Test-Path "$root\runtime\redis\redis-server.exe") {
    $redisExe = "$root\runtime\redis\redis-server.exe"
    $redisDir = "$root\runtime\redis"
    $redisConf = "$root\runtime\redis\redis.windows.conf"
} elseif (Test-Path "C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe") {
    $redisExe = "C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe"
    $redisDir = "C:\laragon\bin\redis\redis-x64-5.0.14.1"
    $redisConf = "$redisDir\redis.windows.conf"
}

# 3. Bersihkan file public/hot (agar Laravel menggunakan pre-built assets di public/build)
if (Test-Path "$root\public\hot") {
    Remove-Item "$root\public\hot" -Force -ErrorAction SilentlyContinue
}

# 4. Pastikan .env tersedia
if (-not (Test-Path "$root\.env")) {
    Write-Host "[+] Menyiapkan file .env dari .env.example..." -ForegroundColor Cyan
    Copy-Item "$root\.env.example" "$root\.env"
    & $phpExe artisan key:generate --force
}

# 5. Setup Database SQLite
$envContent = Get-Content "$root\.env" -Raw
if ($envContent -match "DB_CONNECTION=sqlite") {
    if (-not (Test-Path "$root\database\database.sqlite")) {
        Write-Host "[+] Membuat database\database.sqlite..." -ForegroundColor Cyan
        New-Item -ItemType File -Path "$root\database\database.sqlite" -Force | Out-Null
        Write-Host "[+] Menjalankan migrasi database dan seeder..." -ForegroundColor Cyan
        & $phpExe artisan migrate --seed --force
    }
}

# 6. Pastikan direktori logs ada
if (-not (Test-Path "$root\storage\logs")) {
    New-Item -ItemType Directory -Path "$root\storage\logs" -Force | Out-Null
}

# 7. Jalankan Redis Server Portable jika belum aktif
$redisProcessStarted = $false
$redisRunning = (Get-Process redis-server -ErrorAction SilentlyContinue) -ne $null
if (-not $redisRunning) {
    if ($redisExe -and (Test-Path $redisExe)) {
        Write-Host "[+] Menyalakan Redis Portable di background..." -ForegroundColor Green
        if (Test-Path $redisConf) {
            $redisProc = Start-Process -FilePath $redisExe -ArgumentList "`"$redisConf`"" -WindowStyle Hidden -PassThru
        } else {
            $redisProc = Start-Process -FilePath $redisExe -WindowStyle Hidden -PassThru
        }
        $redisProcessStarted = $true
        Start-Sleep -Seconds 1
    } else {
        Write-Host "[!] Redis server tidak ditemukan. Layanan queue & telegram mungkin terbatas." -ForegroundColor Yellow
    }
} else {
    Write-Host "[+] Redis Server: Sudah aktif (Port 6379)" -ForegroundColor Green
}

# 8. Jalankan Queue Worker (Background, unbuffered output)
Write-Host "[+] Menyalakan Queue Worker di background..." -ForegroundColor Green
$queueLog = "$root\storage\logs\queue.log"
$queueProc = Start-Process -FilePath $phpExe `
    -WorkingDirectory $root `
    -ArgumentList @("-d", "output_buffering=0", "artisan", "queue:listen", "--tries=3") `
    -RedirectStandardOutput $queueLog `
    -RedirectStandardError "$root\storage\logs\queue_error.log" `
    -WindowStyle Hidden `
    -PassThru

# 9. Jalankan Telegram Daemon (jika kredensial terisi di .env)
$telegramProc = $null
$hasTelegramCreds = ($envContent -match "TELEGRAM_API_ID=\d+") -and ($envContent -match "TELEGRAM_API_HASH=[a-zA-Z0-9]+")
if ($hasTelegramCreds) {
    Write-Host "[+] Menyalakan Telegram Daemon (telegram:listen) di background..." -ForegroundColor Green
    $tgLog = "$root\storage\logs\telegram.log"
    $telegramProc = Start-Process -FilePath $phpExe `
        -WorkingDirectory $root `
        -ArgumentList @("-d", "output_buffering=0", "artisan", "telegram:listen") `
        -RedirectStandardOutput $tgLog `
        -RedirectStandardError "$root\storage\logs\telegram_error.log" `
        -WindowStyle Hidden `
        -PassThru
} else {
    Write-Host "[i] Telegram Daemon: Dilewati (TELEGRAM_API_ID belum diisi di .env)" -ForegroundColor Gray
}

# 10. Buka browser otomatis ke http://127.0.0.1:8123
Write-Host "`n[+] Membuka browser: http://127.0.0.1:8123" -ForegroundColor Cyan
Start-Process "http://127.0.0.1:8123"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   Web Server berjalan di: http://127.0.0.1:8123          " -ForegroundColor Green
Write-Host "   Queue log:    storage\logs\queue.log                   " -ForegroundColor Gray
if ($telegramProc) {
    Write-Host "   Telegram log: storage\logs\telegram.log                " -ForegroundColor Gray
}
Write-Host "   Tekan Ctrl+C atau jalankan stop.bat untuk berhenti.     " -ForegroundColor Yellow
Write-Host "==========================================================`n" -ForegroundColor Cyan

# 11. Jalankan Laravel Web Server di foreground & tangani clean shutdown saat Ctrl+C
try {
    & $phpExe artisan serve --host=127.0.0.1 --port=8123
} finally {
    Write-Host "`n[i] Menghentikan background workers..." -ForegroundColor Yellow
    if ($queueProc -and (-not $queueProc.HasExited)) {
        Stop-Process -Id $queueProc.Id -Force -ErrorAction SilentlyContinue
    }
    if ($telegramProc -and (-not $telegramProc.HasExited)) {
        Stop-Process -Id $telegramProc.Id -Force -ErrorAction SilentlyContinue
    }
    if ($redisProcessStarted -and $redisProc -and (-not $redisProc.HasExited)) {
        Write-Host "[i] Menghentikan Redis Portable..." -ForegroundColor Yellow
        Stop-Process -Id $redisProc.Id -Force -ErrorAction SilentlyContinue
    }
    Clean-RpebstorageProcesses -repoPath $root
    Write-Host "[+] Semua layanan berhasil dihentikan dengan aman." -ForegroundColor Green
}
