# ControlPanel — Windows actions runbook (as-built)

Operations companion to [`README.md`](README.md). The README is the *install* recipe;
this is the **as-built state**, the **verify / un-gate / re-gate** procedures, and the
**gotchas** learned bringing `win.wake` / `win.sleep` / `win.launch-claude` live.

The three `win.*` actions run over SSH from the mini-PC's php-fpm (`www-data`) to the
Windows PC. `win.wake` is pure-PHP Wake-on-LAN (no SSH). See the privilege-bridge section
of the `controlpanel` skill for the trust model.

## As-built values (commissioned 2026-07-11)

| Thing | Value |
|---|---|
| Windows target | host `Gemini`, `192.168.0.197`, user `binar` (a local **admin**) |
| NIC MAC (WoL) | `34:5A:60:BB:6F:81` |
| Prod `.env` (box) | `CP_WIN_HOST=192.168.0.197`, `CP_WIN_MAC=34:5A:60:BB:6F:81`, `CP_WIN_USER=binar`, `CP_DISABLED_ACTIONS=` (empty → all live) |
| Wrapper config (box) | `/opt/controlpanel/bin/config.env`: `WIN_HOST=192.168.0.197`, `WIN_USER=binar`, `WIN_SSH_KEY=/opt/controlpanel/ssh/id_ed25519` |
| www-data SSH key | `/opt/controlpanel/ssh/id_ed25519` (www-data-owned, `600`) · fingerprint `SHA256:KTCW8jpePD78E74QmIQpyOYTIgtVqwz5LLaGc9T+aXk` |
| Public key | `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBG5ym6R5HBJ1H2/XTJygf5Mu/2bmHgszu+Q3SlBuTY3 www-data@minipc` |
| `known_hosts` (box) | `/opt/controlpanel/ssh/known_hosts` — populated with `.197`'s host key |
| Windows authorized_keys | `C:\ProgramData\ssh\administrators_authorized_keys` (admin file, ACL: Administrators/SYSTEM only) |
| Windows sshd | OpenSSH Server — Running, StartType Automatic; firewall rule "OpenSSH SSH Server (sshd)" Enabled, Private profile, Allow, TCP 22 |
| App CIDR allow-list | `CP_ALLOWED_CIDRS="192.168.0.0/24,127.0.0.1/32,::1/128,100.64.0.0/10"` — LAN **plus the Tailscale tailnet** |

## Network reachability (LAN + Tailscale)

The site listens on **:85**, gated two ways:

- **UFW:** `85/tcp ALLOW 192.168.0.0/24`, plus a blanket `ALLOW IN on tailscale0` — so
  tailnet traffic reaches the app regardless of the :85 rule.
