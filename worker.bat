@echo off
setlocal
cd /d "%~dp0"

set "PHP_BIN=php"
if exist "C:\xampp\php\php.exe" set "PHP_BIN=C:\xampp\php\php.exe"
where php >nul 2>nul && set "PHP_BIN=php"

echo Starting Last Year Project queue worker (high, default)...
"%PHP_BIN%" -d extension=zip artisan queue:work --queue=high,default --sleep=3 --tries=2
