#!/usr/bin/env bash
# Root-owned. Invoked by the panel as: sudo -n /opt/controlpanel/bin/reload-nginx.sh
set -euo pipefail
/usr/sbin/nginx -t
exec /usr/bin/systemctl reload nginx
