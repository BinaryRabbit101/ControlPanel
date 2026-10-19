<#
.SYNOPSIS
  ControlPanel's Windows-side Claude session manager. Installed at
  C:\ProgramData\ControlPanel\bin\claude-session.ps1 and invoked over SSH by the
  mini-PC wrapper scripts (win-launch-claude.sh / win-list-claude.sh /
  win-end-claude.sh / win-recent-claude.sh).

.DESCRIPTION
  Actions:
    prep-launch  Write the chosen model (and optional -Resume session id) to
                 per-project sentinels, then fire the interactive Scheduled Task
                 LaunchClaudeSession_<project>. (Runs over SSH in session 0 - can
                 trigger, but not itself start the GUI session.)
    launch       Runs ON THE INTERACTIVE DESKTOP via that Scheduled Task. Reads
                 the sentinels, starts `claude --remote-control <project>` (or
                 `claude --resume <uuid> --remote-control` when resuming) with an
                 optional --model in -Dir, and records a session marker.
                 prep-launch -Resume <uuid> -Reconnect first ends the process
                 tree(s) whose command line resumes exactly that uuid (never any
                 other claude.exe), for a session whose app link dropped.
    list         Print running sessions as a JSON array (prunes dead/stale
                 markers first). Read-only.
    recent       Print the project's most recent sessions (running ones marked)
                 as a JSON array [{id,title,updated,name,remote,lastPrompt,
                 running,reconnectable}].
                 Read-only (apart from the session-name cache, see below).
                 Remote Control sessions only (a transcript with a
                 "bridgeSessionId"), here and in recent-all.
    recent-all   The same rows across EVERY project that has a
                 LaunchClaudeSession_<key> task (one call, newest 30 first),
                 each with its `project` key, wrapped as
                 {sessions:[...], skipped:<n>}: skipped = remote sessions in
                 transcript folders no launcher maps to (never listed, never
                 resumable from here). With -Resume <uuid> it returns
                 just that session's row (any age), so the box can learn which
                 project it belongs to. Read-only (apart from the caches).
    end          Kill the session tree for -SessionPid, but ONLY if it is a
                 marker this script recorded and the process start time still
                 matches (defeats PID reuse). Then remove the marker.
    snapshot     Record the Remote Control sessions open right now (from
                 ~/.claude/sessions/<pid>.json, live pids with a
                 bridgeSessionId) in open-sessions.json, stamped with this
                 boot. Runs every minute, windowless, via the Scheduled Task
                 ControlPanel_SnapshotSessions.
    restore      Runs ON THE INTERACTIVE DESKTOP at sign-in via
                 ControlPanel_RestoreSessions. When this boot followed an
                 unexpected shutdown (power cut, crash, held power button:
                 Kernel-Power 41 / EventLog 6008), resumes every session the
                 previous boot's last snapshot listed with
                 `claude --resume <uuid> --remote-control <name>` in its own
                 folder, and records markers so list/end see them. A normal
                 restart restores nothing. -DryRun logs what it would start
                 and starts nothing. Log: restore.log.

  The model id -> real model string map below is the SECOND allowlist; it mirrors
  config/control_panel.php 'models'. Keep the two in sync when adding a model.

  Sentinels: prep-launch runs elevated over SSH, so the files it creates are
  owned by Administrators and the Limited `launch` task can read but NOT delete
  them. So a sentinel is never trusted to have been consumed: prep-launch
  rewrites the resume sentinel on EVERY launch (empty when not resuming) and
  stamps it with the time, and launch ignores one older than two minutes.
