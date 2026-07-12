#!/usr/bin/env bash
# Runs as www-data (no sudo). Triggers a pre-created Windows Scheduled Task that
# launches `claude --remote-control` in a VSCode project on the interactive
# desktop. The <project> token is charset-guarded here and must correspond to a
# task named LaunchClaudeSession_<project> on Windows.
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
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "schtasks /run /tn LaunchClaudeSession_${PROJECT}"
