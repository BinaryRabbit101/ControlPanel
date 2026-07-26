#!/usr/bin/env bash
# Runs as www-data (no sudo). SSHes into the Windows PC and puts it to sleep.
# Host/user/key come from config.env; the remote command is fixed here.
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o WarnWeakCrypto=no \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${WIN_USER}@${WIN_HOST}" \
    'rundll32.exe powrprof.dll,SetSuspendState 0,1,0'
