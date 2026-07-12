#!/usr/bin/env bash
# Root-owned. Invoked as: sudo -n /opt/controlpanel/bin/reboot-host.sh
set -euo pipefail
exec /usr/bin/systemctl reboot
