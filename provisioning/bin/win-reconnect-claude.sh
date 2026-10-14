#!/usr/bin/env bash
# Runs as www-data (no sudo). Reconnects a Claude session whose process is
# still running on Windows but whose link to the Claude app dropped: ends the
# process that resumed exactly this session id, then resumes it again.
#
#   $1 = project token -> LaunchClaudeSession_<project>
#   $2 = session id    -> lowercase UUID
#
# Windows (claude-session.ps1 prep-launch -Reconnect) re-checks the id is one of
# THIS project's sessions and only ever ends a claude.exe whose command line
# contains `--resume <that exact id>`; never any other process.
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'

PROJECT="${1:-}"
SESSION="${2:-}"

case "$PROJECT" in
    "") echo "Missing project token" >&2; exit 2 ;;
    *[!A-Za-z0-9_-]*) echo "Invalid project token: '${PROJECT}'" >&2; exit 2 ;;
esac
if ! [[ "$SESSION" =~ $UUID_RE ]]; then
    echo "Invalid session id" >&2; exit 2
fi

exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o WarnWeakCrypto=no \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "powershell -NoProfile -ExecutionPolicy Bypass -File C:\ProgramData\ControlPanel\bin\claude-session.ps1 -Action prep-launch -Project ${PROJECT} -Resume ${SESSION} -Reconnect"
