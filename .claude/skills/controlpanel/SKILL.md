---
name: controlpanel
description: Operate the ControlPanel app — the sixth Laravel site on the mini-PC (port 85), a login-gated LAN-only dashboard that runs predefined actions (wake/sleep the Windows PC, launch a Claude remote-control session, deploy sites, reload services, WoL/ping LAN devices). Use to deploy/health-check/log/monitor ControlPanel, manage its queue worker, read its action_logs, toggle actions on/off, reset the admin password, or understand its privilege bridge. For ADDING or CHANGING an action use controlpanel-actions. Builds on minipc-ssh, minipc-sites, minipc-admin.
---

# controlpanel — operate the ControlPanel dashboard

ControlPanel is the 6th Laravel site on the mini-PC. It presents a login-gated, **LAN-only**
dashboard of **predefined actions** (no free-form command box) that act on the Windows PC, the
mini-PC itself, and other LAN devices. It runs like the other sites (Nginx + PHP-FPM 8.5 + SQLite)
plus a Redis queue worker for slow actions. All remote commands use SSH — see `minipc-ssh`.

## Key facts

| Fact | Value |
|---|---|
| Port | **85** (UFW: LAN-only, `192.168.0.0/24`) |
| Path (box) | `/home/gemini/websites/ControlPanel/` |
| Local dev repo | `C:\Users\binar\Documents\websites\ControlPanel` |
| DB | SQLite `database/database.sqlite` (owner `gemini:www-data`) + `action_logs` table |
| Queue | Redis + Supervisor program `controlpanel-worker` (async deploy/cache actions) |
| Auth | single seeded admin; **registration disabled**. Creds in `.env` `CP_ADMIN_*` |
| GitHub remote? | no by default (deploy skips `git pull`) |

Registry of actions lives in code: `app/Support/ControlPanel/ActionRegistry.php`. Instance config
(Windows MAC/host, LAN devices, VSCode projects, disabled actions) lives in
`config/control_panel.php` + `.env` `CP_*`.

## Privilege bridge (how it runs system commands)

php-fpm runs as **www-data**, which has NO general sudo. ControlPanel only runs fixed, root-owned
wrapper scripts via a narrow sudoers grant:

- Scripts: `/opt/controlpanel/bin/*.sh` (root:root, 0755, NOT www-data-writable).
- Sudoers: `/etc/sudoers.d/controlpanel` (0440) — NOPASSWD for exactly those scripts.
- Windows control: dedicated **www-data-owned** SSH key `/opt/controlpanel/ssh/id_ed25519`; host/user
  in `/opt/controlpanel/bin/config.env`.
- WoL is pure PHP (no script, no sudo).

The repo ships all of this under `provisioning/` (scripts, sudoers, nginx, supervisor, README).
First-time setup follows `provisioning/README.md`. To add/modify a capability, use
**controlpanel-actions** — never widen sudoers by hand without following that recipe.

## Deploy

Once ControlPanel is added to `deploy.sh` (site allowlist), deploy like any other site:
```bash
ssh gemini@192.168.0.164 "bash /home/gemini/websites/deploy.sh ControlPanel"
```
No GitHub remote → it skips `git pull` but still runs composer/npm/build/migrate/cache/queue:restart
(see `minipc-sites`). If you edited code locally, land it on the box first (git push+pull, or rsync)
before deploying. **First-ever install** (Nginx block, UFW, sudoers, scripts, worker) is the one-time
procedure in `C:\Users\binar\Documents\websites\ControlPanel\provisioning\README.md`.

## Health check

```bash
ssh gemini@192.168.0.164 "curl -s -o /dev/null -w 'HTTP %{http_code}\n' http://127.0.0.1:85/"
```
Expect `302` (redirects to `/login`). The app also exposes `/up` (Laravel health) → `200`.

## Logs

```bash
# application log
ssh gemini@192.168.0.164 "tail -50 /home/gemini/websites/ControlPanel/storage/logs/laravel.log"
# queue worker log (async actions: deploy, rebuild-cache)
ssh gemini@192.168.0.164 "tail -50 /home/gemini/websites/ControlPanel/storage/logs/worker.log"
```
Nginx errors need sudo — see `minipc-admin`.

## The queue worker (async actions)

Async actions (deploy, rebuild-cache) run through Supervisor program `controlpanel-worker`.
```bash
# status / restart (sudo — see minipc-admin for the SUDO_PASSWORD pattern)
ssh gemini@192.168.0.164 "echo '<SUDO_PASSWORD>' | sudo -S supervisorctl status controlpanel-worker:*"
ssh gemini@192.168.0.164 "echo '<SUDO_PASSWORD>' | sudo -S supervisorctl restart controlpanel-worker:*"
```
After a code deploy, `deploy.sh` runs `queue:restart` so the worker reloads. If async actions sit in
`pending`/`running` forever, the worker is down — check its status and the worker log.

## Read the action audit log

Every action (who/what/arg/exit code/output) is written to the `action_logs` table.
```bash
ssh gemini@192.168.0.164 "cd /home/gemini/websites/ControlPanel && php artisan tinker" <<'EOF'
foreach (App\Models\ActionLog::latest()->limit(10)->get() as $l) {
    echo "{$l->created_at} {$l->action_id} ".($l->arg ?? '-')." => {$l->status} (exit {$l->exit_code})".PHP_EOL;
}
EOF
```

## Turn actions on/off (no code change)

Grey out actions via env (comma-separated ids), e.g. to hide destructive ones until ready:
```bash
# in /home/gemini/websites/ControlPanel/.env
CP_DISABLED_ACTIONS=mini.reboot,win.sleep
```
Then `php artisan config:cache`. Disabled actions render greyed and are refused server-side (403).

## Reset / change the admin login

The only account is the seeded admin. Change the password by updating `.env` `CP_ADMIN_PASSWORD`
then re-seeding (idempotent), or directly via tinker:
```bash
ssh gemini@192.168.0.164 "cd /home/gemini/websites/ControlPanel && php artisan db:seed --force"
# or set a one-off password:
ssh gemini@192.168.0.164 "cd /home/gemini/websites/ControlPanel && php artisan tinker" <<'EOF'
$u = App\Models\User::first();
$u->password = Illuminate\Support\Facades\Hash::make('NEW-PASSWORD-HERE');
$u->save();
echo "updated ".$u->email.PHP_EOL;
EOF
```
(For app-user management on the *other* sites, use `minipc-users`.)

## Safety

- Probe read-only first; announce destructive/system changes. `mini.reboot` takes the box (and the
  panel) offline; suspend-the-host is intentionally not offered.
- Never echo `SUDO_PASSWORD`, the www-data SSH private key, or admin passwords into logs/files.
- Keep the `sites` allowlist in `config/control_panel.php` in sync with the `case` allowlist inside
  `deploy-site.sh` / `rebuild-cache.sh`.
- To change what actions exist or what they run, use **controlpanel-actions** — do not hand-edit
  sudoers or add shell strings to the app.
