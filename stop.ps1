# ==========================================================
# rpebstorage - Windows Portable Stopper
# ==========================================================
$ErrorActionPreference = "SilentlyContinue"

try {
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
} catch {}

$root = $PSScriptRoot
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "         Menghentikan Layanan rpebstorage...              " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$stoppedCount = 0

# 1. Hentikan proses yang menggunakan Port 8123
try {
    $connections = Get-NetTCPConnection -LocalPort 8123 -ErrorAction SilentlyContinue
    if ($connections) {
        $pids = $connections | Select-Object -ExpandProperty OwningProcess -Unique
        foreach ($p in $pids) {
            if ($p -and $p -ne $PID) {
                Write-Host "[+] Menghentikan Web Server (PID: $p)..." -ForegroundColor Green
                Stop-Process -Id $p -Force -ErrorAction SilentlyContinue
                $stoppedCount++
            }
        }
    }
} catch {}

# 2. Hentikan semua PHP artisan / rpebstorage workers (portable maupun Laragon)
try {
    $allPhp = Get-CimInstance Win32_Process -Filter "name = 'php.exe'" -ErrorAction SilentlyContinue
    foreach ($p in $allPhp) {
        if ($p.ProcessId -eq $PID) { continue }
        $cmd = $p.CommandLine
        $path = $p.ExecutablePath

        $isRpebs = $false
        if ($path -and $path.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)) {
            $isRpebs = $true
        } elseif ($cmd -and ($cmd.IndexOf($root, [System.StringComparison]::OrdinalIgnoreCase) -ge 0)) {
            $isRpebs = $true
        } elseif ($cmd -and ($cmd -match "telegram:listen")) {
            $isRpebs = $true
        } elseif ($cmd -and ($cmd -match "artisan\s+(queue:listen|queue:work|serve)") -and ($path -like "*php-8.5*" -or $cmd -like "*rpebstorage*")) {
            $isRpebs = $true
        }

        if ($isRpebs) {
            Write-Host "[+] Menghentikan PHP process (PID: $($p.ProcessId))..." -ForegroundColor Green
            Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
            $stoppedCount++
        }
    }
} catch {}

# 3. Hentikan Redis Portable jika berjalan dari folder runtime
$redisProcs = Get-Process redis-server -ErrorAction SilentlyContinue | Where-Object {
    $_.Path -and ($_.Path.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase))
}
foreach ($rProc in $redisProcs) {
    Write-Host "[+] Menghentikan Redis Portable (PID: $($rProc.Id))..." -ForegroundColor Green
    Stop-Process -Id $rProc.Id -Force -ErrorAction SilentlyContinue
    $stoppedCount++
}

# 4. Bersihkan lock file Telegram
try {
    Get-ChildItem -Path "$root\storage\app\telegram-sessions\*\lock" -Recurse -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
} catch {}

Write-Host "`n[+] Selesai! $stoppedCount proses rpebstorage telah dihentikan." -ForegroundColor Cyan
Start-Sleep -Seconds 1
