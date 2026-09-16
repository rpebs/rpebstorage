@echo off
setlocal
cd /d "%~dp0"

title rpebstorage Runtime Setup

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-runtime.ps1"

pause
