@echo off
setlocal
cd /d "%~dp0"

set "PHP_BIN=php"
if exist "C:\xampp\php\php.exe" set "PHP_BIN=C:\xampp\php\php.exe"
where php >nul 2>nul && set "PHP_BIN=php"

echo Starting Last Year Project AI queue worker (ai)...
"%PHP_BIN%" -d extension=zip artisan queue:work --queue=ai --sleep=3 --tries=2 --timeout=180
