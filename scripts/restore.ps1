# Restores a backup made by backup.ps1 into a database. Refuses to overwrite one that already has tables.
#
#   $env:MYSQL_PWD = "<password>"
#   .\scripts\restore.ps1 -BackupPath D:\uniqa-backups\uniqa-20261104-120000 -Database db_uniquasuites_restore

param(
    [Parameter(Mandatory = $true)][string]$BackupPath,
    [Parameter(Mandatory = $true)][string]$Database,
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 3306,
    [string]$User = 'root',
    [string]$MysqlBin = '',
    [switch]$RestoreFiles
)

$ErrorActionPreference = 'Stop'
if (-not $MysqlBin) {
    # XAMPP is not always on C:. Prefer a client on PATH, then look at the usual install folders.
    $found = Get-Command mysql.exe -ErrorAction SilentlyContinue
    $candidates = @(if ($found) { Split-Path -Parent $found.Source }) + (Get-PSDrive -PSProvider FileSystem | ForEach-Object { "$($_.Root)xampp\mysql\bin" })
    $MysqlBin = $candidates | Where-Object { Test-Path (Join-Path $_ 'mysql.exe') } | Select-Object -First 1
    if (-not $MysqlBin) { throw 'Could not find mysql.exe. Pass its folder with -MysqlBin.' }
}
if ($Database -notmatch '^[A-Za-z0-9_]+$') { throw 'Invalid database name.' }
$mysql = "$MysqlBin\mysql.exe"
$connection = @("--host=$DbHost", "--port=$Port", "--user=$User")

& $mysql @connection -e "CREATE DATABASE IF NOT EXISTS ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$existing = & $mysql @connection -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database';"
if ([int]$existing -gt 0) { throw "Database $Database already has $existing tables. Restore into an empty database." }

$sql = (Join-Path $BackupPath 'database.sql') -replace '\\', '/'
& $mysql @connection --default-character-set=utf8mb4 $Database -e "source $sql"
if ($LASTEXITCODE -ne 0) { throw "Restore failed with exit code $LASTEXITCODE" }

if ($RestoreFiles) {
    $root = Split-Path -Parent $PSScriptRoot
    foreach ($pair in @(@('private-files.zip', 'storage\app\private'), @('public-files.zip', 'storage\app\public'))) {
        $zip = Join-Path $BackupPath $pair[0]
        if (Test-Path $zip) { Expand-Archive -Path $zip -DestinationPath (Join-Path $root $pair[1]) -Force }
    }
}

$count = & $mysql @connection -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database';"
Write-Output "Restored $count tables into $Database"
