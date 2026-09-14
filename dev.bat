@echo off
setlocal enabledelayedexpansion

title rpebstorage Dev Server

echo ==========================================================
echo           rpebstorage - Windows Dev Runner
echo ==========================================================

rem 1. Periksa versi PHP di PATH sistem
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
    if exist "C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe" (
        echo [+] Menjalankan Redis Server di background...
        start "" /b "C:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe"
    )
)

echo.
echo [+] Menjalankan Server, Queue Worker, Vite, dan Telegram (jika aktif)...
echo     Akses web: http://127.0.0.1:8000
echo     Tekan Ctrl+C untuk menghentikan semua proses.
echo ==========================================================
echo.

call php artisan dev
