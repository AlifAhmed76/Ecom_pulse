@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo ============================================================
echo Last Year Project - Windows / XAMPP Setup
echo ============================================================
echo.

REM 1. Locate PHP 8.2+
set "PHP_BIN="
if exist "C:\xampp\php\php.exe" set "PHP_BIN=C:\xampp\php\php.exe"
if exist "C:\php-8.2.10\php.exe" set "PHP_BIN=C:\php-8.2.10\php.exe"
where php >nul 2>nul && set "PHP_BIN=php"

if "%PHP_BIN%"=="" (
    echo [ERROR] PHP executable not found. Install PHP 8.2+ or XAMPP and add php.exe to PATH.
    exit /b 1
)

echo [1/7] Checking PHP version and extensions via %PHP_BIN%...
"%PHP_BIN%" -r "if (version_compare(PHP_VERSION, '8.2.0', '<')) { fwrite(STDERR, 'PHP 8.2+ required, found ' . PHP_VERSION . PHP_EOL); exit(1); } echo 'PHP ' . PHP_VERSION . ' OK' . PHP_EOL;"
if errorlevel 1 exit /b 1

"%PHP_BIN%" -d extension=zip -r "$req = ['mbstring','pdo_mysql','pdo_sqlite','zip','xmlreader','dom','fileinfo','openssl','curl','zlib']; $miss = []; foreach ($req as $e) { if (!extension_loaded($e)) { $miss[] = $e; } } if ($miss !== []) { fwrite(STDERR, 'Missing PHP extensions: ' . implode(', ', $miss) . PHP_EOL); exit(1); } echo 'All required PHP extensions loaded OK.' . PHP_EOL;"
if errorlevel 1 exit /b 1

set "COMPOSER_ZIP_FLAG="
"%PHP_BIN%" -r "exit(extension_loaded('zip') ? 0 : 1);"
if errorlevel 1 (
    echo [NOTE] extension=zip is not enabled in php.ini by default; use run.bat / run-tests.bat or uncomment extension=zip in php.ini.
    set "COMPOSER_ZIP_FLAG=--ignore-platform-req=ext-zip"
)

REM 2. Ensure required runtime directories exist
echo [2/7] Ensuring storage and cache directories exist...
if not exist "storage\app\private" mkdir "storage\app\private"
if not exist "storage\app\public" mkdir "storage\app\public"
if not exist "storage\framework\cache\data" mkdir "storage\framework\cache\data"
if not exist "storage\framework\sessions" mkdir "storage\framework\sessions"
if not exist "storage\framework\views" mkdir "storage\framework\views"
if not exist "storage\logs" mkdir "storage\logs"
if not exist "bootstrap\cache" mkdir "bootstrap\cache"

REM 3. Check Composer and install dependencies
echo [3/7] Checking Composer and dependencies...
where composer >nul 2>nul
if errorlevel 1 (
    if exist "vendor\autoload.php" (
        echo [WARN] composer command not found, continuing with existing vendor\ directory.
    ) else (
        echo [ERROR] Composer not found and vendor\autoload.php is missing. Install Composer first.
        exit /b 1
    )
) else (
    call composer install --no-interaction --prefer-dist %COMPOSER_ZIP_FLAG%
    if errorlevel 1 (
        echo [ERROR] composer install failed.
        exit /b 1
    )
)

REM 4. Create .env and APP_KEY if missing
echo [4/7] Configuring .env...
if not exist ".env" (
    copy ".env.example" ".env" >nul
    echo Created .env from .env.example
) else (
    echo .env already exists; keeping existing configuration.
)

"%PHP_BIN%" -r "$env = file_get_contents('.env'); if (!preg_match('/^APP_KEY=base64:.+/m', $env)) { exit(2); }"
if errorlevel 2 (
    echo Generating application key...
    "%PHP_BIN%" -d extension=zip artisan key:generate --ansi --force
    if errorlevel 1 exit /b 1
) else (
    echo APP_KEY already configured.
)

REM 5. Check MySQL connection and ensure database exists
echo [5/7] Checking MySQL / MariaDB connection and creating database...
"%PHP_BIN%" -r "$trim = ' ' . chr(9) . chr(10) . chr(13) . chr(0) . chr(11) . chr(34) . chr(39); $vars = []; foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) { if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue; [$k, $v] = explode('=', $line, 2); $vars[trim($k)] = trim($v, $trim); } $conn = $vars['DB_CONNECTION'] ?? 'mysql'; if ($conn !== 'mysql' && $conn !== 'mariadb') { echo 'DB_CONNECTION is ' . $conn . ', skipping MySQL DB create.' . PHP_EOL; exit(0); } $host = $vars['DB_HOST'] ?? '127.0.0.1'; $port = $vars['DB_PORT'] ?? '3306'; $db = $vars['DB_DATABASE'] ?? 'lastyear'; $user = $vars['DB_USERNAME'] ?? 'root'; $pass = $vars['DB_PASSWORD'] ?? ''; if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) { fwrite(STDERR, 'Invalid DB_DATABASE name in .env' . PHP_EOL); exit(1); } try { $pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]); $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); echo 'Database ' . $db . ' on ' . $host . ':' . $port . ' ready.' . PHP_EOL; } catch (Throwable $e) { fwrite(STDERR, 'MySQL connection failed (' . $host . ':' . $port . '): ' . $e->getMessage() . PHP_EOL); fwrite(STDERR, 'Please open XAMPP Control Panel, click Start on MySQL, and re-run setup.bat.' . PHP_EOL); exit(1); }"
if errorlevel 1 exit /b 1

REM 6. Run migrations and idempotent seeder
echo [6/7] Running database migrations and seeders...
"%PHP_BIN%" -d extension=zip artisan migrate --force
if errorlevel 1 exit /b 1

"%PHP_BIN%" -d extension=zip artisan db:seed --force
if errorlevel 1 exit /b 1

REM 7. Clear stale caches and compile Blade templates
echo [7/7] Optimizing runtime caches...
"%PHP_BIN%" -d extension=zip artisan optimize:clear
"%PHP_BIN%" -d extension=zip artisan view:cache
if errorlevel 1 exit /b 1

echo.
echo ============================================================
echo Setup completed cleanly!
echo Next steps:
echo   1. Run tests : call run-tests.bat
echo   2. Start app : call run.bat
echo   3. Open URL  : http://127.0.0.1:8000
echo   Demo logins  : owner@demostore.test / DemoStore123
echo                  analyst@demostore.test / DemoStore123
echo ============================================================
exit /b 0
