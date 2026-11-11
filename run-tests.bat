@echo off
setlocal
cd /d "%~dp0"

set "PHP_BIN="
if exist "C:\xampp\php\php.exe" set "PHP_BIN=C:\xampp\php\php.exe"
if exist "C:\php-8.2.10\php.exe" set "PHP_BIN=C:\php-8.2.10\php.exe"
where php >nul 2>nul && set "PHP_BIN=php"

if "%PHP_BIN%"=="" (
    echo [ERROR] PHP executable not found.
    exit /b 1
)

echo Running full PHPUnit test suite (in-memory SQLite per phpunit.xml)...
"%PHP_BIN%" -d extension=zip vendor/phpunit/phpunit/phpunit %*
set "CODE=%ERRORLEVEL%"

echo.
if "%CODE%"=="0" (
    echo [PASS] PHPUnit test suite passed.
) else (
    echo [FAIL] PHPUnit test suite exited with code %CODE%.
)
exit /b %CODE%
