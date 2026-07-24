#!/usr/bin/env bash
# Runs as gemini: sudo -n -u gemini /opt/controlpanel/bin/rebuild-cache.sh <Site>
set -euo pipefail

SITE="${1:-}"
case "$SITE" in
    Navigation|LittlePocketMeseum|SingularCoalescence|AiCampaignManager|Budget|ControlPanel|StoryCampaign|NorthernCall_v2|PasswordVault) ;;
    *) echo "Refusing unknown site: '${SITE}'" >&2; exit 2 ;;
esac

cd "/home/gemini/websites/${SITE}"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Rebuilt caches for ${SITE}."
