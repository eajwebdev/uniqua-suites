# UNIQA SUITES

Booking, sales and property operations system for UNIQA SUITES, Cagayan de Oro. Laravel 12, Inertia, React and TypeScript on XAMPP (PHP 8.2, MariaDB 10.4).

- [docs/SOURCE_AUDIT.md](docs/SOURCE_AUDIT.md): what the workbooks contain, conflicts found, decisions the owner must make
- [docs/SYSTEM_DESIGN.md](docs/SYSTEM_DESIGN.md): Excel-to-feature map, state flows, schema, money rules
- [docs/COMPLETION_CHECKLIST.md](docs/COMPLETION_CHECKLIST.md): what is done, partial or blocked

The public site only takes inquiries. Guests cannot confirm a booking or pay on it.

## Verify the environment

```powershell
php -v                          # must be 8.2.x; check it is the XAMPP one with: where.exe php
composer check-platform-reqs
node -v; npm -v
C:\xampp\mysql\bin\mysqld.exe --version
```

This machine runs **MariaDB 10.4.32**, not MySQL 8, even though XAMPP labels it "MySQL". The code uses only SQL that works on it. The `intl` PHP extension is off and nothing here needs it.

## First run

Start **Apache** and **MySQL** in the XAMPP Control Panel. In phpMyAdmin or the `mysql` client:

```sql
CREATE DATABASE db_uniquasuites CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE db_uniquasuites_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

For anything beyond a personal dev machine, create a limited database account instead of using `root`.

```powershell
composer install
Copy-Item .env.example .env     # Command Prompt: copy .env.example .env
php artisan key:generate
# edit .env: DB_USERNAME and DB_PASSWORD; put a password containing @ in double quotes
php artisan migrate --seed      # prints a generated Owner login once; save it
php artisan storage:link
npm ci
npm run build
```

Seeding creates the nine rooms as **proposed and unpublished**, with no guest, staff, contact or Wi-Fi data. To publish, sign in, go to **Rooms**, then approve and publish each room. Pricing stays off until you choose the weekend days under **Settings**.

### Run it

| Purpose | Command |
|---|---|
| App (development) | `php artisan serve`, then open http://127.0.0.1:8000 |
| Frontend with hot reload | `npm run dev` |
| Queue worker | `php artisan queue:work` |
| Scheduler | `php artisan schedule:work` |

XAMPP does not run the scheduler or queue for you. Hold expiry, daily alerts and calendar sync only happen while the scheduler is running.

### Demo data (local only)

`php artisan db:seed --class=DemoSeeder` adds synthetic guests and bookings, publishes the catalog, sets a placeholder weekend rule and creates one login per role (`owner@`, `manager@`, `reception@`, `finance@`, `housekeeping@`, `maintenance@`, `payroll@`, `auditor@uniqa-suites.test`, password `demo-password`). **It overwrites the Owner password and pricing settings. Never run it on real records.**

## Apache virtual host

Point Apache at `public`, never the project root. In `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, with `mod_rewrite` enabled in `httpd.conf`:

```apache
<VirtualHost *:80>
    ServerName uniqa-suites.test
    DocumentRoot "C:/xampp/htdocs/uniqa-suites/public"
    <Directory "C:/xampp/htdocs/uniqa-suites/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add `127.0.0.1 uniqa-suites.test` to `C:\Windows\System32\drivers\etc\hosts` (as Administrator), set `APP_URL=http://uniqa-suites.test`, and restart Apache. The project currently lives in `C:\EAJ WEBDEV\uniqua-suites`; adjust the paths if you do not move it. This virtual host has not been exercised yet: development and testing used `php artisan serve`.

## Tests

Tests **wipe their database**. A cached config (`php artisan optimize` or `config:cache`) makes Laravel ignore `.env.testing`, which would aim them at the real database, so `tests/TestCase.php` refuses to start if the config is cached or the database name does not end in `_test`. Clear the cache first:

```powershell
Copy-Item .env.testing.example .env.testing   # then set APP_KEY and DB_PASSWORD
php artisan config:clear
php artisan test
```

They need a real MariaDB/MySQL server because the concurrency tests run separate OS processes on separate connections.

## Backup and restore

```powershell
$env:MYSQL_PWD = "<password>"
.\scripts\backup.ps1 -Destination D:\uniqa-backups
.\scripts\restore.ps1 -BackupPath D:\uniqa-backups\uniqa-YYYYMMDD-HHMMSS -Database db_uniquasuites_restore -RestoreFiles
```

Restore refuses a database that already has tables. `.env` is not in the backup: **keep a separate copy of `APP_KEY`, because Wi-Fi details and calendar URLs cannot be decrypted without it.** Copy backups off this PC. A single local XAMPP machine is not a backup or a production host.

## Production

Use PHP 8.2 hosting with a MySQL-compatible database and HTTPS. Set `APP_ENV=production`, `APP_DEBUG=false`, a limited database account and a real mail driver (invitations and password resets use mail; `log` only writes them to the log file). Run `npm run build` and `php artisan optimize`. On Windows, use Task Scheduler to run `php artisan schedule:run` every minute, and keep `php artisan queue:work` running under a supervisor such as NSSM. Set official links through the `UNIQA_*` values in `.env`.

## Troubleshooting

| Symptom | Check |
|---|---|
| `Access denied for user 'root'` | Password in `.env`. A password with `@` or `#` must be in double quotes. Test with `C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -u root -p` |
| Stale config or routes | `php artisan optimize:clear`. During development avoid `php artisan optimize`: it freezes `.env` values until cleared |
| Blank page or missing styles | `npm run build`, and delete `public/hot` if `npm run dev` crashed |
| Room photos missing | `php artisan storage:link` |
| Holds never expire | The scheduler is not running |
| Port 80 or 3306 in use | `Get-NetTCPConnection -LocalPort 3306`, then stop the other service rather than changing ports silently |
| Errors | `storage/logs/laravel.log` |
| Upload rejected | `upload_max_filesize` and `post_max_size` in `php.ini` (currently 40M; the app allows 50 MB for a workbook and 10 MB for evidence) |
