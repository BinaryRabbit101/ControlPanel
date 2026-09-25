#!/usr/bin/env bash
# Runs as www-data (no sudo). SSHes into Franklin (her PC) and puts it to sleep.
# Same shape as win-sleep.sh: the remote command only *triggers* the
# ControlPanel_SleepPC Scheduled Task registered on Franklin by
# provisioning/windows/register-sleep-task.ps1, so SSH returns before the
# machine suspends. Host/user/key come from config.env.
set -euo pipefail

# shellcheck source=/dev/null
source /opt/controlpanel/bin/config.env

exec ssh -i "$WIN_SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=accept-new \
    -o WarnWeakCrypto=no \
    -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
    -o ConnectTimeout=10 \
    "${FRANKLIN_USER}@${FRANKLIN_HOST}" \
    'schtasks /run /tn ControlPanel_SleepPC'
