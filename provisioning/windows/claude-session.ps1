<#
.SYNOPSIS
  ControlPanel's Windows-side Claude session manager. Installed at
  C:\ProgramData\ControlPanel\bin\claude-session.ps1 and invoked over SSH by the
  mini-PC wrapper scripts (win-launch-claude.sh / win-list-claude.sh /
  win-end-claude.sh).

.DESCRIPTION
  Actions:
    prep-launch  Write the chosen model to a per-project sentinel, then fire the
                 interactive Scheduled Task LaunchClaudeSession_<project>.
                 (Runs over SSH in session 0 — can trigger, but not itself start
                 the GUI session.)
    launch       Runs ON THE INTERACTIVE DESKTOP via that Scheduled Task. Reads
                 the sentinel, starts `claude --remote-control <project> [--model
                 <str>]` in -Dir, and records a session marker.
    list         Print running sessions as a JSON array (prunes dead/stale
                 markers first). Read-only.
    end          Kill the session tree for -SessionPid, but ONLY if it is a
                 marker this script recorded and the process start time still
                 matches (defeats PID reuse). Then remove the marker.

  The model id -> real model string map below is the SECOND allowlist; it mirrors
  config/control_panel.php 'models'. Keep the two in sync when adding a model.
#>
[CmdletBinding()]
param(
    [ValidateSet('prep-launch', 'launch', 'list', 'end')]
    [string]$Action,
    [string]$Project = '',
    [string]$Model = 'default',
    [string]$Dir = '',
    [int]$SessionPid = 0
)

$ErrorActionPreference = 'Stop'

$Root = 'C:\ProgramData\ControlPanel'
$SessionsDir = Join-Path $Root 'sessions'
New-Item -ItemType Directory -Force -Path $SessionsDir | Out-Null

# id -> actual --model string. '' means "no --model flag" (account default).
# MIRRORS config/control_panel.php 'models'. This is the second allowlist.
$ModelMap = @{
    'default'  = ''
    'opus-4-8' = 'claude-opus-4-8'
    'sonnet-5' = 'claude-sonnet-5'
    'fable-5'  = 'claude-fable-5'
}

function Test-Token([string]$s) { return ($s -match '^[A-Za-z0-9_-]+$') }

function Get-MarkerPath([int]$ProcId) { return (Join-Path $SessionsDir "$ProcId.json") }

# A marker is "live" iff its PID is running AND the process start time matches
# what we recorded (so a recycled PID can never be listed or killed).
function Test-Marker($marker) {
    try {
        $p = Get-Process -Id ([int]$marker.pid) -ErrorAction Stop
        return ($p.StartTime.Ticks -eq [long]$marker.startTicks)
    } catch {
        return $false
    }
}

switch ($Action) {

    'prep-launch' {
        if (-not (Test-Token $Project)) { Write-Error "Invalid project '$Project'"; exit 2 }
        $mid = if (Test-Token $Model) { $Model } else { 'default' }
        if (-not $ModelMap.ContainsKey($mid)) { $mid = 'default' }

        Set-Content -Path (Join-Path $SessionsDir "next-model-$Project.txt") -Value $mid -Encoding Ascii
        & schtasks /run /tn "LaunchClaudeSession_$Project" | Out-Null
        exit $LASTEXITCODE
    }

    'launch' {
        # Runs on the interactive desktop (via the Scheduled Task).
        if (-not (Test-Token $Project)) { Write-Error "Invalid project '$Project'"; exit 2 }
        if ([string]::IsNullOrWhiteSpace($Dir) -or -not (Test-Path -LiteralPath $Dir)) {
            Write-Error "Missing or invalid -Dir for project '$Project'"; exit 2
        }

        # Read + consume the model sentinel written by prep-launch.
        $mid = 'default'
        $sentinel = Join-Path $SessionsDir "next-model-$Project.txt"
        if (Test-Path $sentinel) {
            $mid = (Get-Content $sentinel -Raw).Trim()
            Remove-Item $sentinel -Force -ErrorAction SilentlyContinue
        }
        if (-not $ModelMap.ContainsKey($mid)) { $mid = 'default' }
        $modelStr = $ModelMap[$mid]

        $args = @('--remote-control', $Project)
        if ($modelStr -ne '') { $args += @('--model', $modelStr) }

        # Launch via the .cmd shim, NOT bare 'claude'. Start-Process resolves a bare
        # name through ShellExecute, which finds claude.ps1 first and "opens" it in the
        # .ps1 file association (an editor) instead of running it. claude.cmd is a real
        # batch launcher and executes.
        $proc = Start-Process -FilePath 'claude.cmd' -ArgumentList $args -WorkingDirectory $Dir -PassThru

        $marker = [ordered]@{
            pid        = $proc.Id
            project    = $Project
            model      = $mid
            dir        = $Dir
            started    = (Get-Date).ToString('s')
            startTicks = $proc.StartTime.Ticks
        }
        $marker | ConvertTo-Json -Compress | Set-Content -Path (Get-MarkerPath $proc.Id) -Encoding Ascii
        exit 0
    }

    'list' {
        $out = @()
        Get-ChildItem -Path $SessionsDir -Filter '*.json' -ErrorAction SilentlyContinue | ForEach-Object {
            $marker = $null
            try { $marker = Get-Content $_.FullName -Raw | ConvertFrom-Json } catch { }
            if ($null -eq $marker) { Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue; return }

            if (Test-Marker $marker) {
                $out += [ordered]@{
                    pid     = [int]$marker.pid
                    project = [string]$marker.project
                    model   = [string]$marker.model
                    started = [string]$marker.started
                }
            } else {
                Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue  # dead/stale
            }
        }
        # Always emit a JSON array (even for 0/1 elements).
        Write-Output (ConvertTo-Json @($out) -Compress)
        exit 0
    }

    'end' {
        if ($SessionPid -le 0) { Write-Error 'Missing/invalid -SessionPid'; exit 2 }
        $markerPath = Get-MarkerPath $SessionPid
        if (-not (Test-Path $markerPath)) { Write-Error "No ControlPanel session for pid $SessionPid"; exit 3 }

        $marker = Get-Content $markerPath -Raw | ConvertFrom-Json
        if (-not (Test-Marker $marker)) {
            Remove-Item $markerPath -Force -ErrorAction SilentlyContinue
            Write-Error "Session pid $SessionPid is no longer running"; exit 3
        }

        # Kill the whole tree (claude spawns children). taskkill is best-effort.
        & taskkill /PID $SessionPid /T /F | Out-Null
        Remove-Item $markerPath -Force -ErrorAction SilentlyContinue
        Write-Output "Ended session pid $SessionPid ($($marker.project))."
        exit 0
    }

    default { Write-Error "Unknown action '$Action'"; exit 2 }
}
