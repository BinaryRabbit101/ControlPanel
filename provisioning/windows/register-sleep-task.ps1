<#
.SYNOPSIS
    Registers the ControlPanel_SleepPC Scheduled Task on the Windows PC (run once, elevated).

.DESCRIPTION
    win.sleep used to SSH in and call SetSuspendState directly. That call never
    returns — the PC suspends mid-SSH-session — so the wrapper hung until its
    20 s timeout and the dashboard/API saw a 500 instead of a result.

    Now the SSH command is just `schtasks /run /tn ControlPanel_SleepPC`, which
    returns immediately; this task waits 2 s so SSH can close cleanly, then
    sleeps the machine. Re-running this script replaces the task in place.

    It runs as SYSTEM, not as the logged-on user. After a power cut the PC boots
    to the sign-in screen with nobody logged on; an Interactive task then never
    starts (schtasks /run still says SUCCESS), so Sleep silently did nothing
    until someone signed in at the keyboard (2026-09-29). Registering a SYSTEM
    task needs an elevated shell — an SSH login as the admin user is one.
#>
[CmdletBinding()]
param(
    [string] $TaskName = 'ControlPanel_SleepPC',
    [int] $DelaySeconds = 2
)

$ErrorActionPreference = 'Stop'

$command = "Start-Sleep -Seconds $DelaySeconds; rundll32.exe powrprof.dll,SetSuspendState 0,1,0"
$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -Command `"$command`""

# SYSTEM, so it runs with or without a signed-in session; no stored password,
# never killed for being on battery.
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName -Action $action -Principal $principal -Settings $settings `
    -Description 'ControlPanel: put this PC to sleep (triggered over SSH by win.sleep).' -Force | Out-Null

Write-Host "Registered scheduled task '$TaskName' (delay ${DelaySeconds}s, then SetSuspendState)."
