# Creates the Windows scheduled task that runs Laravel's scheduler every minute.
# XAMPP does not do this itself: without it holds never expire and alerts never go out.
#
#   .\scripts\install-scheduler.ps1             # from an Administrator window: runs whether or not anyone is signed in
#   .\scripts\install-scheduler.ps1 -SignedInOnly   # no Administrator needed: runs only while you are signed in
#   .\scripts\install-scheduler.ps1 -Uninstall

param(
    [string]$TaskName = 'UNIQA SUITES Scheduler',
    [switch]$SignedInOnly,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Output "Removed scheduled task: $TaskName"
    return
}

$php = (Get-Command php.exe -ErrorAction SilentlyContinue).Source
if (-not $php) { throw 'php.exe is not on PATH. Add the XAMPP php folder to PATH first.' }
$root = Split-Path -Parent $PSScriptRoot

$action = New-ScheduledTaskAction -Execute $php -Argument "`"$root\artisan`" schedule:run" -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
$logonType = if ($SignedInOnly) { 'Interactive' } else { 'S4U' }
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType $logonType -RunLevel Limited

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Output "Scheduled task created: $TaskName ($logonType). Check it with: php artisan uniqa:doctor"
