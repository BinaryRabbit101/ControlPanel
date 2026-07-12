#!/usr/bin/env bash
# Root-owned. Invoked as: sudo -n /opt/controlpanel/bin/restart-phpfpm.sh
set -euo pipefail
exec /usr/bin/systemctl restart php8.5-fpm
