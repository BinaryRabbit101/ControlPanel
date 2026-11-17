# Household connector (remote MCP server) — packet 1

> **Superseded (2026-11-17):** the connector moved to Butler (`websites\Butler`) and was removed
> from ControlPanel in Butler packet 2. Kept as history.

Owner decisions (2026-11-16, HUB session):

- A remote MCP server lives **inside ControlPanel**, added as a **custom connector in claude.ai**
  (used from the Claude iPhone app) by the owner and his wife Emily.
- Public address: **https://mcp.thecorgisquad.com/mcp**, published through a Cloudflare Tunnel
  that forwards only the connector paths (the main session sets up the tunnel + DNS, not you).
- **Shared login for now:** both people sign in with the owner's existing ControlPanel account.
  No new users, no roles. Every tool is available to whoever is signed in.
- **Shared app tokens for now:** Shopping List and Reminders are called with the owner's phone
  tokens, stored as box env vars. The main session fills them in on the box.
- v1 uses **only the endpoints that already exist** in Shopping List and Reminders (no changes to
  those apps in this packet).
- Mini-PC actions (deploy, rebuild cache, reboot, health) are **not** exposed. Same "fixed menu of
  verbs" principle as the Shortcut API.

## Read first

- `.claude/skills/controlpanel/SKILL.md` and `.claude/skills/controlpanel-actions/SKILL.md`
- `app/Support/ControlPanel/ActionDispatcher.php`, `ActionRegistry.php`
- `app/Http/Controllers/Api/ShortcutController.php` (the verbs + speakable messages to reuse)
- `routes/api.php`, `routes/web.php`, `bootstrap/app.php`, `app/Http/Middleware/RequireLocalNetwork.php`
- `C:\Users\binar\Documents\HUB\STANDARDS.md` (sign-in screen look — the consent screen matches it)
- laravel/mcp docs: https://laravel.com/docs/12.x/mcp (v1.2.x supports Laravel 13) and
  Passport docs https://laravel.com/docs/12.x/passport
- Memory notes on gotchas: `C:\Users\binar\.claude\projects\C--Users-binar-Documents-HUB\memory\laravel-gotchas.md`

## Build

1. **Packages:** `composer require laravel/mcp laravel/passport` (the box runs PHP 8.5, local 8.4;
   use `--ignore-platform-req=php` only if needed). Passport install: migrations, `api` guard
   (`driver: passport`), `HasApiTokens` + `OAuthenticatable` on `User`. **Key files
   (`storage/oauth-*.key`) are never committed**: make sure they're gitignored. The main session
   runs `passport:keys` on the box once.
2. **Routes** (`routes/ai.php`): `Mcp::oauthRoutes();` and
   `Mcp::web('/mcp', HouseholdServer::class)->middleware(['auth:api', 'throttle:…'])`.
   Throttle `/oauth/register` and the login route (sensible limits, e.g. 10/min per IP).
   Restrict `config/mcp.php` `redirect_domains` to `https://claude.ai/` and `https://claude.com/`.
3. **Behind the tunnel:** cloudflared connects to nginx on the box over http with
   `X-Forwarded-Proto: https` + `X-Forwarded-Host: mcp.thecorgisquad.com`. `trustProxies(at:'*')`
   already exists. Confirm it honours proto/host so the `.well-known` metadata, Passport redirects
   and the issuer all come out as `https://mcp.thecorgisquad.com/...` for public requests, while
   the LAN dashboard on `http://192.168.0.164:85` keeps working unchanged (APP_URL stays the LAN URL).
   Write a feature test that sends forwarded headers and asserts the metadata URLs are https on the
   public host.
4. **Sign-in path on the public host:** Passport's `/oauth/authorize` redirects to `login` when no
   session exists. `login`, `logout` and `/oauth/*` must work **without** the `lan` middleware (check
   that they do; the dashboard, profile and actions must **stay** behind `lan`). After login the user
   must land back on the authorize page (`redirect()->intended`). The public tunnel will forward
   only: `/mcp`, `/oauth/*`, `/.well-known/*`, `/login`, `/logout`, `/build/*`, `/up`, `/favicon*`.
   If the consent flow needs any other path, report it.
5. **Consent screen:** publish `mcp-views`, register `Passport::authorizationView(...)`, and restyle
   `mcp/authorize.blade.php` to match the ControlPanel sign-in screen (STANDARDS.md). Plain words:
   "Claude wants to use ControlPanel for you" with Approve / Cancel, and `dusk="…"` attributes on
   the buttons. Phone-width friendly, no horizontal scroll.
