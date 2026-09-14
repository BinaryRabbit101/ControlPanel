#!/usr/bin/env bash
# Runs as www-data (no sudo). SSHes into the Windows PC and puts it to sleep.
# Host/user/key come from config.env; the remote command is fixed here.
#
# The remote command only *triggers* the ControlPanel_SleepPC Scheduled Task
# (see provisioning/windows/register-sleep-task.ps1), which waits 2 s and then
# calls SetSuspendState. Calling SetSuspendState directly over SSH never
# returns — the PC suspends with the session open — so the wrapper hung until
# its timeout and the panel saw an error for a sleep that actually worked.
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
    'schtasks /run /tn ControlPanel_SleepPC'
