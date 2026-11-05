# UNIQA SUITES

Booking, sales and property operations system for UNIQA SUITES, Cagayan de Oro. Laravel 12, Inertia, React and TypeScript on XAMPP (PHP 8.2, MariaDB 10.4).

- [docs/SOURCE_AUDIT.md](docs/SOURCE_AUDIT.md): what the workbooks contain, conflicts found, decisions the owner must make
- [docs/SYSTEM_DESIGN.md](docs/SYSTEM_DESIGN.md): Excel-to-feature map, state flows, schema, money rules
- [docs/COMPLETION_CHECKLIST.md](docs/COMPLETION_CHECKLIST.md): what is done, partial or blocked

- [docs/STAFF_GUIDE.md](docs/STAFF_GUIDE.md): how each role uses the system
- [docs/CUTOVER.md](docs/CUTOVER.md): moving from Excel, going back if needed, and the acceptance run-through
- [docs/DATA_DICTIONARY.md](docs/DATA_DICTIONARY.md): every table and column

The public site lets guests browse the suites and see which dates are taken. **Book Now** opens a chat with the official Facebook page only for free dates, re-checked live (`UNIQA_MESSENGER_URL=https://m.me/uniqasuitescdo` in `.env`; without it the buttons stay hidden). It shows no prices, and guests cannot reserve or pay on it. Staff find vacant units and prices on the quotation screen, send the quote and enter the booking.

**Schedule conflicts** are stopped in layers: taken dates cannot be picked on the site; the server re-reads live inventory before Book Now unlocks; every staff action runs under a room lock that refuses any overlap; and `room_night_claims` has a unique key per room and night, so the database itself refuses a double booking. Holds count as taken until they expire. Other channels are the exception: Airbnb does not ask this system, so enter those stays promptly or they will not show. The dashboard and `uniqa:doctor` report any overlap.

## Verify the environment

```powershell
php -v                          # must be 8.2.x; check it is the XAMPP one with: where.exe php
composer check-platform-reqs
node -v; npm -v
& "$(Split-Path (Get-Command php).Source)\..\mysql\bin\mysqld.exe" --version
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
# set UNIQA_OWNER_EMAIL to the real owner's email
php artisan migrate --seed      # prints a generated Owner login once; save it. Can take minutes: do not interrupt it
php artisan storage:link
npm ci
npm run build
```

Seeding creates the nine rooms as **proposed and unpublished**, plus one Owner only when the users table is empty. It creates no demo guests, other staff, contact or Wi-Fi data. To publish, sign in, go to **Rooms**, then approve and publish each room. Invite each real staff member from **Team & Permissions**; never use the demo logins in production.

Photos, current rates, capacities and amenities come from the flyers and HD photo packs in `database/seeders/suite`, kept out of `public/` because the flyers show prices and staff phone numbers, and the flyers also set the weekend nights (Friday and Saturday) and the extra-guest fee (per night) unless already chosen under **Settings**. After adding a pack named like `Uniqa_Suites_Rio_All_4_HD.zip`, run `php artisan db:seed --class=SuiteCatalogSeeder`. It only changes details and rates on rooms not yet approved, keeps photos staff uploaded, and adds a dated rate rule instead of editing the old one.

### Run it

| Purpose | Command |
|---|---|
| App (development) | `php artisan serve`, then open http://127.0.0.1:8000 |
| Frontend with hot reload | `npm run dev` |
| Queue worker | `php artisan queue:work` |
| Scheduler | `php artisan schedule:work` |

XAMPP does not run the scheduler for you. Hold expiry, daily alerts, and month-end notices only happen while it is running, and the dashboard warns the owner if it has stopped. For a machine that should keep running, install it as a Windows scheduled task:

```powershell
.\scripts\install-scheduler.ps1                # from an Administrator window
.\scripts\install-scheduler.ps1 -SignedInOnly  # no Administrator needed; runs only while you are signed in
```

