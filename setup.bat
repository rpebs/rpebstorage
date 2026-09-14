@echo off
setlocal enabledelayedexpansion

title rpebstorage Initial Setup

echo ==========================================================
echo           rpebstorage - Windows Initial Setup
echo ==========================================================

rem 1. Periksa / prioritaskan PHP 8 Laragon jika PATH < 8.3
php -r "exit(PHP_VERSION_ID >= 80300 ? 0 : 1);" 2>nul
if errorlevel 1 (
    echo [!] PHP di system PATH belum PHP 8.3+. Mencari di folder Laragon...
    if exist "C:\laragon\bin\php\php-8.5.3\php.exe" (
        set "PATH=C:\laragon\bin\php\php-8.5.3;!PATH!"
        echo [+] Menggunakan PHP Laragon: C:\laragon\bin\php\php-8.5.3
    ) else if exist "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" (
        set "PATH=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;!PATH!"
        echo [+] Menggunakan PHP Laragon: C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64
    ) else (
        echo [X] Tidak dapat menemukan PHP 8.3+ di PATH maupun Laragon.
        pause
        exit /b 1
    )
)

rem 2. Composer install
if not exist "vendor" (
    echo.
    echo [+] Menjalankan composer install...
    call composer install
)

rem 3. File .env
if not exist ".env" (
    echo.
    echo [+] Membuat file .env dari .env.example...
    copy .env.example .env >nul
    call php artisan key:generate
)

rem 4. Database SQLite (default zero-config)
findstr /b /c:"DB_CONNECTION=sqlite" .env >nul
if not errorlevel 1 (
    if not exist "database\database.sqlite" (
        echo.
        echo [+] Membuat database\database.sqlite...
        type nul > database\database.sqlite
    )
)

rem 5. Migrate & seed
echo.
echo [+] Menjalankan migrasi database dan admin seeder...
call php artisan migrate --seed --force

rem 6. NPM install & build
if not exist "node_modules" (
    echo.
    echo [+] Menjalankan npm install...
    call npm install
)

echo.
echo [+] Menjalankan npm run build...
call npm run build

echo.
echo ==========================================================
echo  Setup selesai! 
echo  Untuk memulai aplikasi kapan saja, cukup jalankan:
echo    dev.bat   (atau: dev.ps1 / composer dev)
echo ==========================================================
pause