6. **Server** `app/Mcp/Servers/HouseholdServer.php`, with name "Household" and short instructions
   (PCs are Gemini = the owner's, Franklin = Emily's. Replies are spoken-style. Confirm before
   sleeping a PC or ending a session if the user's intent is unclear).
7. **Tools.** Each returns the same short speakable sentence the Shortcut API would, plus useful
   structured detail. Pull any logic you need out of `ShortcutController` into a shared service
   (e.g. `app/Support/ControlPanel/ShortcutVerbs.php`) so the Shortcut API and MCP tools share it.
   **The Shortcut API's behaviour and responses must not change** (existing ShortcutApiTest stays
   green). Everything dispatches through `ActionDispatcher` as `$request->user()` so action_logs
   attribute it, and never composes shell strings.
   - `pc_status(pc)`, `wake_pc(pc)`, `sleep_pc(pc)`: pc = gemini|franklin (enum).
   - `list_projects()`, `start_session(project)`
   - `list_recent_sessions(project?)`, `resume_session(session, project?)`
   - `list_running_sessions()` (win.list-claude) and `end_session(pid)` (win.end-claude)
   - `shopping_list()`: GET `{shoppinglist}/api/widget/list`. `shopping_groups()`: GET `/api/widget/groups`.
     `add_shopping_items(items: string[] | name, note?, group?)`: POST `/api/widget/items`
     (single `{name, note?, group?}` or multi-line `{text, group?}`). Pass the app's own `message`
     through.
   - `create_reminder(title, notes?, due_date?, due_time?, list?, is_shared?)`: POST
     `{reminders}/api/shortcut/reminders` with header `X-Shortcut-Token`. `todays_reminders()`:
     GET `{reminders}/api/widget/today?token=…`. `send_push(title, body, to: me|both, url?)`:
     POST `{reminders}/api/push` with `X-Api-Token`.
   - Use tool annotations (read-only / destructive hints) where laravel/mcp supports them.
   - Config: `config/control_panel.php` `household` block. `shoppinglist_url`
     (env `CP_SHOPPINGLIST_URL`, default `http://127.0.0.1:124`), `shoppinglist_token`
     (`CP_SHOPPINGLIST_TOKEN`), `reminders_url` (`CP_REMINDERS_URL`, default `http://127.0.0.1:90`),
     `reminders_token` (`CP_REMINDERS_TOKEN`). A missing token gives a friendly tool error ("Shopping
     List isn't connected yet"), not an exception. Short HTTP timeouts. Never log token values.
     Add the keys (empty) to `.env.example`.
8. **Docs:** add a "Household connector" section to `.claude/skills/controlpanel/SKILL.md` and the
   README covering the tools, the env keys, the public paths, how to add the connector in claude.ai
   (Settings → Connectors → Add custom connector → `https://mcp.thecorgisquad.com/mcp`), and that
   the Passport keys live only on the box.

## Gates (you run them; the main session re-runs them)

- `php artisan test`. Baseline is **3 known failures** (Breeze registration ×2 + stock ExampleTest).
  Everything else is green. New feature tests cover:
  - unauthenticated `POST /mcp` returns 401 with a `WWW-Authenticate` header
  - the `.well-known` endpoints return https URLs on the forwarded public host
  - `/oauth/register` accepts a claude.ai redirect and rejects a foreign one
  - each tool, via laravel/mcp's testing helpers acting as a user, with `ActionRunner`/the queue
    faked and `Http::fake()` for the two apps (assert the token header is sent, and that a missing
    token gives the friendly error)
  - Shortcut API unchanged
- `vendor/bin/pint --test` is clean (run `vendor/bin/pint` on your files).
- `npm run build` succeeds.
- Dusk: add `tests/Browser/McpConsentTest.php`. Sign in, open an authorize URL for a registered
  test client, see the consent screen and Approve button. Run with the recipe in the controlpanel
  skill (headless; never take focus or open visible windows; restore `.env` afterwards).

## Do not

- Commit, push, deploy, or touch the box. Don't create the tunnel or DNS.
- Read or print any `.env` values or tokens.
- Change Shopping List, Reminders, or the mini-PC actions.

## Report back

Files changed, gate output (test counts, pint, build, dusk), every choice you made where this
brief was silent, any extra public path the sign-in flow needs, and anything the main session must
run on the box (migrations are automatic via deploy.sh; list anything else).
