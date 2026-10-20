<?php

namespace App\Support\ControlPanel;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * A project's recent Claude sessions on Gemini, newest first, read over SSH by
 * win.recent-claude. Read-only and not written to action_logs (like the live
 * session list). Shared by the dashboard's "Resume recent" list and the
 * Shortcut API so both show the same rows.
 */
class RecentSessions
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionRunner $runner,
    ) {}

    /**
     * @return array{sessions: array<int, array{id: string, title: string, updated: string, ago: string, name: string, remote: bool, last_prompt: string, running: bool, reconnectable: bool, origin: string}>, error: ?string}
     */
    public function for(string $project): array
    {
        $definition = $this->registry->find('win.recent-claude');
        if ($definition === null || ! $definition->enabled) {
            return ['sessions' => [], 'error' => 'Recent sessions are unavailable.'];
        }

        // 422 on a project that isn't on the allowlist.
        $project = (string) $this->registry->validateArg($definition, $project);

        $result = $this->runner->run($definition, $project);
        if (! $result->ok) {
            return ['sessions' => [], 'error' => $this->reason($result->error)];
        }

        $decoded = json_decode($result->output, true);
        if (! is_array($decoded)) {
            return ['sessions' => [], 'error' => 'Gemini sent back something unexpected.'];
        }

        $sessions = [];
        foreach ($decoded as $row) {
            if (($session = $this->row($row)) !== null) {
                $sessions[] = $session;
            }
        }

        return ['sessions' => $sessions, 'error' => null];
    }

    /**
     * The newest sessions across every ControlPanel project, newest first,
     * from ONE call to Windows. Each row also carries `project` (key) and
     * `project_label`. Rows whose project isn't on the allowlist are dropped
     * and counted in `skipped`, with the remote sessions Windows found in
     * folders no launcher maps to. With $session (a UUID) only that session's
     * row comes back, whatever its age, so resume can learn its project.
     *
     * @return array{sessions: array<int, array<string, mixed>>, skipped: int, error: ?string}
     */
    public function all(?string $session = null): array
    {
        $definition = $this->registry->find('win.recent-all-claude');
        if ($definition === null || ! $definition->enabled) {
            return ['sessions' => [], 'skipped' => 0, 'error' => 'Recent sessions are unavailable.'];
        }

        // 422 on anything but a UUID.
        $session = $this->registry->validateArg($definition, $session);

        $result = $this->runner->run($definition, $session);
        if (! $result->ok) {
            return ['sessions' => [], 'skipped' => 0, 'error' => $this->reason($result->error)];
        }

        $decoded = json_decode($result->output, true);
        if (! is_array($decoded) || ! is_array($decoded['sessions'] ?? null)) {
            return ['sessions' => [], 'skipped' => 0, 'error' => 'Gemini sent back something unexpected.'];
        }

        $labels = config('control_panel.projects', []);
        $skipped = max(0, (int) ($decoded['skipped'] ?? 0));
        $sessions = [];
        foreach ($decoded['sessions'] as $row) {
            if (($s = $this->row($row)) === null) {
                continue;
            }
            $key = (string) ($row['project'] ?? '');
            if (! array_key_exists($key, $labels)) {
                $skipped++;

                continue;
            }
            $sessions[] = $s + ['project' => $key, 'project_label' => (string) $labels[$key]];
        }

        return ['sessions' => $sessions, 'skipped' => $skipped, 'error' => null];
    }

    /**
     * One row from Windows, normalised; null when its id isn't a UUID.
     *
     * @return array{id: string, title: string, updated: string, ago: string, name: string, remote: bool, last_prompt: string, running: bool, reconnectable: bool, origin: string}|null
     */
    private function row(mixed $row): ?array
    {
        $id = is_array($row) ? strtolower((string) ($row['id'] ?? '')) : '';
        if (preg_match(ActionRegistry::SESSION_ID_PATTERN, $id) !== 1) {
            return null;
        }

        $updated = (string) ($row['updated'] ?? '');

        return [
            'id' => $id,
            'title' => trim((string) ($row['title'] ?? '')) ?: 'Untitled session',
            'updated' => $updated,
            'ago' => $this->ago($updated),
            'name' => trim((string) ($row['name'] ?? '')),
            'remote' => (bool) ($row['remote'] ?? false),
            'last_prompt' => trim((string) ($row['lastPrompt'] ?? '')),
            'running' => (bool) ($row['running'] ?? false),
            'reconnectable' => (bool) ($row['reconnectable'] ?? false),
            // Who started it: "controlpanel" or "elsewhere". An older Windows
            // script sends nothing, so those rows read elsewhere.
            'origin' => ($row['origin'] ?? '') === 'controlpanel' ? 'controlpanel' : 'elsewhere',
        ];
    }

    /** "2h ago", or '' when the time is missing or unreadable. */
    private function ago(string $iso): string
    {
        if ($iso === '') {
            return '';
        }

        try {
            return Carbon::parse($iso)->diffForHumans(['short' => true]);
        } catch (Throwable) {
            return '';
        }
    }

    /** A sentence the owner can act on, not raw ssh stderr. */
    private function reason(string $error): string
    {
        $error = trim($error);

        if ($error === '' || str_contains($error, 'timed out') || str_contains($error, 'Timed out')
            || str_contains($error, 'No route') || str_contains($error, 'Connection refused')) {
            return "Couldn't reach Gemini. Is it awake?";
        }

        if (str_contains($error, 'No launcher task')) {
            return "This project has no launcher on Gemini yet, so its sessions can't be listed.";
        }

        return 'Could not list sessions: '.mb_substr(strtok($error, "\n") ?: $error, 0, 160);
    }
}
