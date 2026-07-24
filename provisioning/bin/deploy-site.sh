#!/usr/bin/env bash
# Runs as gemini: sudo -n -u gemini /opt/controlpanel/bin/deploy-site.sh <Site>
# Re-validates the site against a hard allowlist (defense-in-depth on top of the
# Laravel-side validation) before touching deploy.sh.
set -euo pipefail

SITE="${1:-}"
case "$SITE" in
    Navigation|LittlePocketMeseum|SingularCoalescence|AiCampaignManager|Budget|ControlPanel|StoryCampaign|NorthernCall_v2|PasswordVault) ;;
    *) echo "Refusing to deploy unknown site: '${SITE}'" >&2; exit 2 ;;
esac

exec bash /home/gemini/websites/deploy.sh "$SITE"
