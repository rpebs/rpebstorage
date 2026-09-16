@echo off
setlocal

title rpebstorage Portable Stopper

cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop.ps1"