#>
[CmdletBinding()]
param(
    [ValidateSet('prep-launch', 'launch', 'list', 'end', 'recent', 'recent-all', 'snapshot', 'restore')]
    [string]$Action,
    [string]$Project = '',
    [string]$Model = 'default',
    [string]$Dir = '',
    [int]$SessionPid = 0,
    [string]$Resume = '',
    [switch]$Reconnect,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

$Root = 'C:\ProgramData\ControlPanel'
$SessionsDir = Join-Path $Root 'sessions'
$NamesCache = Join-Path $Root 'session-names.json'
$TranscriptCache = Join-Path $Root 'transcript-cache.json'
$ClaudeHome = Join-Path $env:USERPROFILE '.claude'
$OpenSessions = Join-Path $Root 'open-sessions.json'
$RestorePending = Join-Path $Root 'restore-pending.json'
$RestoreClaimed = Join-Path $Root 'restore-claimed.json'
$RestoreLog = Join-Path $Root 'restore.log'
New-Item -ItemType Directory -Force -Path $SessionsDir | Out-Null

# id -> actual --model string. '' means "no --model flag" (account default).
# MIRRORS config/control_panel.php 'models'. This is the second allowlist.
$ModelMap = @{
    'default'  = ''
    'opus-4-8' = 'claude-opus-4-8'
    'sonnet-5' = 'claude-sonnet-5'
    'fable-5'  = 'claude-fable-5'
}

# A Claude session id. The resume id is an allowlist boundary: nothing else
# ever reaches the claude command line.
$UuidRe = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
$ResumeMaxAge = [TimeSpan]::FromMinutes(2)
$RecentLimit = 10
$RecentAllLimit = 30
$StubBytes = 5KB
# A snapshot older than this is never restored (the PC sat off for days).
$RestoreMaxAge = [TimeSpan]::FromHours(48)
$NetworkWait = [TimeSpan]::FromMinutes(2)
$RestoreStagger = 3

# One clean line on stderr and a meaningful exit code. (Write-Error under
# ErrorActionPreference=Stop throws instead: exit 1 plus a script-path prefix
# and position lines, which the dashboard and the phone would show verbatim.)
function Exit-With([string]$msg, [int]$code) {
    [Console]::Error.WriteLine($msg)
    exit $code
}

function Test-Token([string]$s) { return ($s -match '^[A-Za-z0-9_-]+$') }

function Test-Uuid([string]$s) { return ($s -cmatch $UuidRe) }

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

# The project's directory: -Dir when given, else the -Dir argument of its
# LaunchClaudeSession_<project> task (the box only knows project keys).
function Get-ProjectDir([string]$proj, [string]$given) {
    if (-not [string]::IsNullOrWhiteSpace($given)) { return $given }
    $task = Get-ScheduledTask -TaskName "LaunchClaudeSession_$proj" -ErrorAction SilentlyContinue
    if ($null -eq $task) { return $null }
    foreach ($a in @($task.Actions)) {
        $m = [regex]::Match([string]$a.Arguments, '-Dir\s+(?:"([^"]+)"|(\S+))')
        if ($m.Success) {
            if ($m.Groups[1].Success) { return $m.Groups[1].Value }
            return $m.Groups[2].Value
        }
    }
    return $null
}

# Claude keeps a project's transcripts in ~/.claude/projects/<dir with every
# non-alphanumeric character replaced by '-'>. The drive letter's case depends
# on how the session was started, so match case-insensitively and merge.
function Get-TranscriptDirs([string]$projDir) {
    $encoded = ($projDir.TrimEnd('\', '/')) -replace '[^A-Za-z0-9]', '-'
    $base = Join-Path $ClaudeHome 'projects'
    if (-not (Test-Path -LiteralPath $base)) { return @() }
    return @(Get-ChildItem -LiteralPath $base -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -ieq $encoded } | ForEach-Object { $_.FullName })
}

# Every launcher's transcript folder: encoded dir (lower-case) -> project key,
# from one Get-ScheduledTask call. When two keys share a folder the
# alphabetically first key wins, so the answer is stable.
function Get-LauncherFolders {
    $map = @{}
    $tasks = @(Get-ScheduledTask -TaskName 'LaunchClaudeSession_*' -ErrorAction SilentlyContinue |
        Sort-Object TaskName)
    foreach ($task in $tasks) {
        $key = ([string]$task.TaskName).Substring('LaunchClaudeSession_'.Length)
        if (-not (Test-Token $key)) { continue }
        foreach ($a in @($task.Actions)) {
            $m = [regex]::Match([string]$a.Arguments, '-Dir\s+(?:"([^"]+)"|(\S+))')
            if (-not $m.Success) { continue }
            $dir = if ($m.Groups[1].Success) { $m.Groups[1].Value } else { $m.Groups[2].Value }
            $enc = (($dir.TrimEnd('\', '/')) -replace '[^A-Za-z0-9]', '-').ToLower()
            if (-not $map.ContainsKey($enc)) { $map[$enc] = $key }
            break
        }
    }
    return $map
}

function Read-NamesCache {
    $map = @{}
    if (Test-Path -LiteralPath $NamesCache) {
        try {
            $obj = Get-Content -LiteralPath $NamesCache -Raw | ConvertFrom-Json
            foreach ($p in $obj.PSObject.Properties) { $map[$p.Name] = $p.Value }
        } catch { }
    }
    return $map
}

# Session ids that are running right now, from two places:
#   - claude.exe command lines carrying `--resume <id>`;
#   - ~/.claude/sessions/<pid>.json (live pids only), which also names the
#     session the way the Claude app shows it. Those names are remembered in
#     session-names.json so a session keeps its name after it dies.
#   `resumed` maps an id to the PIDs whose command line resumes exactly it:
#   the only processes Reconnect may ever end.
function Get-RunningSessionIds {
    $running = @{}
    $resumed = @{}
    $procs = @(Get-CimInstance Win32_Process -Filter "Name='claude.exe'" -ErrorAction SilentlyContinue)
    foreach ($p in $procs) {
        $m = [regex]::Match([string]$p.CommandLine, '--(?:resume|session-id)[\s=]+"?([0-9a-fA-F-]{36})')
        if ($m.Success) { $running[$m.Groups[1].Value.ToLower()] = $true }
        $r = [regex]::Match([string]$p.CommandLine, '--resume\s+"?([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})"?(?=\s|$)')
        if ($r.Success) {
            $rid = $r.Groups[1].Value.ToLower()
            if (-not $resumed.ContainsKey($rid)) { $resumed[$rid] = @() }
            $resumed[$rid] += [int]$p.ProcessId
        }
    }

    $names = Read-NamesCache
    $changed = $false
    $live = Join-Path $ClaudeHome 'sessions'
    if (Test-Path -LiteralPath $live) {
        foreach ($f in @(Get-ChildItem -LiteralPath $live -Filter '*.json' -ErrorAction SilentlyContinue)) {
            try {
                $s = Get-Content -LiteralPath $f.FullName -Raw | ConvertFrom-Json
                if ($null -eq (Get-Process -Id ([int]$s.pid) -ErrorAction SilentlyContinue)) { continue }
                $sid = ([string]$s.sessionId).ToLower()
                if (-not (Test-Uuid $sid)) { continue }
                $running[$sid] = $true
                $n = [string]$s.name
                if ($n -ne '') {
                    $prev = $names[$sid]
                    if ($null -eq $prev -or [string]$prev.name -ne $n) {
                        $names[$sid] = [ordered]@{ name = $n; seen = (Get-Date).ToUniversalTime().ToString('o') }
                        $changed = $true
                    }
                }
            } catch { }
        }
    }

    if ($changed) {
        # Keep the newest 500 so the cache can never grow without bound.
        $keep = [ordered]@{}
        $names.GetEnumerator() | Sort-Object { [string]$_.Value.seen } -Descending |
            Select-Object -First 500 | ForEach-Object { $keep[$_.Key] = $_.Value }
        try { $keep | ConvertTo-Json -Compress | Set-Content -LiteralPath $NamesCache -Encoding Ascii } catch { }
    }

    return @{ running = $running; resumed = $resumed; names = $names }
}

# End every process tree whose command line resumes exactly $id (and nothing
# else), then wait up to 15 s for them to be gone. Returns $true when clear.
function Stop-ResumedSession([string]$id) {
    $pids = @(@((Get-RunningSessionIds).resumed[$id]) | Where-Object { $_ -gt 0 })
    foreach ($procId in $pids) {
        & taskkill /PID $procId /T /F 2>&1 | Out-Null
    }
    for ($i = 0; $i -lt 30; $i++) {
        $alive = @($pids | Where-Object { $null -ne (Get-Process -Id $_ -ErrorAction SilentlyContinue) })
        if ($alive.Count -eq 0) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

function Get-Clipped([string]$s, [int]$max) {
    $s = ($s -replace '\s+', ' ').Trim()
    if ($s.Length -le $max) { return $s }
    return $s.Substring(0, $max - 1).TrimEnd() + [char]0x2026
}

# Text of a user message (string or [{type:text,text}] content), or '' if it
# isn't something the owner typed.
function Get-UserText($entry) {
    if ($entry.isMeta -or $entry.isSidechain) { return '' }
    $c = $entry.message.content
    $text = ''
    if ($c -is [string]) {
        $text = $c
    } else {
        foreach ($part in @($c)) {
            if ($part.type -eq 'text' -and $part.text) { $text = [string]$part.text; break }
        }
    }
    $text = $text.Trim()
    if ($text -eq '' -or $text.StartsWith('<')) { return '' }
    return $text
}

# Stream a transcript line by line (they are multi-MB): only lines that look
# relevant are parsed. Title = last custom-title (an explicit /rename) else
# last ai-title, falling back to the first thing the owner typed.
function Read-Transcript([string]$path) {
    $info = @{ custom = ''; ai = ''; first = ''; last = ''; remote = $false }
    $fs = [System.IO.File]::Open($path, 'Open', 'Read', 'ReadWrite,Delete')
    $reader = New-Object System.IO.StreamReader($fs, [System.Text.Encoding]::UTF8)
    try {
        while ($null -ne ($line = $reader.ReadLine())) {
            if ($line.Length -gt 200000) { continue }
            try {
                if ($line.Contains('"type":"custom-title"')) {
                    $t = [string]($line | ConvertFrom-Json).customTitle
                    if ($t.Trim() -ne '') { $info.custom = $t }
                } elseif ($line.Contains('"type":"ai-title"')) {
                    $t = [string]($line | ConvertFrom-Json).aiTitle
                    if ($t.Trim() -ne '') { $info.ai = $t }
                } elseif ($line.Contains('"type":"last-prompt"')) {
                    $t = [string]($line | ConvertFrom-Json).lastPrompt
                    if ($t.Trim() -ne '') { $info.last = $t }
                } elseif (-not $info.remote -and $line.Contains('"bridgeSessionId":"')) {
                    $info.remote = $true
                } elseif ($info.first -eq '' -and $line.Contains('"type":"user"') -and $line.Length -lt 50000) {
                    $info.first = Get-UserText ($line | ConvertFrom-Json)
                }
            } catch { }
        }
    } finally {
        $reader.Dispose()
    }
    return $info
}

# Per-transcript facts, keyed by session id, in transcript-cache.json:
#   remote + plen  the Remote Control probe. Once a bridgeSessionId is seen the
#                  session is remote for good; otherwise plen is how far the
#                  file was scanned, and only what was appended since is read.
#   stamp + title fields  the full parse, reused while size+mtime match (an
#                  idle transcript never changes, so mostly only running ones
#                  are re-read).
# The newest 3000 entries are kept.
$script:TCache = $null
$script:TCacheDirty = $false

function Get-CacheEntry([string]$id) {
    if ($null -eq $script:TCache) {
        $script:TCache = @{}
        if (Test-Path -LiteralPath $TranscriptCache) {
            try {
                $obj = Get-Content -LiteralPath $TranscriptCache -Raw | ConvertFrom-Json
                foreach ($p in $obj.PSObject.Properties) {
                    $h = @{}
                    foreach ($q in $p.Value.PSObject.Properties) { $h[$q.Name] = $q.Value }
                    $script:TCache[$p.Name] = $h
                }
            } catch { }
        }
    }
    if (-not $script:TCache.ContainsKey($id)) { $script:TCache[$id] = @{ remote = $false; plen = [long]0 } }
    return $script:TCache[$id]
}

# Did this session ever have Remote Control on? A transcript records it as a
# `bridge-session` entry carrying "bridgeSessionId":"cse_...". Reading stops at
# the first match.
function Test-RemoteSession($file) {
    $e = Get-CacheEntry $file.BaseName.ToLower()
    if ([bool]$e.remote) { return $true }
    $start = [long]$e.plen
    if ($start -gt $file.Length) { $start = 0 }
    if ($start -eq $file.Length) { return $false }

    $found = $false
    $fs = [System.IO.File]::Open($file.FullName, 'Open', 'Read', 'ReadWrite,Delete')
    try {
        $fs.Seek($start, 'Begin') | Out-Null
        $reader = New-Object System.IO.StreamReader($fs, [System.Text.Encoding]::UTF8)
        while ($null -ne ($line = $reader.ReadLine())) {
            if ($line.Contains('"bridgeSessionId":"')) { $found = $true; break }
        }
    } finally {
        $fs.Dispose()
    }
    $e.remote = $found
    $e.plen = [long]$file.Length
    $e.seen = $file.LastWriteTimeUtc.ToString('o')
    $script:TCacheDirty = $true
    return $found
}

function Read-TranscriptCached($file) {
    $e = Get-CacheEntry $file.BaseName.ToLower()
    $stamp = "$($file.Length):$($file.LastWriteTimeUtc.Ticks)"
    if ([string]$e.stamp -eq $stamp) {
        return @{ custom = [string]$e.custom; ai = [string]$e.ai; first = [string]$e.first; last = [string]$e.last; remote = [bool]$e.remote }
    }
    $info = Read-Transcript $file.FullName
    $info.first = Get-Clipped $info.first 200
    $info.last = Get-Clipped $info.last 200
    $e.stamp = $stamp
    $e.seen = $file.LastWriteTimeUtc.ToString('o')
    $e.custom = $info.custom; $e.ai = $info.ai; $e.first = $info.first; $e.last = $info.last
    if ($info.remote) { $e.remote = $true }
    $script:TCacheDirty = $true
    return $info
}

function Save-TranscriptCache {
    if (-not $script:TCacheDirty) { return }
    $keep = [ordered]@{}
    $script:TCache.GetEnumerator() | Sort-Object { [string]$_.Value.seen } -Descending |
        Select-Object -First 3000 | ForEach-Object { $keep[$_.Key] = $_.Value }
    try { ConvertTo-AsciiJsonRaw $keep 3 | Set-Content -LiteralPath $TranscriptCache -Encoding Ascii } catch { }
}

# The transcripts a list can show: Remote Control sessions only, newest first.
# Running ones are always included; idle ones until $limit are found. Files
# are probed newest-first, so older ones are never opened once the list is
# full. $items = objects with a .file property.
function Select-RemoteSessions($items, $state, [int]$limit) {
    $out = New-Object System.Collections.Generic.List[object]
    $idle = 0
    foreach ($it in @($items | Sort-Object { $_.file.LastWriteTimeUtc } -Descending)) {
        $id = $it.file.BaseName.ToLower()
        $running = $state.running.ContainsKey($id)
        if (-not $running -and $idle -ge $limit) { continue }
        if (-not (Test-RemoteSession $it.file)) { continue }
        $out.Add($it)
        if (-not $running) { $idle++ }
    }
    return $out.ToArray()
}

# One output row for a transcript file ($project '' = per-project list).
function New-SessionRow($f, $state, [string]$project) {
    $id = $f.BaseName.ToLower()
    $info = Read-TranscriptCached $f
    $title = if ($info.custom -ne '') { $info.custom } elseif ($info.ai -ne '') { $info.ai } elseif ($info.first -ne '') { Get-Clipped $info.first 60 } else { 'Untitled session' }
    $cached = $state.names[$id]
    $row = [ordered]@{
        id         = $id
        title      = Get-Clipped $title 100
        updated    = $f.LastWriteTimeUtc.ToString('yyyy-MM-ddTHH:mm:ssZ')
        name       = if ($null -ne $cached) { Get-Clipped ([string]$cached.name) 60 } else { '' }
        remote     = $true
        lastPrompt = Get-Clipped $info.last 120
        running    = [bool]$state.running.ContainsKey($id)
        # Only a process that resumed exactly this id may be ended.
        reconnectable = [bool]$state.resumed.ContainsKey($id)
    }
    if ($project -ne '') { $row.project = $project }
    return $row
}

# JSON with every non-ASCII character escaped, so titles survive the SSH hop
# whatever the console code page is.
function ConvertTo-AsciiJsonRaw($value, [int]$depth) {
    $json = ConvertTo-Json $value -Compress -Depth $depth
    return [regex]::Replace($json, '[^\x00-\x7F]', { param($m) '\u{0:x4}' -f [int][char]$m.Value })
}

function ConvertTo-AsciiJson($value) {
    return ConvertTo-AsciiJsonRaw @($value) 4
}

# This boot's start as UTC ticks, whole seconds, as a string (a number string
# survives ConvertFrom-Json unchanged on 5.1 and 7, unlike a date).
function Get-BootStamp {
    $t = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime.ToUniversalTime().Ticks
    return [string]($t - ($t % [TimeSpan]::TicksPerSecond))
}

# Did this boot follow an unexpected shutdown? Windows logs Kernel-Power 41
# and EventLog 6008 a few seconds into the boot after a power cut, a crash or
# a held power button; a normal restart logs neither.
function Test-DirtyBoot {
    $since = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime.AddMinutes(-1)
    foreach ($q in @(
            @{ LogName = 'System'; ProviderName = 'Microsoft-Windows-Kernel-Power'; Id = 41; StartTime = $since },
            @{ LogName = 'System'; ProviderName = 'EventLog'; Id = 6008; StartTime = $since })) {
        if ($null -ne (Get-WinEvent -FilterHashtable $q -MaxEvents 1 -ErrorAction SilentlyContinue)) { return $true }
    }
    return $false
}

function Write-RestoreLog([string]$msg) {
    try { Add-Content -LiteralPath $RestoreLog -Value "$((Get-Date).ToString('s'))  $msg" -Encoding Ascii } catch { }
}

# The rows of a snapshot file (never a lone $null for an empty list).
function Get-SnapshotRows($snap) {
    return @(@($snap.sessions) | Where-Object { $null -ne $_ })
}

# First call after a boot: the previous boot's last snapshot becomes
# restore-pending.json when that boot ended unexpectedly, and is dropped
# otherwise. snapshot and restore both start at sign-in, so either may get
# here first; the move means only one of them wins.
function Step-Snapshot([string]$bootStamp) {
    if (-not (Test-Path -LiteralPath $OpenSessions)) { return }
    $snap = $null
    try { $snap = Get-Content -LiteralPath $OpenSessions -Raw | ConvertFrom-Json } catch { }
    if ($null -ne $snap -and [string]$snap.boot -eq $bootStamp) { return }
    try {
        $rows = if ($null -ne $snap) { Get-SnapshotRows $snap } else { @() }
        if ($rows.Count -gt 0 -and (Test-DirtyBoot)) {
            Move-Item -LiteralPath $OpenSessions -Destination $RestorePending -Force
            Write-RestoreLog "Unexpected shutdown: $($rows.Count) session(s) waiting to be restored."
        } else {
            Remove-Item -LiteralPath $OpenSessions -Force
        }
    } catch { }
}

# Wait (up to $NetworkWait) until claude.ai's API answers on 443, so a resumed
# session can open its Remote Control link straight away.
function Wait-Network {
    $until = (Get-Date) + $NetworkWait
    while ((Get-Date) -lt $until) {
        $tcp = New-Object System.Net.Sockets.TcpClient
        try {
            if ($tcp.ConnectAsync('api.anthropic.com', 443).Wait(3000) -and $tcp.Connected) { return $true }
        } catch { } finally { $tcp.Dispose() }
        Start-Sleep -Seconds 3
    }
    return $false
}

# A Remote Control name that is safe on claude.cmd's command line (cmd expands
# % and treats " ^ & | < > specially): plain ASCII only, 60 characters.
function Get-SafeName([string]$name) {
    $n = (($name -replace "[^A-Za-z0-9 ._,/()'+:#-]", '') -replace '\s+', ' ').Trim()
    if ($n.Length -gt 60) { $n = $n.Substring(0, 60).TrimEnd() }
    return $n
}

switch ($Action) {

    'prep-launch' {
        if (-not (Test-Token $Project)) { Exit-With "Invalid project '$Project'" 2 }
        $mid = if (Test-Token $Model) { $Model } else { 'default' }
        if (-not $ModelMap.ContainsKey($mid)) { $mid = 'default' }

        $resumeLine = ''
        if ($Resume -ne '') {
            $rid = $Resume.ToLower()
            if (-not (Test-Uuid $rid)) { Exit-With 'Invalid session id.' 2 }

            # The id must be one of THIS project's transcripts...
            $projDir = Get-ProjectDir $Project $Dir
            if ($null -eq $projDir) { Exit-With "No launcher task for project '$Project'" 3 }
            $found = $false
            foreach ($d in (Get-TranscriptDirs $projDir)) {
                if (Test-Path -LiteralPath (Join-Path $d "$rid.jsonl")) { $found = $true }
            }
            if (-not $found) { Exit-With "That session isn't one of $Project's sessions." 3 }

            # ...and not already running (two processes on one session collide).
            # -Reconnect first ends the process that resumed exactly this id:
            # it can stay alive after its link to the Claude app drops.
            $state = Get-RunningSessionIds
            if ($Reconnect -and $state.running.ContainsKey($rid)) {
                if (-not $state.resumed.ContainsKey($rid)) {
                    Exit-With "That session wasn't started with resume, so it can't be reconnected from here. End it at the PC first." 3
                }
                if (-not (Stop-ResumedSession $rid)) { Exit-With 'The old session did not stop in time. Try again.' 4 }
                $state = Get-RunningSessionIds
            }
            if ($state.running.ContainsKey($rid)) {
                Exit-With 'That session is already running. Use Reconnect if the app shows it disconnected.' 3
            }
            $resumeLine = "$rid $([DateTime]::UtcNow.Ticks)"
        }

        Set-Content -Path (Join-Path $SessionsDir "next-model-$Project.txt") -Value $mid -Encoding Ascii
        # Always rewritten, so a plain launch can never pick up an old resume.
        Set-Content -Path (Join-Path $SessionsDir "next-resume-$Project.txt") -Value $resumeLine -Encoding Ascii
        & schtasks /run /tn "LaunchClaudeSession_$Project" | Out-Null
        exit $LASTEXITCODE
    }

    'launch' {
        # Runs on the interactive desktop (via the Scheduled Task).
        if (-not (Test-Token $Project)) { Exit-With "Invalid project '$Project'" 2 }
        if ([string]::IsNullOrWhiteSpace($Dir) -or -not (Test-Path -LiteralPath $Dir)) {
            Exit-With "Missing or invalid -Dir for project '$Project'" 2
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

        # The resume sentinel: "<uuid> <utc ticks>", honoured only when fresh.
        $resumeId = ''
        $rsentinel = Join-Path $SessionsDir "next-resume-$Project.txt"
        if (Test-Path $rsentinel) {
            $raw = ''
            try { $raw = [string](Get-Content $rsentinel -Raw) } catch { }
            $m = [regex]::Match($raw.Trim(), '^(\S+) (\d+)$')
            if ($m.Success -and (Test-Uuid $m.Groups[1].Value)) {
                $age = [DateTime]::UtcNow - [DateTime]::new([long]$m.Groups[2].Value, [DateTimeKind]::Utc)
                if ($age -ge [TimeSpan]::Zero -and $age -le $ResumeMaxAge) { $resumeId = $m.Groups[1].Value }
            }
            Remove-Item $rsentinel -Force -ErrorAction SilentlyContinue
        }

        if ($resumeId -ne '') {
            $args = @('--resume', $resumeId, '--remote-control')
        } else {
            $args = @('--remote-control', $Project)
        }
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
            resume     = $resumeId
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
        # Remember live session names while they are visible (see recent).
        try { Get-RunningSessionIds | Out-Null } catch { }
        # Always emit a JSON array (even for 0/1 elements).
        Write-Output (ConvertTo-Json @($out) -Compress)
        exit 0
    }

    'recent' {
        # Read-only: the project's newest Remote Control sessions. Running
        # ones are listed too (marked), since a resumed process can outlive
        # its link to the Claude app and then needs Reconnect; they never use
        # up the ten.
        if (-not (Test-Token $Project)) { Exit-With "Invalid project '$Project'" 2 }
        $projDir = Get-ProjectDir $Project $Dir
        if ($null -eq $projDir) { Exit-With "No launcher task for project '$Project'" 3 }

        $state = Get-RunningSessionIds
        $items = foreach ($d in (Get-TranscriptDirs $projDir)) {
            foreach ($f in @(Get-ChildItem -LiteralPath $d -Filter '*.jsonl' -File -ErrorAction SilentlyContinue)) {
                if ($f.Length -ge $StubBytes -and (Test-Uuid $f.BaseName.ToLower())) { [pscustomobject]@{ file = $f } }
            }
        }
        $picked = Select-RemoteSessions @($items) $state $RecentLimit

        $out = @()
        foreach ($it in $picked) {
            try { $out += (New-SessionRow $it.file $state '') } catch { continue }
        }
        Save-TranscriptCache
        Write-Output (ConvertTo-AsciiJson $out)
        exit 0
    }

    'recent-all' {
        # Read-only: the newest Remote Control sessions across every
        # launcher's folder, in one SSH call. Files are probed newest-first and
        # only the picked ones are parsed (mostly from the transcript cache).
        # Sessions in folders no launcher maps to are never listed (they
        # can't be resumed from here); `skipped` counts the remote ones.
        $rid = ''
        if ($Resume -ne '') {
            $rid = $Resume.ToLower()
            if (-not (Test-Uuid $rid)) { Exit-With 'Invalid session id.' 2 }
        }

        $folders = Get-LauncherFolders
        $state = Get-RunningSessionIds
        $base = Join-Path $ClaudeHome 'projects'
        $mapped = New-Object System.Collections.Generic.List[object]
        $unmapped = New-Object System.Collections.Generic.List[object]
        foreach ($d in @(Get-ChildItem -LiteralPath $base -Directory -ErrorAction SilentlyContinue)) {
            $key = $folders[$d.Name.ToLower()]
            foreach ($f in @(Get-ChildItem -LiteralPath $d.FullName -Filter '*.jsonl' -File -ErrorAction SilentlyContinue)) {
                if ($f.Length -lt $StubBytes -or -not (Test-Uuid $f.BaseName.ToLower())) { continue }
                $item = [pscustomobject]@{ file = $f; project = $key }
                if ($null -eq $key) { $unmapped.Add($item) } else { $mapped.Add($item) }
            }
        }

        if ($rid -ne '') {
            # One session, any age: tells the box which project it belongs to.
            $picked = @($mapped | Where-Object { $_.file.BaseName.ToLower() -eq $rid } |
                Where-Object { Test-RemoteSession $_.file } | Select-Object -First 1)
        } else {
            $picked = Select-RemoteSessions $mapped.ToArray() $state $RecentAllLimit
        }

        $skipped = 0
        foreach ($u in $unmapped) {
            if ($rid -ne '' -and $u.file.BaseName.ToLower() -ne $rid) { continue }
            if (Test-RemoteSession $u.file) { $skipped++ }
        }

        $out = @()
        foreach ($m in $picked) {
            try { $out += (New-SessionRow $m.file $state ([string]$m.project)) } catch { continue }
        }
        Save-TranscriptCache
        Write-Output (ConvertTo-AsciiJsonRaw ([ordered]@{ sessions = @($out); skipped = $skipped }) 4)
        exit 0
    }

    'end' {
        if ($SessionPid -le 0) { Exit-With 'Missing/invalid -SessionPid' 2 }
        $markerPath = Get-MarkerPath $SessionPid
        if (-not (Test-Path $markerPath)) { Exit-With "No ControlPanel session for pid $SessionPid" 3 }

        $marker = Get-Content $markerPath -Raw | ConvertFrom-Json
        if (-not (Test-Marker $marker)) {
            Remove-Item $markerPath -Force -ErrorAction SilentlyContinue
            Exit-With "Session pid $SessionPid is no longer running" 3
        }

        # Kill the whole tree (claude spawns children). taskkill is best-effort.
        & taskkill /PID $SessionPid /T /F | Out-Null
        Remove-Item $markerPath -Force -ErrorAction SilentlyContinue
        Write-Output "Ended session pid $SessionPid ($($marker.project))."
        exit 0
    }

    'snapshot' {
        # Windowless, every minute. Only sessions whose Remote Control link is
        # on right now (bridgeSessionId) and whose process is the one that
        # wrote the file (start time matches procStart, so a recycled PID
        # never counts; the exe can be renamed claude.exe.old.* by an update).
        $bootStamp = Get-BootStamp
        Step-Snapshot $bootStamp

        $rows = @()
        $live = Join-Path $ClaudeHome 'sessions'
        foreach ($f in @(Get-ChildItem -LiteralPath $live -Filter '*.json' -File -ErrorAction SilentlyContinue)) {
            try {
                $s = Get-Content -LiteralPath $f.FullName -Raw | ConvertFrom-Json
                if ([string]$s.kind -ne 'interactive' -or [string]$s.bridgeSessionId -eq '') { continue }
                $sid = ([string]$s.sessionId).ToLower()
                if (-not (Test-Uuid $sid)) { continue }
                $p = Get-Process -Id ([int]$s.pid) -ErrorAction SilentlyContinue
                if ($null -eq $p) { continue }
                if ([string]$s.procStart -match '^\d+$') {
                    if ([Math]::Abs($p.StartTime.ToFileTimeUtc() - [long]$s.procStart) -gt [TimeSpan]::TicksPerSecond) { continue }
                } elseif ($p.ProcessName -notlike 'claude*') { continue }
                $rows += [ordered]@{ id = $sid; cwd = [string]$s.cwd; name = [string]$s.name }
            } catch { }
        }

        $snap = [ordered]@{
            boot     = $bootStamp
            taken    = [string][DateTime]::UtcNow.Ticks
            sessions = @($rows)
        }
        $tmp = "$OpenSessions.tmp"
        ConvertTo-AsciiJsonRaw $snap 4 | Set-Content -LiteralPath $tmp -Encoding Ascii
        Move-Item -LiteralPath $tmp -Destination $OpenSessions -Force
        exit 0
    }

    'restore' {
        # Runs on the interactive desktop at sign-in (behind the auto-logon
        # lock screen is fine). Restores at most once per unexpected shutdown:
        # the pending file is claimed before anything starts.
        Step-Snapshot (Get-BootStamp)
        if (-not (Test-Path -LiteralPath $RestorePending)) { exit 0 }
        $file = $RestorePending
        if (-not $DryRun) {
            try { Move-Item -LiteralPath $RestorePending -Destination $RestoreClaimed -Force } catch { exit 0 }
            $file = $RestoreClaimed
        }
        $snap = $null
        try { $snap = Get-Content -LiteralPath $file -Raw | ConvertFrom-Json } catch { }
        if ($null -eq $snap) { Write-RestoreLog 'Restore skipped: the snapshot could not be read.'; exit 0 }
        $age = [DateTime]::UtcNow - [DateTime]::new([long]$snap.taken, [DateTimeKind]::Utc)
        if ($age -gt $RestoreMaxAge) {
            Write-RestoreLog "Restore skipped: the snapshot is $([int]$age.TotalHours) hours old."
            exit 0
        }
        if (-not $DryRun -and -not (Wait-Network)) {
            Write-RestoreLog 'No network after 2 minutes; restoring anyway (each session reconnects when it can).'
        }

        $state = Get-RunningSessionIds
        $folders = Get-LauncherFolders
        $started = 0
        foreach ($s in (Get-SnapshotRows $snap)) {
            $id = ([string]$s.id).ToLower()
            $cwd = [string]$s.cwd
            if (-not (Test-Uuid $id)) { continue }
            if ($state.running.ContainsKey($id)) { Write-RestoreLog "$id already running, left alone."; continue }
            if ([string]::IsNullOrWhiteSpace($cwd) -or -not (Test-Path -LiteralPath $cwd -PathType Container)) {
                Write-RestoreLog "$id skipped: folder '$cwd' is gone."; continue
            }
            # The id must be a transcript in that folder's own project store,
            # so a snapshot can only ever resume what really ran there.
            $found = $false
            foreach ($d in (Get-TranscriptDirs $cwd)) {
                if (Test-Path -LiteralPath (Join-Path $d "$id.jsonl")) { $found = $true }
            }
            if (-not $found) { Write-RestoreLog "$id skipped: no transcript for it under '$cwd'."; continue }

            $name = Get-SafeName ([string]$s.name)
            $args = @('--resume', $id, '--remote-control')
            if ($name -ne '') { $args += "`"$name`"" }
            $enc = (($cwd.TrimEnd('\', '/')) -replace '[^A-Za-z0-9]', '-').ToLower()
            $proj = if ($folders.ContainsKey($enc)) { $folders[$enc] } else { 'restored' }

            if ($DryRun) {
                Write-RestoreLog "[dry run] would start in '$cwd': claude $($args -join ' ')"
                continue
            }
            if ($started -gt 0) { Start-Sleep -Seconds $RestoreStagger }
            $proc = Start-Process -FilePath 'claude.cmd' -ArgumentList $args -WorkingDirectory $cwd -PassThru
            $marker = [ordered]@{
                pid        = $proc.Id
                project    = $proj
                model      = 'default'
                resume     = $id
                dir        = $cwd
                started    = (Get-Date).ToString('s')
                startTicks = $proc.StartTime.Ticks
            }
            $marker | ConvertTo-Json -Compress | Set-Content -Path (Get-MarkerPath $proc.Id) -Encoding Ascii
            Write-RestoreLog "Restored $id ($proj, '$name') as pid $($proc.Id)."
            $started++
        }
        if (-not $DryRun) { Write-RestoreLog "Restore finished: $started session(s) started." }
        exit 0
    }

    default { Exit-With "Unknown action '$Action'" 2 }
}
