#!/usr/bin/env bash
# Runs as www-data (no sudo). Asks Windows to start a `claude --remote-control`
# session in a VSCode project on the interactive desktop, with an optional model,
# or to RESUME one of the project's earlier sessions.
#
#   $1 = project token  -> a Windows Scheduled Task LaunchClaudeSession_<project>
#   $2 = model id        -> one of config('control_panel.models')[].id (e.g.
#                           opus-4-8 / sonnet-5 / fable-5 / default)
#        OR a session id  (lowercase UUID) -> resume that session, default model
#   $3 = session id       -> resume that session with the model in $2
#
# Every token is guarded here (defense-in-depth on top of the Laravel
# allowlist). The model is handed to Windows by name only; claude-session.ps1
# maps the id to the real model string (the second allowlist). A session id must
# be exactly a UUID, and Windows re-checks it is one of THIS project's sessions
# and not already running before it fires the per-project Scheduled Task.
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'

PROJECT="${1:-}"
MODEL="${2:-default}"
RESUME="${3:-}"
[ -z "$MODEL" ] && MODEL="default"
# A UUID in the model slot is a resume with the default model.
if [ -z "$RESUME" ] && [[ "$MODEL" =~ $UUID_RE ]]; then
    RESUME="$MODEL"
    MODEL="default"
fi

case "$PROJECT" in
    "") echo "Missing project token" >&2; exit 2 ;;
    *[!A-Za-z0-9_-]*) echo "Invalid project token: '${PROJECT}'" >&2; exit 2 ;;
esac
case "$MODEL" in
    *[!A-Za-z0-9_-]*) echo "Invalid model token: '${MODEL}'" >&2; exit 2 ;;
esac
RESUME_ARG=""
if [ -n "$RESUME" ]; then
    if ! [[ "$RESUME" =~ $UUID_RE ]]; then
        echo "Invalid session id" >&2; exit 2
    fi
    RESUME_ARG=" -Resume ${RESUME}"
fi

# Named params only; tokens are guarded so no shell metacharacters can reach the
# remote command. prep-launch writes the model (and resume) sentinels and fires
# the LaunchClaudeSession_<project> task on the interactive desktop.
#
# WarnWeakCrypto=no: Windows' bundled OpenSSH has no post-quantum key exchange
# yet, so a modern client prints a three-line "store now, decrypt later" warning
# to stderr on every connection. Harmless on a LAN, but the dashboard surfaces
# stderr, which made a successful launch look like a failure.
exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o WarnWeakCrypto=no \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    "powershell -NoProfile -ExecutionPolicy Bypass -File C:\ProgramData\ControlPanel\bin\claude-session.ps1 -Action prep-launch -Project ${PROJECT} -Model ${MODEL}${RESUME_ARG}"
