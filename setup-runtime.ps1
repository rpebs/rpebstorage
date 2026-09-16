# ==========================================================
# rpebstorage - Runtime Bundler / Setup Script
# ==========================================================
$ErrorActionPreference = "Stop"

$root = $PSScriptRoot
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "       Menyiapkan Portable Runtime (PHP & Redis)          " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$phpTarget = "$root\runtime\php"
$redisTarget = "$root\runtime\redis"

# 1. Pastikan folder runtime ada
New-Item -ItemType Directory -Path $phpTarget, $redisTarget -Force | Out-Null

# 2. Setup PHP
if (-not (Test-Path "$phpTarget\php.exe")) {
    Write-Host "[i] Mencari sumber PHP 8.x di komputer lokal..." -ForegroundColor Gray
    $sourcePhp = $null
    $candidates = @(
        "C:\laragon\bin\php\php-8.5.3",
        "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64"
    )
    foreach ($c in $candidates) {
        if (Test-Path "$c\php.exe") {
            $sourcePhp = $c
            break
        }
    }

    if ($sourcePhp) {
        Write-Host "[+] Menyalin PHP dari $sourcePhp ke runtime\php..." -ForegroundColor Green
        Copy-Item -Path "$sourcePhp\*" -Destination $phpTarget -Recurse -Force
        if (Test-Path "C:\laragon\etc\ssl\cacert.pem") {
            Copy-Item -Path "C:\laragon\etc\ssl\cacert.pem" -Destination "$phpTarget\cacert.pem" -Force
            Copy-Item -Path "C:\laragon\etc\ssl\cacert.pem" -Destination "$root\cacert.pem" -Force
        }
    } else {
        Write-Host "[!] PHP lokal tidak ditemukan. Mengunduh PHP 8.3 Win64 zip..." -ForegroundColor Yellow
        $phpZip = "$root\runtime\php.zip"
        $phpUrl = "https://windows.php.net/downloads/releases/archives/php-8.3.16-nts-Win32-vs16-x64.zip"
        Invoke-WebRequest -Uri $phpUrl -OutFile $phpZip -UseBasicParsing
        Expand-Archive -Path $phpZip -DestinationPath $phpTarget -Force
        Remove-Item $phpZip -Force
        Copy-Item "$phpTarget\php.ini-development" "$phpTarget\php.ini"
    }

    if (-not (Test-Path "$phpTarget\cacert.pem")) {
        if (Test-Path "$root\cacert.pem") {
            Copy-Item -Path "$root\cacert.pem" -Destination "$phpTarget\cacert.pem" -Force
        } else {
            Write-Host "[+] Mengunduh berkas CA bundle resmi (cacert.pem)..." -ForegroundColor Green
            try {
                Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile "$phpTarget\cacert.pem" -UseBasicParsing
                Copy-Item -Path "$phpTarget\cacert.pem" -Destination "$root\cacert.pem" -Force
            } catch {}
        }
    } else {
        if (-not (Test-Path "$root\cacert.pem")) {
            Copy-Item -Path "$phpTarget\cacert.pem" -Destination "$root\cacert.pem" -Force
        }
    }

    # Penyesuaian php.ini agar 100% portable
    $iniPath = "$phpTarget\php.ini"
    if (Test-Path $iniPath) {
        Write-Host "[+] Menyesuaikan php.ini untuk mode portable..." -ForegroundColor Green
        $caCertNorm = ("$phpTarget\cacert.pem" -replace '\\', '/')
        $ini = Get-Content $iniPath -Raw
        $ini = $ini -replace '(?m)^;?extension_dir\s*=.*$', 'extension_dir = "ext"'
        $ini = $ini -replace '(?m)^;?error_log\s*=.*$', ';error_log = php_errors.log'
        $ini = $ini -replace '(?m)^;?include_path\s*=.*$', ';include_path = ".;./pear"'
        $ini = $ini -replace '(?m)^;?sendmail_path\s*=.*$', ';sendmail_path ='
        $ini = $ini -replace '(?m)^;?session\.save_path\s*=.*$', ';session.save_path = "/tmp"'
        $ini = $ini -replace '(?m)^;?curl\.cainfo\s*=.*$', "curl.cainfo = `"$caCertNorm`""
        $ini = $ini -replace '(?m)^;?openssl\.cafile\s*=.*$', "openssl.cafile = `"$caCertNorm`""
        $ini = $ini -replace '(?m)^;?opcache\.enable\s*=.*$', 'opcache.enable=0'
        $ini = $ini -replace '(?m)^;?opcache\.enable_cli\s*=.*$', 'opcache.enable_cli=0'
        Set-Content -Path $iniPath -Value $ini
    }
} else {
    Write-Host "[+] PHP Portable sudah tersedia di runtime\php." -ForegroundColor Green
}

# 3. Setup Redis
if (-not (Test-Path "$redisTarget\redis-server.exe")) {
    $sourceRedis = "C:\laragon\bin\redis\redis-x64-5.0.14.1"
    if (Test-Path "$sourceRedis\redis-server.exe") {
        Write-Host "[+] Menyalin Redis dari $sourceRedis ke runtime\redis..." -ForegroundColor Green
        Copy-Item -Path "$sourceRedis\*" -Destination $redisTarget -Recurse -Force
    } else {
        Write-Host "[!] Redis lokal tidak ditemukan. Mengunduh Redis x64 portable..." -ForegroundColor Yellow
        $redisZip = "$root\runtime\redis.zip"
        $redisUrl = "https://github.com/tporadowski/redis/releases/download/v5.0.14.1/Redis-x64-5.0.14.1.zip"
        Invoke-WebRequest -Uri $redisUrl -OutFile $redisZip -UseBasicParsing
        Expand-Archive -Path $redisZip -DestinationPath $redisTarget -Force
        Remove-Item $redisZip -Force
    }
} else {
    Write-Host "[+] Redis Portable sudah tersedia di runtime\redis." -ForegroundColor Green
}

Write-Host "`n[+] Setup runtime selesai! Anda sekarang dapat menjalankan start.bat kapan saja." -ForegroundColor Cyan
