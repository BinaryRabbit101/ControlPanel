<?php

namespace App\Http\Controllers\ControlPanel;

use App\Http\Controllers\Controller;
use App\Jobs\RunControlPanelAction;
use App\Models\ActionLog;
use App\Support\ControlPanel\ActionRegistry;
use App\Support\ControlPanel\ActionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ActionController extends Controller
{
    public function run(
        Request $request,
        string $action,
        ActionRegistry $registry,
        ActionRunner $runner,
    ): JsonResponse {
        $definition = $registry->find($action);
        abort_if($definition === null, 404, 'Unknown action.');
        abort_unless($definition->enabled, 403, 'This action is disabled.');

        // Throws a 422 on anything not on the allowlist.
        $arg = $registry->validateArg($definition, $request->input('arg'));
        $arg2 = $registry->validateArg2($definition, $request->input('arg2'));

        $log = ActionLog::create([
            'user_id' => $request->user()->id,
            'action_id' => $definition->id,
            'category' => $definition->category,
            'arg' => $arg,
            'arg2' => $arg2,
            'status' => 'pending',
        ]);

        if ($definition->async) {
            RunControlPanelAction::dispatch($log->id, $definition->id, $arg, $arg2);

            return response()->json($this->payload($log->fresh()));
        }

        $log->update(['status' => 'running', 'started_at' => now()]);
        $result = $runner->run($definition, $arg, $arg2);
        $log->update([
            'status' => $result->ok ? 'success' : 'failed',
            'exit_code' => $result->exitCode,
            'output' => Str::limit($result->output, 60000, ''),
            'error' => Str::limit($result->error, 10000, ''),
            'finished_at' => now(),
        ]);

        return response()->json($this->payload($log->fresh()));
    }

    public function status(ActionLog $log): JsonResponse
    {
        return response()->json($this->payload($log));
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

    private function payload(ActionLog $log): array
    {
        return [
            'log_id' => $log->id,
            'action_id' => $log->action_id,
            'arg' => $log->arg,
            'arg2' => $log->arg2,
            'status' => $log->status,
            'exit_code' => $log->exit_code,
            'output' => $log->output,
            'error' => $log->error,
            'terminal' => $log->isTerminal(),
            'finished_at' => optional($log->finished_at)->toDateTimeString(),
        ];
    }
}
