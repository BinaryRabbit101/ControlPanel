---
name: controlpanel-actions
description: Add or modify an action in the ControlPanel dashboard end-to-end — the registry entry, the wrapper script, the sudoers grant, and the allowlist — following the app's security model (no user-composed shell strings; args allowlisted twice). Use when the user wants a NEW button/capability in ControlPanel (e.g. "add an action to restart Redis", "let it wake my NAS", "add a deploy for a new site", "add a Claude project"), or to change what an existing action does. For running/deploying/monitoring ControlPanel use controlpanel. Builds on controlpanel, minipc-ssh, minipc-admin.
---

# controlpanel-actions — add or change a ControlPanel action

ControlPanel's safety rests on one invariant: **the web layer only picks an action id + an
allowlisted arg — it never composes a shell command.** Every privileged action runs a fixed,
root-owned wrapper script granted narrowly in sudoers. Adding a capability means touching each layer
in the right order. Edit the **local repo** (`C:\Users\binar\Documents\websites\ControlPanel`), then
deploy; install script/sudoers changes **on the box**.

## The four handler types

| Handler | Runs | Sudo? | Needs a wrapper script? |
|---|---|---|---|
| `wol` | pure-PHP Wake-on-LAN packet | no | no (uses config MAC) |
| `inline` | read-only PHP (`health`, `ping`) | no | no |
| `ssh` | wrapper that SSHes into Windows | no (www-data key) | yes |
| `script` | wrapper on the mini-PC | root or gemini | yes |

Pick the lightest handler that fits. Read-only or WoL/ping additions need **no** sudoers change.

## Recipe: add a `script` action (the fullest case)

Example: "add a button to restart Redis on the mini-PC."

**1. Wrapper script** — `provisioning/bin/restart-redis.sh` (single-purpose, absolute paths):
```bash
#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/systemctl restart redis-server
```
If the action takes an argument, guard it with an in-script `case` allowlist (see
`deploy-site.sh`) — this is the second of two allowlist checks.

**2. Registry entry** — add an `Action` in `app/Support/ControlPanel/ActionRegistry.php`:
```php
new Action(
    id: 'mini.restart-redis', label: 'Restart Redis', category: 'Mini-PC',
    handler: 'script', script: 'restart-redis.sh', runAs: 'root', destructive: true,
    description: 'Restart the redis-server service.', timeout: 30,
),
```
- `runAs`: `root` or `gemini` (never leave a privileged script at `none`).
- Slow (minutes)? set `async: true` so it runs on the queue worker and the UI polls.
- Takes an arg? set `argKind` to `site` | `device` | `project` and see step 4.

**3. Sudoers** — add the exact script path to `provisioning/sudoers/controlpanel`, keeping the
`Cmnd_Alias` tidy. Root actions go in `CP_ROOT`; gemini actions on the `(gemini)` line.
**Validate before installing:**
```bash
visudo -cf provisioning/sudoers/controlpanel   # must say "parsed OK"
```

**4. (If it takes an arg) allowlist it twice** — add the allowed values to
`config/control_panel.php` (e.g. `sites`, `devices`, or `projects`), which `ActionRegistry::validateArg`
enforces, AND mirror them in the wrapper script's `case` block. The Laravel check returns 422 on
anything off-list; the script check is defense-in-depth.

**5. Deploy + install + test.**
```bash
# land the code (git push+pull or rsync) then:
ssh gemini@192.168.0.164 "bash /home/gemini/websites/deploy.sh ControlPanel"
# install the new/changed script + sudoers ON THE BOX (root):
ssh gemini@192.168.0.164 "bash -s" <<'EOF'
sudo install -o root -g root -m 755 /home/gemini/websites/ControlPanel/provisioning/bin/restart-redis.sh /opt/controlpanel/bin/
sudo visudo -cf /home/gemini/websites/ControlPanel/provisioning/sudoers/controlpanel \
  && sudo install -o root -g root -m 440 /home/gemini/websites/ControlPanel/provisioning/sudoers/controlpanel /etc/sudoers.d/controlpanel
sudo -l -U www-data   # confirm the new script is listed
EOF
```
(The `sudo` calls need `SUDO_PASSWORD` piped to `sudo -S` — see `minipc-admin`.)
Then verify the grant from the web user BEFORE clicking the button:
```bash
ssh gemini@192.168.0.164 "sudo -u www-data sudo -n /opt/controlpanel/bin/restart-redis.sh; echo exit=\$?"
```

## Recipe: add an `ssh` (Windows) action

Wrapper sources `config.env` and pins a fixed remote command (model on `win-sleep.sh`). No sudoers
change. If it takes a token, charset-guard it (`*[!A-Za-z0-9_-]*`) like `win-launch-claude.sh`.
Install the script to `/opt/controlpanel/bin/` (root:root 0755). Add the registry entry with
`handler: 'ssh'`, `runAs: 'none'`.

## Recipe: add a device (WoL/ping) or a Claude project — config only

No code/script/sudoers change; edit `config/control_panel.php` then `php artisan config:cache`:
```php
'devices' => [
    ['id' => 'nas', 'label' => 'NAS', 'mac' => 'AA:BB:CC:DD:EE:FF', 'ip' => '192.168.0.50'],
],
'projects' => [
    'mini-pc' => 'Mini-PC management repo',   // needs a Windows Scheduled Task LaunchClaudeSession_mini-pc
],
```
`lan.wake`/`lan.ping` pick these up automatically; `win.launch-claude` offers each project key.

## Checklist before shipping an action

- [ ] Wrapper script is single-purpose, `set -euo pipefail`, absolute binary paths, root-owned.
- [ ] Any argument is allowlisted in BOTH `config/control_panel.php` and the script's `case`.
- [ ] Privileged script is in `sudoers.d/controlpanel` (validated with `visudo -cf`) — nowhere wider.
- [ ] Registry `runAs` matches the sudoers `(user)`; `async` set for slow actions.
- [ ] Tested read-only path first, then `sudo -u www-data sudo -n <script>` from a shell.
- [ ] No user input ever reaches a shell string — verify the handler uses array argv only.
