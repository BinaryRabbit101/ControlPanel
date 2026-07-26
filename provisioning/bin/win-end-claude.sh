#!/usr/bin/env bash
# Runs as www-data (no sudo). Ends a running Claude remote-control session on
# Windows, identified by its PID.
#
#   $1 = pid (digits only)
#
# The PID is guarded to digits here; the real allowlist is enforced on Windows:
# claude-session.ps1 refuses to kill any PID it did not record in its session
# registry (and re-checks the process start time to defeat PID reuse).
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

SESSION_PID="${1:-}"
case "$SESSION_PID" in
    "") echo "Missing session pid" >&2; exit 2 ;;
    *[!0-9]*) echo "Invalid session pid: '${SESSION_PID}'" >&2; exit 2 ;;
esac

exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o WarnWeakCrypto=no \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "powershell -NoProfile -ExecutionPolicy Bypass -File C:\\ProgramData\\ControlPanel\\bin\\claude-session.ps1 -Action end -SessionPid ${SESSION_PID}"
