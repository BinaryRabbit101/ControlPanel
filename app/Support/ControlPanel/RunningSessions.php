<?php

namespace App\Support\ControlPanel;

/**
 * The Claude sessions running on Gemini right now, read over SSH by
 * win.list-claude. Read-only and not written to action_logs. Shared by the
 * dashboard's End Session dropdown and the Household MCP connector so both
 * show the same rows.
 */
class RunningSessions
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionRunner $runner,
    ) {}

    /**
     * origin is "controlpanel" (a session ControlPanel launched; an older
     * Windows script only lists those) or "elsewhere" (Remote Control on,
     * started in a terminal or the app; the pid is claude.exe's own).
     *
     * @return array{sessions: array<int, array{pid: string, origin: string, name: string, project: string, model: string, started: string, session_id: string}>, error: ?string}
     */
    public function list(): array
    {
        $definition = $this->registry->find('win.list-claude');

        if ($definition === null || ! $definition->enabled) {
            return ['sessions' => [], 'error' => 'Session listing is unavailable.'];
        }

        $result = $this->runner->run($definition);

        if (! $result->ok) {
            return [
                'sessions' => [],
                'error' => $result->error !== '' ? $result->error : 'Could not reach Windows to list sessions.',
            ];
        }

        $decoded = json_decode($result->output, true);
        if (! is_array($decoded)) {
            return ['sessions' => [], 'error' => 'Unexpected session output.'];
        }

        // Normalise to a predictable shape; ignore anything malformed.
        $sessions = [];
        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['pid'])) {
                continue;
            }
            $project = (string) ($row['project'] ?? '') ?: 'claude';
            $sessionId = strtolower((string) ($row['sessionId'] ?? ''));
            $sessions[] = [
                'pid' => (string) $row['pid'],
                'origin' => ($row['origin'] ?? '') === 'elsewhere' ? 'elsewhere' : 'controlpanel',
                'name' => trim((string) ($row['name'] ?? '')) ?: $project,
                'project' => $project,
                'model' => (string) ($row['model'] ?? ''),
                'started' => (string) ($row['started'] ?? ''),
                'session_id' => preg_match(ActionRegistry::SESSION_ID_PATTERN, $sessionId) === 1 ? $sessionId : '',
            ];
        }

        return ['sessions' => $sessions, 'error' => null];
    }
}
