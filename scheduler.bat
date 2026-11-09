@echo off
setlocal
cd /d "%~dp0"

set "PHP_BIN=php"
if exist "C:\xampp\php\php.exe" set "PHP_BIN=C:\xampp\php\php.exe"
where php >nul 2>nul && set "PHP_BIN=php"

:loop
echo Starting Last Year Project scheduler...
"%PHP_BIN%" -d extension=zip artisan schedule:work
echo Scheduler exited with code %ERRORLEVEL%. Restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
