#!/usr/bin/env bash
# Runs as www-data (no sudo). Read-only: asks Windows for a project's recent
# Claude sessions that are not running now, newest first, as a JSON array:
#   [ {"id":"<uuid>","title":"...","updated":"2026-10-14T13:29:33Z",
#      "name":"hub-2e","remote":true,"lastPrompt":"..."} ]
# Backs the "Resume recent" list (ActionController::recent) and the Shortcut's
# GET /api/shortcut/recent.
#
#   $1 = project token -> claude-session.ps1 finds the project's folder from
#                         its LaunchClaudeSession_<project> task
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

PROJECT="${1:-}"
case "$PROJECT" in
    "") echo "Missing project token" >&2; exit 2 ;;
    *[!A-Za-z0-9_-]*) echo "Invalid project token: '${PROJECT}'" >&2; exit 2 ;;
esac

exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o WarnWeakCrypto=no \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "powershell -NoProfile -ExecutionPolicy Bypass -File C:\ProgramData\ControlPanel\bin\claude-session.ps1 -Action recent -Project ${PROJECT}"
