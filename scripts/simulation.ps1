# Builds a separate simulation database, fills it with synthetic activity, and tries to break the
# booking rules. Your real database is never touched.
#
#   .\scripts\simulation.ps1          rebuild and run the scenarios
#   .\scripts\simulation.ps1 -Serve   then open the simulated system at http://127.0.0.1:8020
#   .\scripts\simulation.ps1 -Month   instead: 2 samples of every booking and cleaning status on 3 suites
#
# Sign in with owner@sim.test (also admin@, manager@, reception@, cleaner1@, cleaner2@), password simulation-2026.

param([switch]$Serve, [switch]$ServeOnly, [switch]$Month)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# A cached config makes Laravel ignore the simulation settings, so this run gets its own cache paths.
# Those files do not exist, which forces the settings to be read from .env.simulation.
$env:APP_ENV = 'simulation'
$env:APP_CONFIG_CACHE = 'bootstrap/cache/simulation-config.php'
$env:APP_ROUTES_CACHE = 'bootstrap/cache/simulation-routes.php'

$envFile = Join-Path $root '.env.simulation'
if (-not (Test-Path $envFile)) {
    $lines = Get-Content (Join-Path $root '.env') | Where-Object { $_ -notmatch '^(APP_ENV|APP_URL|DB_DATABASE|DEMO|MAIL_MAILER)=' }
    $lines += 'APP_ENV=simulation', 'APP_URL=http://127.0.0.1:8020', 'DB_DATABASE=db_uniquasuites_sim', 'DEMO=false', 'MAIL_MAILER=log'
    $utf8 = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllLines($envFile, [string[]]$lines, $utf8)
}

function Read-Setting([string]$name) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if ($line) { return ($line -replace "^$name=", '').Trim('"') }
    return ''
}

$database = Read-Setting 'DB_DATABASE'
if ($database -notmatch '_sim$') { throw "Refusing to run: DB_DATABASE is [$database], which does not end in _sim." }

if (-not $ServeOnly) {
    $found = Get-Command mysql.exe -ErrorAction SilentlyContinue
    $candidates = @(if ($found) { Split-Path -Parent $found.Source }) + (Get-PSDrive -PSProvider FileSystem | ForEach-Object { "$($_.Root)xampp\mysql\bin" })
    $mysqlBin = $candidates | Where-Object { Test-Path (Join-Path $_ 'mysql.exe') } | Select-Object -First 1
    if (-not $mysqlBin) { throw 'Could not find mysql.exe.' }

    $env:MYSQL_PWD = Read-Setting 'DB_PASSWORD'
    & (Join-Path $mysqlBin 'mysql.exe') "--host=$(Read-Setting 'DB_HOST')" "--port=$(Read-Setting 'DB_PORT')" "--user=$(Read-Setting 'DB_USERNAME')" -e "CREATE DATABASE IF NOT EXISTS ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the simulation database.' }
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue

    # This command only exists when the connected database really is the simulation one.
    php artisan uniqa:sim-guard
    if ($LASTEXITCODE -ne 0) { throw 'Stopped: the app is not connected to the simulation database. Nothing was changed.' }

    php artisan migrate:fresh --force
    if ($LASTEXITCODE -ne 0) { throw 'Migrations failed.' }

    if ($Month) {
        php artisan db:seed --class=MonthTestSeeder --force
        if ($LASTEXITCODE -ne 0) { throw 'Seeding failed.' }
    } else {
        php artisan db:seed --class=SimulationSeeder --force
        if ($LASTEXITCODE -ne 0) { throw 'Seeding failed.' }
        php artisan uniqa:simulate
        $scenarioResult = $LASTEXITCODE
        Write-Output "Scenarios exit code: $scenarioResult"

        # The scenarios leave test stays far in the future and check seeded guests out. Start clean for browsing.
        php artisan migrate:fresh --force | Out-Null
        php artisan db:seed --class=SimulationSeeder --force | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Reseeding failed.' }
    }
}

if ($Serve -or $ServeOnly) {
    php artisan uniqa:sim-guard
    if ($LASTEXITCODE -ne 0) { throw 'Stopped: the app is not connected to the simulation database.' }
    php artisan serve --port=8020
}
