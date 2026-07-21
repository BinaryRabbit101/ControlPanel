# ControlPanel — provisioning

Everything the Laravel app can't ship in its own repo: the privileged wrapper
scripts, the sudoers grant, the Nginx/Supervisor units, and the one-time
Windows setup. Do these on the **mini-PC** (as `gemini`, using `sudo`) and on the
**Windows PC** as noted.

> Order matters — do the Windows prerequisites and the SSH round-trip **before**
> wiring the SSH-based actions. Verify each stage before moving on.

## 1. Windows PC prerequisites (one-time)

1. **OpenSSH Server**
   ```powershell
   Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0
   Start-Service sshd
   Set-Service sshd -StartupType Automatic
   # firewall: allow TCP 22 from the LAN only
   New-NetFirewallRule -Name sshd-lan -DisplayName "OpenSSH (LAN)" -Enabled True `
     -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22 -RemoteAddress 192.168.0.0/24
   ```
2. **Authorize the mini-PC www-data key** (created in step 2 below). Because the
   Windows user is an admin, the public key goes in
   `C:\ProgramData\ssh\administrators_authorized_keys` (NOT `~\.ssh`), owned by
   Administrators/SYSTEM only:
   ```powershell
   # paste the www-data public key as one line
   Add-Content C:\ProgramData\ssh\administrators_authorized_keys '<PUBKEY>'
   icacls C:\ProgramData\ssh\administrators_authorized_keys /inheritance:r /grant "Administrators:F" "SYSTEM:F"
   ```
3. **Wake-on-LAN**: enable in BIOS/UEFI; NIC → Advanced → "Wake on Magic Packet"
   = Enabled and Power Management → "Allow this device to wake the computer";
   **disable Fast Startup** (Control Panel → Power). Record the NIC MAC
   (`getmac /v`) and set a DHCP reservation for a stable IP. Put the MAC/IP/user
   into the site `.env` (`CP_WIN_MAC`, `CP_WIN_HOST`, `CP_WIN_USER`).
4. **Claude session manager** (enables model choice + list/end). Install the
   Windows-side script and route the launch tasks through it:
   ```powershell
   # install the manager (source of truth: provisioning/windows/claude-session.ps1)
   New-Item -ItemType Directory -Force C:\ProgramData\ControlPanel\bin | Out-Null
   Copy-Item .\provisioning\windows\claude-session.ps1 C:\ProgramData\ControlPanel\bin\
   ```
   **Per project**, create a Scheduled Task `LaunchClaudeSession_<project>`,
   "Run only when user is logged on", whose action runs the manager's `launch`
   (it reads the model the panel chose, starts claude, and records the session so
   it can be listed/ended):
   ```
   Program:   powershell.exe
   Arguments: -NoProfile -ExecutionPolicy Bypass -File C:\ProgramData\ControlPanel\bin\claude-session.ps1 -Action launch -Project <project> -Dir "C:\path\to\project"
   ```
   `-Dir` is that project's root (from the VS Code Project Manager store
   `%APPDATA%\Code\User\globalStorage\alefragnani.project-manager\projects.json`).
   Add `<project>` to the `projects` array in `config/control_panel.php`.
   Requires the Windows user to be logged in to claude.ai (`claude auth login`),
   on a Pro/Max/Team plan, pointed at api.anthropic.com.

   > The panel passes only the model **id** (e.g. `opus-4-8`) over the wire;
   > `claude-session.ps1`'s `$ModelMap` maps it to the real `--model` string — the
   > second allowlist. Keep it in sync with `config('control_panel.models')`.
   > Migrating a project's task is drop-in: the old `claude --remote-control
   > '<project>'` still works but shows no model and won't appear in the
   > end-session list until it runs `-Action launch`.

## 2. Mini-PC: dedicated www-data SSH key

```bash
sudo install -d -o www-data -g www-data -m 700 /opt/controlpanel/ssh
sudo -u www-data ssh-keygen -t ed25519 -N '' -f /opt/controlpanel/ssh/id_ed25519
sudo -u www-data touch /opt/controlpanel/ssh/known_hosts
sudo cat /opt/controlpanel/ssh/id_ed25519.pub   # → paste into Windows (step 1.2)
```
Verify the round-trip BEFORE wiring buttons:
```bash
sudo -u www-data ssh -i /opt/controlpanel/ssh/id_ed25519 \
  -o UserKnownHostsFile=/opt/controlpanel/ssh/known_hosts \
  <WIN_USER>@<WIN_HOST> whoami
