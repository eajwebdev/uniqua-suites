# Backs up the database and private uploaded evidence.
#
#   $env:MYSQL_PWD = "<password>"
#   .\scripts\backup.ps1 -Destination D:\uniqa-backups
#
# Keep the destination off this PC as well: a local XAMPP machine alone is not a backup.

param(
    [Parameter(Mandatory = $true)][string]$Destination,
    [string]$Database = 'db_uniquasuites',
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 3306,
    [string]$User = 'root',
    [string]$MysqlBin = ''
)

$ErrorActionPreference = 'Stop'
if (-not $MysqlBin) {
    # XAMPP is not always on C:. Prefer a client on PATH, then look at the usual install folders.
    $found = Get-Command mysql.exe -ErrorAction SilentlyContinue
    $candidates = @(if ($found) { Split-Path -Parent $found.Source }) + (Get-PSDrive -PSProvider FileSystem | ForEach-Object { "$($_.Root)xampp\mysql\bin" })
    $MysqlBin = $candidates | Where-Object { Test-Path (Join-Path $_ 'mysql.exe') } | Select-Object -First 1
    if (-not $MysqlBin) { throw 'Could not find mysql.exe. Pass its folder with -MysqlBin.' }
}
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$target = Join-Path $Destination "uniqa-$stamp"
New-Item -ItemType Directory -Force -Path $target | Out-Null

& "$MysqlBin\mysqldump.exe" "--host=$DbHost" "--port=$Port" "--user=$User" --single-transaction --routines --default-character-set=utf8mb4 "--result-file=$target\database.sql" $Database
if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE" }

$root = Split-Path -Parent $PSScriptRoot
$private = Join-Path $root 'storage\app\private'
$public = Join-Path $root 'storage\app\public'
foreach ($pair in @(@($private, 'private-files.zip'), @($public, 'public-files.zip'))) {
    if ((Get-ChildItem -Path $pair[0] -Recurse -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne '.gitignore' }).Count -gt 0) {
        Compress-Archive -Path "$($pair[0])\*" -DestinationPath (Join-Path $target $pair[1])
    }
}

$hashes = Get-ChildItem $target -File | Get-FileHash -Algorithm SHA256 | ForEach-Object { "$($_.Hash)  $(Split-Path -Leaf $_.Path)" }
$hashes | Out-File -Encoding utf8 (Join-Path $target 'SHA256SUMS.txt')
Write-Output "Backup written to $target"
