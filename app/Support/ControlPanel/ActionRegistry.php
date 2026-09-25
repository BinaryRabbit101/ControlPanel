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
            // ---- Gemini (the owner's PC) -------------------------------------
            new Action(
                id: 'win.wake', label: 'Wake Gemini', category: 'Gemini',
                handler: 'wol', description: 'Send a Wake-on-LAN magic packet to Gemini.',
            ),
            new Action(
                id: 'win.sleep', label: 'Sleep Gemini', category: 'Gemini',
                handler: 'ssh', script: 'win-sleep.sh', destructive: true,
                description: 'SSH into Gemini and put it to sleep.', timeout: 20,
            ),
            new Action(
                id: 'win.launch-claude', label: 'Start Session', category: 'Gemini',
                handler: 'ssh', script: 'win-launch-claude.sh', argKind: 'project',
                description: 'Start a Claude Code remote-control session in a VSCode project on Windows.',
                timeout: 30,
            ),
            new Action(
                id: 'win.end-claude', label: 'End Session', category: 'Gemini',
                handler: 'ssh', script: 'win-end-claude.sh', argKind: 'session', destructive: true,
                description: 'Stop a running Claude remote-control session on Windows.',
                timeout: 20,
            ),
            new Action(
                id: 'win.ping', label: 'Ping Gemini', category: 'Gemini',
                handler: 'inline', script: 'ping', device: 'windows-pc',
                description: 'Is Gemini awake? (read-only)', timeout: 15,
            ),
            // Utility (not a card): backs the live end-session dropdown. Read-only.
            new Action(
                id: 'win.list-claude', label: 'List Claude sessions', category: 'Gemini',
                handler: 'ssh', script: 'win-list-claude.sh', hidden: true,
                description: 'List running Claude sessions on Windows (read-only, JSON).',
                timeout: 20,
            ),

            // ---- Franklin (her PC) ------------------------------------------
            new Action(
                id: 'franklin.wake', label: 'Wake Franklin', category: 'Franklin',
                handler: 'wol', device: 'franklin',
                description: 'Send a Wake-on-LAN magic packet to Franklin.',
            ),
            new Action(
                id: 'franklin.sleep', label: 'Sleep Franklin', category: 'Franklin',
                handler: 'ssh', script: 'franklin-sleep.sh', destructive: true,
                description: 'SSH into Franklin and put it to sleep.', timeout: 20,
            ),
            new Action(
                id: 'franklin.ping', label: 'Ping Franklin', category: 'Franklin',
                handler: 'inline', script: 'ping', device: 'franklin',
                description: 'Is Franklin awake? (read-only)', timeout: 15,
            ),

            // ---- Mini-PC ----------------------------------------------------
            new Action(
                id: 'mini.health', label: 'Health check', category: 'Mini-PC',
                handler: 'inline', script: 'health',
                description: 'Disk, memory, uptime and load (read-only).', timeout: 20,
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
     * Validate (and pass through) the primary argument an action may carry.
     * Throws a 422 ValidationException on anything not on the allowlist.
     */
    public function validateArg(Action $action, ?string $arg): ?string
    {
        return $this->validateKind($action, $action->argKind, $arg, 'arg');
    }

    /**
     * Validate (and pass through) the optional secondary argument (e.g. model).
     */
    public function validateArg2(Action $action, ?string $arg): ?string
    {
        return $this->validateKind($action, $action->argKind2, $arg, 'arg2');
    }

    private function validateKind(Action $action, string $kind, ?string $arg, string $field): ?string
    {
        return match ($kind) {
            'none' => null,
            'site' => $this->ensureIn($arg, config('control_panel.sites', []), 'site', $field),
            'device' => $this->ensureIn($arg, array_column(config('control_panel.devices', []), 'id'), 'device', $field),
            'project' => $this->ensureIn($arg, array_keys(config('control_panel.projects', [])), 'project', $field),
            // Optional: absent model means "launch with the account default".
            'model' => ($arg === null || $arg === '')
                ? null
                : $this->ensureIn($arg, array_column(config('control_panel.models', []), 'id'), 'model', $field),
            // Dynamic (live) list: the real allowlist is enforced Windows-side,
            // which only kills PIDs it recorded. Here we just guard the shape.
            'session' => $this->ensurePid($arg, $field),
            default => throw ValidationException::withMessages([$field => "Unknown argument kind for {$action->id}."]),
        };
    }

    private function ensurePid(?string $arg, string $field): string
    {
        if ($arg === null || preg_match('/^[1-9][0-9]{0,6}$/', $arg) !== 1) {
            throw ValidationException::withMessages([$field => 'Invalid session.']);
        }

        return $arg;
    }

    /** @param array<int, string> $allowed */
    private function ensureIn(?string $arg, array $allowed, string $kind, string $field = 'arg'): string
    {
        if ($arg === null || ! in_array($arg, $allowed, true)) {
            throw ValidationException::withMessages([$field => "Invalid {$kind}."]);
        }

        return $arg;
    }
}
