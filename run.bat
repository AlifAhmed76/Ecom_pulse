@echo off
setlocal
cd /d "%~dp0"

set "PHP_BIN=php"
if exist "C:\xampp\php\php.exe" set "PHP_BIN=C:\xampp\php\php.exe"
where php >nul 2>nul && set "PHP_BIN=php"

echo Launching Last Year Project background workers and web server...
start "LYP Scheduler" cmd /k call "%~dp0scheduler.bat"
start "LYP Worker (high,default)" cmd /k call "%~dp0worker.bat"
start "LYP Worker (ai)" cmd /k call "%~dp0worker-ai.bat"

echo Starting web server at http://127.0.0.1:8000
"%PHP_BIN%" -d extension=zip artisan serve --host=127.0.0.1 --port=8000
