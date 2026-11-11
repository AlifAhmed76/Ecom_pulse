# Deployment Notes: Redis, Apache (XAMPP) & PHP OPcache

By default (`DECISIONS.md`), **Last Year Project** runs offline on Windows XAMPP using `CACHE_STORE=file`, `SESSION_DRIVER=file`, and `QUEUE_CONNECTION=database` so zero external services beyond PHP 8.2+ and MySQL/MariaDB are required. When moving to a faster local demo or multi-process server, you can enable Redis, Apache VirtualHost, and PHP OPcache using `.env` and runtime config changes only.

---

## 1. Optional Redis (`docker-compose.redis.yml`)

1. Start the Redis container bundled at the project root:
   ```bat
   docker compose -f docker-compose.redis.yml up -d
   ```
   This binds Redis 7 to `127.0.0.1:6379` with AOF persistence (`redis-cli ping` healthcheck).

2. Ensure PHP has either the `phpredis` extension or `predis/predis` installed, then switch `.env`:
   ```env
   CACHE_STORE=redis
   SESSION_DRIVER=redis
   QUEUE_CONNECTION=redis
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379
   ```
3. Clear cached configuration and restart queue workers:
   ```bat
   php -d extension=zip artisan optimize:clear
   ```
   *(All `TenantCache` keys use versioned tenant prefixes `t{tenantId}:v{version}:...` and never flush other tenants.)*

---

## 2. Apache VirtualHost (XAMPP)

Never expose the repository root (`.env`, `storage/`, `vendor/`) over HTTP. Always point Apache's `DocumentRoot` strictly to `public/`.

Example `httpd-vhosts.conf` entry:
```apache
<VirtualHost *:80>
    ServerName ecompulse.local
    DocumentRoot "C:/xampp/htdocs/EcomPulse/public"

    <Directory "C:/xampp/htdocs/EcomPulse/public">
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    ErrorLog "logs/ecompulse-error.log"
    CustomLog "logs/ecompulse-access.log" common
</VirtualHost>
```

Checklist:
- Verify `mod_rewrite` is enabled in `httpd.conf` (`LoadModule rewrite_module modules/mod_rewrite.so`).
- When serving over HTTPS in production, set `APP_URL=https://...` and `SESSION_SECURE_COOKIE=true` in `.env`.
- Do not serve `storage/app/private` through web server aliases; uploaded CSV/XLSX/XML imports and tenant export ZIPs are accessed only via authenticated controllers.

---

## 3. PHP `php.ini` & OPcache Settings

In `C:\php-8.2.10\php.ini` or `C:\xampp\php\php.ini`:

1. Enable required extensions persistently so bare `php artisan test` and Artisan subprocesses work without `-d extension=zip`:
   ```ini
   extension=zip
   extension=mbstring
   extension=pdo_mysql
   extension=pdo_sqlite
   extension=openssl
   extension=curl
   extension=fileinfo
   ```

2. Recommended PHP OPcache settings (`php.ini`):
   ```ini
   zend_extension=opcache
   opcache.enable=1
   opcache.enable_cli=0
   opcache.memory_consumption=128
   opcache.interned_strings_buffer=16
   opcache.max_accelerated_files=10000
   opcache.revalidate_freq=2
   ; Set opcache.validate_timestamps=0 in immutable production builds (restart Apache/PHP-FPM after deploys)
   opcache.validate_timestamps=1
   ```
   *(On Windows PHP, `zend_extension=opcache` points to `php_opcache.dll` inside `ext/`.)*
