# ControlPanel

Login-gated, LAN-only dashboard on the mini-PC (port 85) that runs a fixed menu of actions:
wake/sleep/ping the PCs, start/resume/end Claude sessions on Gemini, deploy and maintain the
mini-PC sites. Operating notes live in `.claude/skills/controlpanel/SKILL.md`.

## Household connector (remote MCP server)

ControlPanel is also a **remote MCP server** — the "Household" custom connector in claude.ai,
used from the Claude iPhone app by the owner and Emily (2026-11-16). Built on `laravel/mcp`
+ `laravel/passport` (OAuth 2.1 with PKCE and dynamic client registration). Code:
`routes/ai.php`, `app/Mcp/Servers/HouseholdServer.php`, `app/Mcp/Tools/*`,
`app/Support/ControlPanel/ShortcutVerbs.php` (verbs + sentences shared with the iOS Shortcut
API) and `HouseholdApps.php` (Shopping List / Reminders HTTP calls).

**Public address:** `https://mcp.thecorgisquad.com/mcp`, through a Cloudflare Tunnel that forwards
only `/mcp`, `/oauth/*`, `/.well-known/*`, `/login`, `/logout`, `/build/*`, `/up`, `/favicon*`
(plus `/apple-touch-icon.png` and `/manifest.webmanifest` for the sign-in page's icon). The
tunnel talks http to nginx and sends `X-Forwarded-Proto: https` + `X-Forwarded-Host`;
`trustProxies('*')` turns those into https URLs on the public host, while the LAN dashboard
keeps `http://192.168.0.164:85` (APP_URL stays the LAN URL). A request is "public"
(`App\Support\ControlPanel\PublicHost`) when it carries Cloudflare's `Cf-Ray` /
`Cf-Connecting-Ip` headers, or its raw or forwarded host is `CP_MCP_PUBLIC_HOST` (any case,
trailing dot ignored); public requests get 403 from `lan` (dashboard, profile, Shortcut API)
whatever IP or host they claim. Loopback without Cf-* headers (Tailscale Serve, :448) is still
allowed. Every response carries `X-Frame-Options: DENY` + `frame-ancestors 'none'`; session
cookies are `Secure` on https only. Signed in on the public host with nowhere to return,
`/login` shows "You're signed in. Go back to Claude to finish."; logout there returns to the
sign-in form (the LAN still goes to the dashboard / `/`).

**Sign-in:** shared for now — both people sign in with the owner's ControlPanel account. Claude
opens `/oauth/authorize` → `/login` (if no session) → back to the consent screen
(`resources/views/mcp/authorize.blade.php`, the estate sign-in look: "Claude wants to use
ControlPanel for you", Approve / Cancel). Access tokens last 7 days and renew with a 90-day
refresh token. `/oauth/register` only accepts `https://claude.ai/` and `https://claude.com/`
redirects (`config/mcp.php`). Throttles: login 10/min per IP and 20/hour per email, register 10/min per IP, discovery
60/min per IP; `/mcp` 120/min per IP (before auth) and 120/min per account. Device-code grant is off.

**Tools** (each answers with the same short spoken sentence as the Shortcut API, then the
detail as JSON; PC/session tools go through `ActionDispatcher` as the signed-in account, so
they land in `action_logs`):

| Tool | Does |
|---|---|
| `pc_status(pc)` / `wake_pc(pc)` / `sleep_pc(pc)` | `pc` = `gemini` (owner) or `franklin` (Emily) |
| `list_projects()` / `start_session(project)` | start a Remote Control session on Gemini |
| `list_recent_sessions(project?)` / `resume_session(session, project?)` | resume or reconnect |
| `list_running_sessions()` / `end_session(pid)` | win.list-claude / win.end-claude |
| `shopping_list()` / `shopping_groups()` / `add_shopping_items(items[] or name, note?, group?)` | Shopping List `/api/widget/*` with `X-Api-Token` |
| `create_reminder(title, notes?, due_date?, due_time?, list?, is_shared?)` | Reminders `POST /api/shortcut/reminders` with `X-Shortcut-Token` |
| `todays_reminders()` | Reminders `GET /api/widget/today?token=` |
| `send_push(title, body, to: me\|both, url?)` | Reminders `POST /api/push` with `X-Api-Token` |

Mini-PC actions (deploy, rebuild cache, reboot, health) are deliberately **not** tools.

**Env keys** (box `.env`; empty token = that app's tools say "isn't connected yet"):
`CP_MCP_PUBLIC_HOST` (default `mcp.thecorgisquad.com`), `CP_SHOPPINGLIST_URL` (default
`http://127.0.0.1:124`), `CP_SHOPPINGLIST_TOKEN`, `CP_REMINDERS_URL` (default
`http://127.0.0.1:90`), `CP_REMINDERS_TOKEN` (the owner's phone keys). Token values are never
logged or returned.

**Passport keys** (`storage/oauth-private.key`, `storage/oauth-public.key`) live **only on the
box** — gitignored (`/storage/*.key`), created once with `php artisan passport:keys`, owned so
www-data can read them (`chown gemini:www-data`, `chmod 640`/`660`; Passport refuses
world-readable keys). Losing them signs every connector out (re-add it in claude.ai).

**Add the connector in claude.ai:** Settings → Connectors → Add custom connector →
`https://mcp.thecorgisquad.com/mcp` → Connect → sign in with the ControlPanel account →
Approve. Each person adds it on their own claude.ai account.
