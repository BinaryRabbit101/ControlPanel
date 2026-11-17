# ControlPanel

Login-gated, LAN-only dashboard on the mini-PC (port 85) that runs a fixed menu of actions:
wake/sleep/ping the PCs, start/resume/end Claude sessions on Gemini, deploy and maintain the
mini-PC sites. Operating notes live in `.claude/skills/controlpanel/SKILL.md`.

## Claude connector (moved to Butler)

The Claude connector lives in Butler (`websites\Butler`); it calls this Shortcut API as the
account 'Butler'. ControlPanel itself is LAN-only again (2026-11-17): no MCP, no OAuth, and any
request carrying Cloudflare's `Cf-Ray` / `Cf-Connecting-Ip` headers gets 403 from `lan`.
