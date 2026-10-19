<#
.SYNOPSIS
    Registers the two Scheduled Tasks that bring Remote Control sessions back
    after a power cut (run once, as binar, no elevation).

.DESCRIPTION
    A power cut kills every local `claude` process, and the Claude app on the
    phone can't restart one (2026-10-14, 2026-10-19). With these two tasks the
    PC reopens them itself once it is back and auto-logon has signed in:

      ControlPanel_SnapshotSessions  every minute while signed in, windowless
                                     (conhost --headless): claude-session.ps1
                                     -Action snapshot records the Remote
                                     Control sessions open right now.
      ControlPanel_RestoreSessions   at sign-in: claude-session.ps1 -Action
                                     restore resumes the previous boot's last
                                     snapshot, but only when that boot ended
                                     unexpectedly (Kernel-Power 41 / 6008).

    Needs claude-session.ps1 installed at C:\ProgramData\ControlPanel\bin\.
    Re-running this script replaces both tasks.
#>
[CmdletBinding()]
param(
    [string] $Script = 'C:\ProgramData\ControlPanel\bin\claude-session.ps1',
    [int] $SnapshotMinutes = 1
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Script)) { throw "Install claude-session.ps1 at $Script first." }

$ps = "-NoProfile -ExecutionPolicy Bypass -File `"$Script`""
$every = New-TimeSpan -Minutes $SnapshotMinutes
# Interactive/Limited as the signed-in user, like LaunchClaudeSession_*: no
# stored password, and the scripts see this user's ~\.claude.
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

# Snapshot: at sign-in, then every minute. The -Once trigger starts the
# repetition now, without signing out first. conhost --headless keeps a
# console window from flashing up every minute.
$snapAction = New-ScheduledTaskAction -Execute 'conhost.exe' -Argument "--headless powershell.exe $ps -Action snapshot"
$atLogOn = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$atLogOn.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval $every).Repetition
$fromNow = New-ScheduledTaskTrigger -Once -At (Get-Date).AddSeconds(30) -RepetitionInterval $every
$snapSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew -StartWhenAvailable
Register-ScheduledTask -TaskName 'ControlPanel_SnapshotSessions' -Action $snapAction -Trigger @($atLogOn, $fromNow) `
    -Principal $principal -Settings $snapSettings -Force `
    -Description 'ControlPanel: every minute, record the open Claude Remote Control sessions (restored after a power cut).' | Out-Null

# Restore: once at sign-in. It waits up to 2 min for the network and staggers
# the sessions, so it gets a 10-minute limit.
$restoreAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-WindowStyle Hidden $ps -Action restore"
$restoreTrigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$restoreSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'ControlPanel_RestoreSessions' -Action $restoreAction -Trigger $restoreTrigger `
    -Principal $principal -Settings $restoreSettings -Force `
    -Description 'ControlPanel: after an unexpected shutdown, reopen the Claude Remote Control sessions that were open.' | Out-Null

Write-Host 'Registered ControlPanel_SnapshotSessions (every minute) and ControlPanel_RestoreSessions (at sign-in).'
