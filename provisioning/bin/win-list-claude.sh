#!/usr/bin/env bash
# Runs as www-data (no sudo). Read-only: asks Windows for the list of running
# Claude remote-control sessions and prints it as a JSON array on stdout:
#   [ {"pid":8124,"project":"controlpanel","model":"opus-4-8","started":"..."} ]
# claude-session.ps1 prunes dead/stale entries before printing. Backs the
# "End Claude session" dropdown (see ActionController::sessions).
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "powershell -NoProfile -ExecutionPolicy Bypass -File C:\\ProgramData\\ControlPanel\\bin\\claude-session.ps1 -Action list"