```

## 3. Mini-PC: wrapper scripts + sudoers

```bash
sudo install -d -o root -g root -m 755 /opt/controlpanel/bin
sudo install -o root -g root -m 755 provisioning/bin/*.sh /opt/controlpanel/bin/
sudo install -o root -g root -m 644 provisioning/bin/config.env.example /opt/controlpanel/bin/config.env
sudo -e /opt/controlpanel/bin/config.env        # set WIN_HOST / WIN_USER / WIN_SSH_KEY

# sudoers — validate first, then install
sudo visudo -cf provisioning/sudoers/controlpanel
sudo install -o root -g root -m 440 provisioning/sudoers/controlpanel /etc/sudoers.d/controlpanel
sudo -l -U www-data                              # should list exactly the CP scripts
```

## 4. Mini-PC: deploy the Laravel site (port 85)

```bash
# land the code at /home/gemini/websites/ControlPanel (git pull or rsync), then:
cd /home/gemini/websites/ControlPanel
composer install --no-dev --optimize-autoloader
cp .env.example .env    # or copy your prod .env; set APP_ENV=production, APP_DEBUG=false,
                        # APP_URL=http://192.168.0.164:85, QUEUE_CONNECTION=redis, CACHE_STORE=redis,
                        # and the CP_* vars (admin password, Windows MAC/host, allowed CIDRs)
php artisan key:generate
touch database/database.sqlite && sudo chown gemini:www-data database/database.sqlite && chmod 664 database/database.sqlite
php artisan migrate --force
php artisan db:seed --force        # creates the admin from CP_ADMIN_*
npm ci && npm run build
php artisan config:cache route:cache view:cache
sudo chown -R gemini:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache

# Nginx + firewall
sudo install -o root -g root -m 644 provisioning/nginx/controlpanel /etc/nginx/sites-available/controlpanel
sudo ln -sf /etc/nginx/sites-available/controlpanel /etc/nginx/sites-enabled/controlpanel
sudo nginx -t && sudo systemctl reload nginx
sudo ufw allow from 192.168.0.0/24 to any port 85 proto tcp

# Queue worker (for async deploy/cache-rebuild actions)
sudo install -o root -g root -m 644 provisioning/supervisor/controlpanel-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

Confirm: `curl -I http://127.0.0.1:85/` → 302 to `/login`; log in as the admin.

## 5. Verify each action (safe → risky)

1. Read-only: **Health check**, **Ping device** — confirm output + a row in Recent actions.
2. **Wake** (Windows/LAN) — with the target asleep; watch with `sudo tcpdump -i any udp port 9`.
3. Privileged: **Reload Nginx**, **Restart PHP-FPM** — confirm sudo grant works.
4. **Deploy a site** (async) — pick a low-risk site; watch it go pending → running → success.
5. **Sleep Windows**, **Launch Claude**, **Reboot mini-PC** last (destructive).

## Notes / trade-offs

- The `deploy.sh` allowlist is hard-coded in `deploy-site.sh` and mirrored in
  `config/control_panel.php` — keep them in sync when adding a site.
- `mini.reboot` takes the panel offline until the box returns; there is no
  completion feedback. Suspend-the-host is intentionally not offered.
- The www-data SSH key is a real secret — it grants the fixed Windows commands
  only. Keep it 600, www-data-owned; rotate by regenerating + re-authorizing.
- Sleep command: `SetSuspendState` hibernates if hibernation is enabled. If it
  hibernates instead of sleeping, disable hibernation or switch the wrapper to
  Sysinternals `psshutdown64 -d -t 0`.
