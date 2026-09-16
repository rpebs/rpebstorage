@echo off
setlocal enabledelayedexpansion

title rpebstorage Portable Runner (Port 8123)

rem Pindah ke direktori script
cd /d "%~dp0"

rem Jalankan start.ps1 via PowerShell (Bypass execution policy)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start.ps1"

if %errorlevel% neq 0 (
    echo.
    echo [!] Server berhenti dengan errorlevel %errorlevel%.
    pause
)
