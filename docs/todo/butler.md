# Butler — the household's Claude connector as its own app (packet 1)

Owner decisions (2026-11-16, HUB session):

- The Claude custom connector moves **out of ControlPanel** into a new small Laravel app,
  **Butler** (`C:\Users\binar\Documents\websites\Butler`). Butler is the household's front door:
  it holds the OAuth sign-in, the MCP tools and the keys for every app it talks to.
- Butler calls **ControlPanel's existing Shortcut API** for PC and session verbs (as a ControlPanel
  service account named Butler, with its own `X-Api-Token`), and calls **Shopping List and
  Reminders directly**. Future apps (Cafe, Budget, Kitchen, …) become more Butler tools; nothing
  household-related goes into ControlPanel any more.
- Same public address: **https://mcp.thecorgisquad.com/mcp**. The main session re-points the
  Cloudflare Tunnel at Butler once it's deployed. Nobody has added the connector yet, so no
  migration of existing connections is needed.
- **Shared login for now:** one Butler account (the owner's). Emily signs in with it. Build the
  standard Owner + Co-Owner two-account cap anyway, so adding her later is just registering.
- **Shared app keys for now:** the owner's Shopping List and Reminders phone keys, in Butler's `.env`.
- v1 tools = exactly the ones ControlPanel shipped in e1defdc (same behaviour, same speakable replies).
- Box: LAN port **:129**, PHP 8.5, SQLite. The main session does all box setup (nginx, repo clone,
  `.env`, keys, tunnel, DNS).

## Read first

- ControlPanel commit `e1defdc` (`git -C ..\ControlPanel show --stat e1defdc`) — the connector
  you are porting: `app/Mcp/**`, `app/Support/ControlPanel/{HouseholdApps,ShortcutVerbs,VerbResult,RunningSessions,PublicHost}.php`,
  `app/Http/Middleware/{DenyFraming,SecureSessionCookieOnHttps,ThrottleMcpByIp}.php`,
  `routes/ai.php`, `config/mcp.php`, `resources/views/mcp/authorize.blade.php`,
  `resources/views/auth/signed-in.blade.php`, the login limiter in `AppServiceProvider`,
  `bootstrap/app.php`, and `tests/Feature/HouseholdConnector*Test.php`, `tests/Feature/HouseholdToolsTest.php`,
  `tests/Browser/McpConsentTest.php`. Also ControlPanel's `docs/todo/household-connector.md`.
- ControlPanel `app/Http/Controllers/Api/ShortcutController.php` + `routes/api.php` (the API Butler calls).
- `C:\Users\binar\Documents\HUB\STANDARDS.md` (sign-in screen, Dusk, Owner + Co-Owner cap).
- `C:\Users\binar\Documents\websites\ShoppingList\api` as the pattern for composer gate scripts
  (`composer test` / `lint` / `analyse` / `dusk`), Larastan level 7.
- Memory notes: `C:\Users\binar\.claude\projects\C--Users-binar-Documents-HUB\memory\laravel-gotchas.md`,
  `household-connector.md`.

## Part A — ControlPanel: two more Shortcut verbs

In `C:\Users\binar\Documents\websites\ControlPanel` (main, clean tree):
- `GET /api/shortcut/running` → the running Remote Control sessions (same data the MCP
  `list_running_sessions` tool returns, via `ShortcutVerbs` / `RunningSessions`).
- `POST /api/shortcut/end` with `pid` → `win.end-claude` (pid validated by the registry pattern).
- Same `{ok, message, …}` shape, same `['lan','api-token']` middleware, logged as the token's user.
  Feature tests. Document both in the controlpanel skill's Shortcut API section.
- **Do not remove** ControlPanel's MCP/Passport code in this packet. That's packet 2, after Butler
  is live.

## Part B — Butler

1. **New app:** Laravel 13 (`composer create-project laravel/laravel Butler`), PHP ^8.3, Blade +
   Alpine + Tailwind like ControlPanel (no Inertia, no Wayfinder). SQLite. Breeze-style auth.
   `git init` on `main`. A private GitHub repo is created by the main session.
2. **Accounts:** sign-in screen per STANDARDS.md. Registration allows at most two accounts
   (first = Owner, second = Co-Owner), then closes. Also add an artisan command
   `butler:make-owner {email} {name}` that creates the owner with a random password and prints it
   once, so the main session can seed the box. No per-account API token page (Butler has no phone API).
3. **Port the connector** from ControlPanel e1defdc: laravel/mcp + Passport, `Mcp::oauthRoutes`,
   `/oauth/register` with its own throttle, `redirect_domains` claude.ai + claude.com, the
   7-day / 90-day token lifetimes, device grant off, the consent screen, the signed-in landing page,
   DenyFraming, SecureSessionCookieOnHttps, ThrottleMcpByIp ahead of `auth:api`, per-IP + per-email
   login limits, JSON 401s on `/mcp`. The server is named **Butler** ("Butler wants to…" wording
   becomes "Claude wants to use Butler for you").
   - Butler is public as a whole (the tunnel will forward every path), so **drop** the
     `PublicHost`/`lan` split. Every page except sign-in and the OAuth endpoints needs a login.
   - `trustProxies(at: '*')`. Metadata URLs must come out https on the forwarded public host
     (keep that test). APP_URL on the box will be `https://mcp.thecorgisquad.com`.
4. **Tools.** Same 15 tools, names, schemas and reply style as ControlPanel's:
   - PC + session tools call ControlPanel over HTTP: `{controlpanel_url}/api/shortcut/{wake,sleep,status,projects,session,recent,resume,running,end}`
     with `X-Api-Token`. Pass ControlPanel's `message` through as the spoken sentence and keep the
     structured details. Map 401/403/422/unreachable to friendly sentences like HouseholdApps does.
   - Shopping List + Reminders tools: port `HouseholdApps` as-is.
   - Config `config/butler.php`: `controlpanel_url` (`BUTLER_CONTROLPANEL_URL`, default
     `http://127.0.0.1:85`), `controlpanel_token` (`BUTLER_CONTROLPANEL_TOKEN`), and
     `shoppinglist_url/token`, `reminders_url/token` (`BUTLER_SHOPPINGLIST_*`, `BUTLER_REMINDERS_*`,
     defaults `http://127.0.0.1:124` / `http://127.0.0.1:90`). A missing key gives a friendly tool
     error. Never log key values. Add the keys, empty, to `.env.example`.
   - Structure the app clients (one small class per app: `ControlPanelClient`, `ShoppingListClient`,
     `RemindersClient`) so adding the next app is one client + its tools.
5. **Home page** (signed in): a plain list of the tools in friendly words, plus the last 25
   **activity rows**. Add an `activity` table: user, tool, ok, the spoken message (truncated), time.
   Never store keys or raw responses. Phone-width rows, no cards (owner preference), no horizontal scroll.
6. **Box files in the repo:** `provisioning/nginx/butler` (listen 129, root `public`, php8.5-fpm
   socket `unix:/run/php/php8.5-fpm.sock`, `client_max_body_size` small, modelled on ControlPanel's
   vhost), and a `README.md`.
7. **Project docs:** `CLAUDE.md` (what Butler is, gates, deploy =
   `ssh gemini@192.168.0.164 "bash /home/gemini/websites/deploy.sh Butler"` after push, Passport keys
   live only on the box, how to add a new app + tools), `.claude/skills/butler/SKILL.md`, and
   `ROSTER.md` per the global `project-roster` skill (id `butler`, family `household`, status
   `building`, connects to controlpanel, shopping-list, reminders, cloudflare). Copy this brief to
   `docs/todo/butler.md` in Butler.

## Gates (you run them; the main session re-runs them)

- **Butler:** `composer test` (Pest or PHPUnit, your choice; state which), `composer lint` (Pint
  `--test`), `composer analyse` (Larastan level 7), `npm run build`, and Dusk (sign-in, consent
  screen + Approve, phone width with no sideways scroll). Run headless, never open visible windows
  or take focus. Port every OAuth/hardening test from ControlPanel plus tool tests with `Http::fake()`
  (assert headers sent, friendly errors, ControlPanel message passthrough).
- **ControlPanel:** `php artisan test` (baseline 3 known failures), `vendor/bin/pint --test` on
  changed files.

## Do not

- Commit, push, deploy, SSH to the box, create GitHub repos, or touch Cloudflare.
- Read or print any `.env` values or keys.
- Change Shopping List or Reminders.

## Report back

Files changed in both repos, gate output, choices made where this brief was silent, and the exact
box-side steps for the main session (env keys, `passport:keys`, `butler:make-owner`, anything else).
