#!/usr/bin/env bash
# Runs as www-data (no sudo). Asks Windows to start a `claude --remote-control`
# session in a VSCode project on the interactive desktop, with an optional model.
#
#   $1 = project token  -> a Windows Scheduled Task LaunchClaudeSession_<project>
#   $2 = model id        -> one of config('control_panel.models')[].id (e.g.
#                           opus-4-8 / sonnet-5 / fable-5 / default)
#
# Both tokens are charset-guarded here (defense-in-depth on top of the Laravel
# allowlist). The model is handed to Windows by name only; claude-session.ps1
# maps the id to the real model string (the second allowlist) and triggers the
# per-project Scheduled Task.
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

PROJECT="${1:-}"
MODEL="${2:-default}"
[ -z "$MODEL" ] && MODEL="default"

case "$PROJECT" in
    "") echo "Missing project token" >&2; exit 2 ;;
    *[!A-Za-z0-9_-]*) echo "Invalid project token: '${PROJECT}'" >&2; exit 2 ;;
esac
case "$MODEL" in
    *[!A-Za-z0-9_-]*) echo "Invalid model token: '${MODEL}'" >&2; exit 2 ;;
esac

# Named params only; tokens are charset-guarded so no shell metacharacters can
# reach the remote command. prep-launch writes the model sentinel and fires the
# LaunchClaudeSession_<project> task on the interactive desktop.
exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "powershell -NoProfile -ExecutionPolicy Bypass -File C:\\ProgramData\\ControlPanel\\bin\\claude-session.ps1 -Action prep-launch -Project ${PROJECT} -Model ${MODEL}"
