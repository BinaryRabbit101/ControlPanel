<#
.SYNOPSIS
    Registers the ControlPanel_LockAfterAutoLogon Scheduled Task on the Windows PC (run once, as binar).

.DESCRIPTION
    win.launch-claude opens sessions on the signed-in desktop, so after a power
    cut (PC woken by win.wake, sitting at the sign-in screen) nothing could start
    until someone signed in at the keyboard (2026-09-29). The fix is Windows
    auto-logon (Sysinternals Autologon), and this task is its safety half: at
    sign-in, if the PC booted less than $WindowSeconds ago, lock the workstation.

    The session stays signed in behind the lock screen, so LaunchClaudeSession_*
    tasks still run; anyone at the keyboard needs the PIN. A normal sign-in later
    than the window is left alone. Re-running this script replaces the task.
#>
[CmdletBinding()]
param(
    [string] $TaskName = 'ControlPanel_LockAfterAutoLogon',
    [int] $WindowSeconds = 120
)

$ErrorActionPreference = 'Stop'

$command = "if (((Get-Date) - (Get-CimInstance Win32_OperatingSystem).LastBootUpTime).TotalSeconds -lt $WindowSeconds) { rundll32.exe user32.dll,LockWorkStation }"
$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -Command `"$command`""

# Locking is per-session, so this runs as the signing-in user in their own
# interactive session (like LaunchClaudeSession_*), no stored password.
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
    -Description "ControlPanel: lock the desktop when auto-logon signs in within ${WindowSeconds}s of boot." -Force | Out-Null

Write-Host "Registered scheduled task '$TaskName' (locks at sign-in within ${WindowSeconds}s of boot)."
