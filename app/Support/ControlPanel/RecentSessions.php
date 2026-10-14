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
     * @return array{sessions: array<int, array{id: string, title: string, updated: string, ago: string, name: string, remote: bool, last_prompt: string, running: bool, reconnectable: bool}>, error: ?string}
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
            $id = is_array($row) ? strtolower((string) ($row['id'] ?? '')) : '';
            if (preg_match(ActionRegistry::SESSION_ID_PATTERN, $id) !== 1) {
                continue;
            }

            $updated = (string) ($row['updated'] ?? '');
            $sessions[] = [
                'id' => $id,
                'title' => trim((string) ($row['title'] ?? '')) ?: 'Untitled session',
                'updated' => $updated,
                'ago' => $this->ago($updated),
                'name' => trim((string) ($row['name'] ?? '')),
                'remote' => (bool) ($row['remote'] ?? false),
                'last_prompt' => trim((string) ($row['lastPrompt'] ?? '')),
                'running' => (bool) ($row['running'] ?? false),
                'reconnectable' => (bool) ($row['reconnectable'] ?? false),
            ];
        }

        return ['sessions' => $sessions, 'error' => null];
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
