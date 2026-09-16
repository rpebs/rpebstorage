@echo off
setlocal enabledelayedexpansion

title rpebstorage Dev Server

echo ==========================================================
echo           rpebstorage - Windows Dev Runner
echo ==========================================================

rem 1. Periksa PHP (utamakan runtime portable, lalu PATH, lalu Laragon)
if exist "%~dp0runtime\php\php.exe" (
    set "PATH=%~dp0runtime\php;!PATH!"
    set "PHPRC=%~dp0runtime\php"
    if exist "%~dp0runtime\php\cacert.pem" (
        set "SSL_CERT_FILE=%~dp0runtime\php\cacert.pem"
        set "CURL_CA_BUNDLE=%~dp0runtime\php\cacert.pem"
    )
    echo [+] Menggunakan PHP Portable: %~dp0runtime\php
) else (
    php -r "exit(PHP_VERSION_ID >= 80300 ? 0 : 1);" 2>nul
    if errorlevel 1 (
        echo [!] PHP di system PATH belum PHP 8.3+ atau belum terdaftar.
        echo [i] Mencari PHP 8.x di folder Laragon...
        
        if exist "C:\laragon\bin\php\php-8.5.3\php.exe" (
            set "PATH=C:\laragon\bin\php\php-8.5.3;!PATH!"
            echo [+] Menggunakan PHP Laragon: C:\laragon\bin\php\php-8.5.3
        ) else if exist "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" (
            set "PATH=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;!PATH!"
            echo [+] Menggunakan PHP Laragon: C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64
        ) else (
            echo [X] Tidak dapat menemukan PHP 8.3+ di PATH maupun Laragon.
            echo     Silakan install PHP 8.3+ atau atur path PHP di Laragon.
            pause
            exit /b 1
        )
    )
)

if not defined SSL_CERT_FILE (
    if exist "%~dp0cacert.pem" (
        set "SSL_CERT_FILE=%~dp0cacert.pem"
        set "CURL_CA_BUNDLE=%~dp0cacert.pem"
    ) else if exist "C:\laragon\etc\ssl\cacert.pem" (
        set "SSL_CERT_FILE=C:\laragon\etc\ssl\cacert.pem"
        set "CURL_CA_BUNDLE=C:\laragon\etc\ssl\cacert.pem"
    )
)

rem 2. Pastikan file .env ada
if not exist ".env" (
    echo [+] Menyiapkan .env dari .env.example...
    copy .env.example .env >nul
    call php artisan key:generate
)

rem 3. Jika menggunakan SQLite, pastikan database ada & termigrasi
findstr /b /c:"DB_CONNECTION=sqlite" .env >nul
if not errorlevel 1 (
    if not exist "database\database.sqlite" (
        echo [+] Membuat database\database.sqlite...
        type nul > database\database.sqlite
        echo [+] Menjalankan migrasi database dan seeder...
        call php artisan migrate --seed --force
    )
)

rem 4. Pastikan Redis Server berjalan (dibutuhkan jika menggunakan Telegram / Redis Queue)
tasklist /fi "imagename eq redis-server.exe" 2>nul | findstr /i "redis-server.exe" >nul
if errorlevel 1 (
    if exist "%~dp0runtime\redis\redis-server.exe" (
        echo [+] Menjalankan Redis Portable di background...
        start "" /b "%~dp0runtime\redis\redis-server.exe" "%~dp0runtime\redis\redis.windows.conf"
    ) else if exist "C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe" (
        echo [+] Menjalankan Redis Server Laragon di background...
        start "" /b "C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe"
    )
)

echo.
echo [+] Menjalankan Server, Queue Worker, Vite, dan Telegram (jika aktif)...
echo     Akses web: http://127.0.0.1:8123
echo     Tekan Ctrl+C untuk menghentikan semua proses.
echo ==========================================================
echo.

call php artisan dev
