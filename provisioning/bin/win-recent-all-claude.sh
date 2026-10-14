#!/usr/bin/env bash
# Runs as www-data (no sudo). Read-only: asks Windows for the newest Remote
# Control sessions across EVERY ControlPanel project, in one SSH call:
#   {"sessions":[{"id":"<uuid>","title":"...","updated":"...","name":"...",
#                 "remote":true,"lastPrompt":"...","running":false,
#                 "reconnectable":false,"project":"hub"}],"skipped":0}
# Backs GET /api/shortcut/recent with no project, and POST /api/shortcut/resume
# with no project (which passes the id to learn the session's project).
#
#   $1 = optional session id -> lowercase UUID; just that session's row
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'

SESSION="${1:-}"
RESUME=""
if [[ -n "$SESSION" ]]; then
    if ! [[ "$SESSION" =~ $UUID_RE ]]; then
        echo "Invalid session id" >&2; exit 2
    fi
    RESUME=" -Resume ${SESSION}"
fi

exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o WarnWeakCrypto=no \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "powershell -NoProfile -ExecutionPolicy Bypass -File C:\ProgramData\ControlPanel\bin\claude-session.ps1 -Action recent-all${RESUME}"
