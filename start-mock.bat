@echo off
REM Starts the TIP mock SOAP server (PHP built-in web server). Created: 2026-10-12.
REM Close this window (or press Ctrl+C) to stop it.
setlocal

set PORT=8089
set PHP_BIN=D:\laragon\bin\php\php-8.0.9-Win32-vs16-x64\php.exe
if not exist "%PHP_BIN%" set PHP_BIN=php

cd /d "%~dp0"
echo.
echo   TIP MOCK SOAP server
echo   WSDL: http://127.0.0.1:%PORT%/TipMobileWS/Service1.svc?wsdl
echo   Logs: %~dp0logs
echo.
"%PHP_BIN%" -S 127.0.0.1:%PORT% router.php
