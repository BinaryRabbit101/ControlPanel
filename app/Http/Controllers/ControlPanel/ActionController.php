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

        $log = ActionLog::create([
            'user_id' => $request->user()->id,
            'action_id' => $definition->id,
            'category' => $definition->category,
            'arg' => $arg,
            'status' => 'pending',
        ]);

        if ($definition->async) {
            RunControlPanelAction::dispatch($log->id, $definition->id, $arg);

            return response()->json($this->payload($log->fresh()));
        }

        $log->update(['status' => 'running', 'started_at' => now()]);
        $result = $runner->run($definition, $arg);
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

    private function payload(ActionLog $log): array
    {
        return [
            'log_id' => $log->id,
            'action_id' => $log->action_id,
            'arg' => $log->arg,
            'status' => $log->status,
            'exit_code' => $log->exit_code,
            'output' => $log->output,
            'error' => $log->error,
            'terminal' => $log->isTerminal(),
            'finished_at' => optional($log->finished_at)->toDateTimeString(),
        ];
    }
}
