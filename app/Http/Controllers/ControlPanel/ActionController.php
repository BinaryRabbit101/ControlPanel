<?php

namespace App\Http\Controllers\ControlPanel;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Support\ControlPanel\ActionDispatcher;
use App\Support\ControlPanel\ActionRegistry;
use App\Support\ControlPanel\ActionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionController extends Controller
{
    public function run(
        Request $request,
        string $action,
        ActionRegistry $registry,
        ActionDispatcher $dispatcher,
    ): JsonResponse {
        $definition = $registry->find($action);
        abort_if($definition === null, 404, 'Unknown action.');
        abort_unless($definition->enabled, 403, 'This action is disabled.');

        // Validates both args (422 on anything off the allowlist), logs, runs.
        $log = $dispatcher->dispatch(
            $request->user(), $definition, $request->input('arg'), $request->input('arg2'),
        );

        return response()->json($dispatcher->payload($log));
    }

    public function status(ActionLog $log, ActionDispatcher $dispatcher): JsonResponse
    {
        return response()->json($dispatcher->payload($log));
    }

    /**
     * Live list of running Claude sessions on Windows, used to populate the
     * "End Claude session" dropdown. Read-only; not written to action_logs.
     * Returns { sessions: [{project, pid, model, started}], error }.
     */
    public function sessions(ActionRegistry $registry, ActionRunner $runner): JsonResponse
    {
        $definition = $registry->find('win.list-claude');

        if ($definition === null || ! $definition->enabled) {
            return response()->json(['sessions' => [], 'error' => 'Session listing is unavailable.']);
        }

        $result = $runner->run($definition);

        if (! $result->ok) {
            return response()->json([
                'sessions' => [],
                'error' => $result->error !== '' ? $result->error : 'Could not reach Windows to list sessions.',
            ]);
        }

        $decoded = json_decode($result->output, true);
        if (! is_array($decoded)) {
            return response()->json(['sessions' => [], 'error' => 'Unexpected session output.']);
        }

        // Normalise to a predictable shape; ignore anything malformed.
        $sessions = [];
        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['pid'])) {
                continue;
            }
            $sessions[] = [
                'pid' => (string) $row['pid'],
                'project' => (string) ($row['project'] ?? 'claude'),
                'model' => (string) ($row['model'] ?? ''),
                'started' => (string) ($row['started'] ?? ''),
            ];
        }

        return response()->json(['sessions' => $sessions, 'error' => null]);
    }
}