To check an installation is safe and running, use `php artisan uniqa:doctor`. In production any FAIL must be fixed before go-live.

### One-click sign-in (local only)

Set `DEMO=true` in `.env` and the login page offers one button per role, with no password. The Owner button opens your real Owner account; other roles get an account of their own, listed under **Team & Permissions**, without adding any demo data. **Anyone who can reach the page gets in**, so production refuses it even if left on, and `uniqa:doctor` fails. Turn it off with `DEMO=false`.

### Demo data (local only)

`php artisan db:seed --class=DemoSeeder` adds synthetic guests and bookings, publishes the catalog, sets a placeholder weekend rule and creates one login per role (`admin@`, `owner@`, `manager@`, `reception@`, `staff@uniqa-suites.test`, password `demo-password`). **It overwrites the Owner password and pricing settings. Never run it on real records.**

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

Add `127.0.0.1 uniqa-suites.test` to `C:\Windows\System32\drivers\etc\hosts` (as Administrator), set `APP_URL=http://uniqa-suites.test`, and restart Apache. Adjust the paths above to where XAMPP and this project actually live. This setup was tested with a separate Apache instance on another port, in production mode. It has not yet been installed into XAMPP's own Apache or tested over HTTPS.

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

Both scripts look for `mysql.exe` on `PATH`, then in `xampp\mysql\bin` on each drive; pass `-MysqlBin` if yours is elsewhere. Restore refuses a database that already has tables. `.env` is not in the backup: **keep a separate copy of `APP_KEY`, because Wi-Fi details and calendar URLs cannot be decrypted without it.** Copy backups off this PC. A single local XAMPP machine is not a backup or a production host.

## Two-factor sign-in

Each person turns it on under **Account**, using any authenticator app. Recovery codes are shown once. If someone loses their phone and their codes, an Owner can clear it under **Team & Permissions** after confirming who they are.

To make it mandatory for staff with finance, security or administrator access, tick the option under **Settings**. Anyone affected who has not enrolled is sent to **Account** until they do. Authenticator secrets are encrypted with `APP_KEY`, which is one more reason to keep a copy of that key.

## Production

Use PHP 8.2 hosting with a MySQL-compatible database and HTTPS. Set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `LOG_STACK=daily`, a limited database account and a real mail driver (invitations and password resets use mail; `log` only writes them to the log file). Run `npm run build` and `php artisan optimize`. Turn on two-factor sign-in for staff with sensitive access, then run `php artisan uniqa:doctor`. On Windows, use `scripts\install-scheduler.ps1` to run the scheduler every minute, and keep `php artisan queue:work` running under a supervisor such as NSSM. Set official links through the `UNIQA_*` values in `.env`.

## Troubleshooting

| Symptom | Check |
|---|---|
| `Access denied for user 'root'` | Password in `.env`. A password with `@` or `#` must be in double quotes. Test with `C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -u root -p` |
| Stale config or routes | `php artisan optimize:clear`. During development avoid `php artisan optimize`: it freezes `.env` values until cleared |
| Blank page or missing styles | `npm run build`, and delete `public/hot` if `npm run dev` crashed |
| Room photos missing | `php artisan storage:link` |
| Holds never expire, or no month-end notice | The scheduler is not running |
| `Table already exists` on migrate | An earlier migrate was interrupted, and MariaDB cannot roll back table creation. If the database holds nothing you need, run `php artisan migrate:fresh --seed`. **That erases every table** |
| Locked out of two-factor | Use a recovery code, or ask an Owner to reset it under Team & Permissions |
| Port 80 or 3306 in use | `Get-NetTCPConnection -LocalPort 3306`, then stop the other service rather than changing ports silently |
| Errors | `storage/logs/laravel.log` |
| Upload rejected | `upload_max_filesize` and `post_max_size` in `php.ini` (currently 40M; the app allows 50 MB for a workbook and 10 MB for evidence) |
