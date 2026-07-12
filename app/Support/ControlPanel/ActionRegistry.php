<?php

namespace App\Support\ControlPanel;

use Illuminate\Validation\ValidationException;

/**
 * The single source of truth for what the control panel is allowed to do.
 * Actions live in code (not user-editable config) so the allowlist can't be
 * tampered with through the app. Per-instance gating is via config('control_panel.disabled').
 */
class ActionRegistry
{
    /** @var array<int, Action>|null */
    private ?array $cache = null;

    /** @return array<int, Action> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $disabled = config('control_panel.disabled', []);

        $defs = [
            // ---- Windows PC -------------------------------------------------
            new Action(
                id: 'win.wake', label: 'Wake Windows PC', category: 'Windows',
                handler: 'wol', description: 'Send a Wake-on-LAN magic packet to the Windows PC.',
            ),
            new Action(
                id: 'win.sleep', label: 'Sleep Windows PC', category: 'Windows',
                handler: 'ssh', script: 'win-sleep.sh', destructive: true,
                description: 'SSH into Windows and put it to sleep.', timeout: 20,
            ),
            new Action(
                id: 'win.launch-claude', label: 'Launch Claude session', category: 'Windows',
                handler: 'ssh', script: 'win-launch-claude.sh', argKind: 'project',
                description: 'Start a Claude Code remote-control session in a VSCode project on Windows.',
                timeout: 30,
            ),
            // Device wake/ping with a chooser (ids keep the historical lan.* prefix).
            new Action(
                id: 'lan.wake', label: 'Wake device', category: 'Windows',
                handler: 'wol', argKind: 'device',
                description: 'Send a Wake-on-LAN packet to a chosen device.',
            ),
            new Action(
                id: 'lan.ping', label: 'Ping device', category: 'Windows',
                handler: 'inline', script: 'ping', argKind: 'device',
                description: 'Ping a chosen device (read-only).', timeout: 15,
            ),

            // ---- Mini-PC ----------------------------------------------------
            new Action(
                id: 'mini.health', label: 'Health check', category: 'Mini-PC',
                handler: 'inline', script: 'health',
                description: 'Disk, memory, uptime and load (read-only).', timeout: 20,
            ),
            new Action(
                id: 'mini.reload-nginx', label: 'Reload Nginx', category: 'Mini-PC',
                handler: 'script', script: 'reload-nginx.sh', runAs: 'root',
                description: 'nginx -t then reload.', timeout: 30,
            ),
            new Action(
                id: 'mini.restart-phpfpm', label: 'Restart PHP-FPM', category: 'Mini-PC',
                handler: 'script', script: 'restart-phpfpm.sh', runAs: 'root', destructive: true,
                description: 'Restart the php8.5-fpm service.', timeout: 30,
            ),
            new Action(
                id: 'mini.deploy', label: 'Deploy a site', category: 'Mini-PC',
                handler: 'script', script: 'deploy-site.sh', runAs: 'gemini', argKind: 'site',
                async: true, destructive: true, timeout: 900,
                description: 'Run deploy.sh for the selected site.',
            ),
            new Action(
                id: 'mini.rebuild-cache', label: 'Rebuild caches', category: 'Mini-PC',
                handler: 'script', script: 'rebuild-cache.sh', runAs: 'gemini', argKind: 'site',
                async: true, timeout: 300,
                description: 'Clear + rebuild config/route/view caches for a site.',
            ),
            new Action(
                id: 'mini.reboot', label: 'Reboot mini-PC', category: 'Mini-PC',
                handler: 'script', script: 'reboot-host.sh', runAs: 'root', destructive: true,
                description: 'Reboot the mini-PC. The panel will be offline until it comes back.',
                timeout: 20,
            ),
        ];

        foreach ($defs as $action) {
            $action->enabled = ! in_array($action->id, $disabled, true);
        }

        return $this->cache = $defs;
    }

    public function find(string $id): ?Action
    {
        foreach ($this->all() as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Validate (and pass through) the single argument an action may carry.
     * Throws a 422 ValidationException on anything not on the allowlist.
     */
    public function validateArg(Action $action, ?string $arg): ?string
    {
        return match ($action->argKind) {
            'none' => null,
            'site' => $this->ensureIn($arg, config('control_panel.sites', []), 'site'),
            'device' => $this->ensureIn($arg, array_column(config('control_panel.devices', []), 'id'), 'device'),
            'project' => $this->ensureIn($arg, array_keys(config('control_panel.projects', [])), 'project'),
            default => throw ValidationException::withMessages(['arg' => "Unknown argument kind for {$action->id}."]),
        };
    }

    /** @param array<int, string> $allowed */
    private function ensureIn(?string $arg, array $allowed, string $kind): string
    {
        if ($arg === null || ! in_array($arg, $allowed, true)) {
            throw ValidationException::withMessages(['arg' => "Invalid {$kind}."]);
        }

        return $arg;
    }
}