- **App middleware `RequireLocalNetwork`:** rejects (403 "only reachable from the local
  network") any client IP not in `config('control_panel.network.allowed_cidrs')`
  (from `CP_ALLOWED_CIDRS`). This is checked against `$request->ip()` (the real client IP).

The mini-PC is on Tailscale as `minipc` / `100.76.100.37` (MagicDNS:
`minipc.jackal-hippocampus.ts.net`). Reaching the panel by the `.ts.net` name — even from a
device on the same home WiFi — arrives over the tailnet with a **`100.64.0.0/10` (CGNAT)**
source IP, *not* a `192.168.0.x` LAN IP. That's why the iPhone got a 403 until
`100.64.0.0/10` was added to `CP_ALLOWED_CIDRS` (2026-07-11).

Trade-off of allowing the whole `/10`: **any** device on the tailnet (including devices
shared into it by other users) can now reach the login page — still password-gated, but a
wider surface than LAN-only. To tighten without losing remote access, either narrow
`CP_ALLOWED_CIDRS` to specific device `/32`s, or (better) restrict who can reach `minipc:85`
with a **Tailscale ACL** and keep the app check as `/10` defense-in-depth.

## Verify the SSH round-trip (safe, read-only)

Run from the mini-PC (`gemini`, needs the sudo password to become `www-data`):

```bash
sudo -u www-data ssh -i /opt/controlpanel/ssh/id_ed25519 \
  -o StrictHostKeyChecking=accept-new \
  -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
  -o BatchMode=yes -o ConnectTimeout=10 \
  binar@192.168.0.197 whoami
```

**Green** = prints `gemini\binar`, exit `0`. (Windows OpenSSH returns `HOST\user`, so the
value is `gemini\binar`, **not** `binar` — see gotchas.) A post-quantum warning on stderr
is normal.

## Un-gate / re-gate the actions

Gating is env-only — no code change. `CP_DISABLED_ACTIONS` is a comma-separated list of
action ids that render greyed and are refused server-side (403).

```bash
cd /home/gemini/websites/ControlPanel
# Re-gate (hide until Windows side is up again):
sed -i 's|^CP_DISABLED_ACTIONS=.*|CP_DISABLED_ACTIONS=win.wake,win.sleep,win.launch-claude|' .env
# Un-gate (all live):
sed -i 's|^CP_DISABLED_ACTIONS=.*|CP_DISABLED_ACTIONS=|' .env
# Either way, rebuild the cache and confirm:
php artisan config:cache
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); var_dump(config("control_panel.disabled"));'
```

`config('control_panel.disabled')` should be `array(0) {}` when fully un-gated.

**Only un-gate after the round-trip above returns `gemini\binar`, exit 0.**

## Gotchas

- **`whoami` returns `gemini\binar`, not `binar`.** Windows OpenSSH prints `HOST\user`.
  Any health check that asserts exact-equals `binar` will false-fail — match on exit code
  or a `\binar` suffix instead.
- **Post-quantum warning on stderr.** Each connect prints a "store now, decrypt later" /
  "connection is not using a post-quantum key exchange algorithm" warning. Harmless, but it
  lands in captured stderr / action output — don't treat it as an error.
- **Never health-check with `win.sleep`.** It actually sleeps `192.168.0.197`, dropping the
  SSH path. Safe live check is `win.wake` (WoL magic packet only) or just confirm the buttons
  render as **Run** rather than **Disabled**.
- **`win.launch-claude` needs a Scheduled Task per project key.** Firing key `<k>` triggers a
  Windows Scheduled Task `LaunchClaudeSession_<k>` on the interactive desktop. The task must
  exist for the action to do anything.
- **`config/control_panel.php` was edited directly on the box** to add the test project
  `magic-deck-builder`. The local dev repo's copy is *untracked* and its working tree has
  heavy uncommitted divergence — reconcile before any future full rsync/deploy or you may
  revert box state. (Deploy safely lands a single file with `scp` + `deploy.sh ControlPanel`.)

## Model picker + list/end sessions (added 2026-07-20)

Three capabilities were added around Claude sessions:

- **`win.launch-claude` gained a model dropdown.** The launch card now has a
  second `<select>` (Default / Opus 4.8 / Sonnet 5 / Fable 5, from
  `config('control_panel.models')`). The panel sends only the model **id** as
  `arg2`; the box wrapper writes it to a per-project sentinel and fires the task;
  `claude-session.ps1` maps the id → real `--model` string.
- **`win.end-claude` (new, destructive).** A dropdown of **live** sessions with an
  **End** button. The dropdown is filled by `GET /actions/sessions`
  (→ `win-list-claude.sh`, read-only, not logged). Ending sends the session's PID
  as `arg` (digits-guarded); `claude-session.ps1 -Action end` only kills a PID it
  recorded, re-checking the process start time to defeat PID reuse.
- **`win.list-claude` (new, hidden).** Utility action behind the sessions
  endpoint — not rendered as a card.

**Box install (mini-PC project handoff).** No sudoers change — all three are
`ssh`-handler (www-data key, no sudo). Just land the updated `provisioning/bin`
and reinstall the wrappers:
```bash
cd /home/gemini/websites/ControlPanel
sudo install -o root -g root -m 755 provisioning/bin/win-launch-claude.sh \
  provisioning/bin/win-list-claude.sh provisioning/bin/win-end-claude.sh /opt/controlpanel/bin/
php artisan migrate --force        # adds action_logs.arg2
php artisan config:cache route:cache view:cache
```

**Windows install (handoff).** Copy `provisioning/windows/claude-session.ps1` to
`C:\ProgramData\ControlPanel\bin\` and repoint each `LaunchClaudeSession_<key>`
task action to `-Action launch -Project <key> -Dir "<rootPath>"` (see README §1.4).
Until a task is repointed, launching that project still works but carries no model
and won't appear in the end-session list.

**Verify (read-only first).** From the box, as www-data:
```bash
sudo -u www-data /opt/controlpanel/bin/win-list-claude.sh   # → JSON array (…[] if none)
```
Then in the UI: Launch a project with **Opus 4.8** → it should appear in the
End-session dropdown as `controlpanel (pid N) · opus-4-8`; **End** it → it drops
off the list on the next refresh.

## Known-open / not-yet-verified

- **BIOS/UEFI Wake-on-LAN** must be enabled in firmware for `win.wake` to boot a *fully-off*
  machine. The OS/NIC side (Fast Startup off, "Wake on Magic Packet" on) is done, but firmware
  can't be scripted — enable it in UEFI by hand. Waking from **sleep** works without it.
- **`win.launch-claude` invocation is a best guess.** The scheduled task currently runs
  `claude --remote-control-session-name-prefix 'magic-deck-builder'` because the installed CLI
  has no `--remote-control` flag (the README example's `claude --remote-control '<project>'` is
  known-wrong for this CLI version). If firing it doesn't surface a controllable session on
  claude.ai, the fix is in the **scheduled task command** on the Windows PC, not the box —
  adjust the task action and re-test.

## If the round-trip breaks later

| Symptom | Likely cause |
|---|---|
| `Connection timed out` on port 22 | PC off/asleep, OpenSSH Server stopped, or firewall rule disabled. `ping` + a TCP probe to `:22` from the box isolates it. |
| `Permission denied (publickey)` | Key not authorized correctly on Windows. Because `binar` is an admin, the key **must** be in `C:\ProgramData\ssh\administrators_authorized_keys` (not `~\.ssh`) with ACL locked to Administrators/SYSTEM. |
| host-key prompt/error | First contact — `-o StrictHostKeyChecking=accept-new` handles it and writes `known_hosts`. If the Windows host key changed (reinstall), remove the stale `known_hosts` line. |
