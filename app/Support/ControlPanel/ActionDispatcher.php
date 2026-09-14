<?php

namespace App\Support\ControlPanel;

use App\Jobs\RunControlPanelAction;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Runs (or queues) an action on behalf of a user and records it in
 * action_logs. Shared by the dashboard's AJAX controller and the token-authed
 * Shortcut API so both take exactly the same path through validation, the
 * audit log and the handlers.
 */
class ActionDispatcher
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionRunner $runner,
    ) {
    }

    /**
     * Validate the arguments (422 on anything off the allowlist), log the
     * request, then either dispatch it to the queue or run it inline.
     */
    public function dispatch(User $user, Action $action, ?string $arg = null, ?string $arg2 = null): ActionLog
    {
        $arg = $this->registry->validateArg($action, $arg);
        $arg2 = $this->registry->validateArg2($action, $arg2);

        $log = ActionLog::create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'category' => $action->category,
            'arg' => $arg,
            'arg2' => $arg2,
            'status' => 'pending',
        ]);

        if ($action->async) {
            RunControlPanelAction::dispatch($log->id, $action->id, $arg, $arg2);

            return $log->fresh();
        }

        $log->update(['status' => 'running', 'started_at' => now()]);
        $result = $this->runner->run($action, $arg, $arg2);
        $log->update([
            'status' => $result->ok ? 'success' : 'failed',
            'exit_code' => $result->exitCode,
            'output' => Str::limit($result->output, 60000, ''),
            'error' => Str::limit($result->error, 10000, ''),
            'finished_at' => now(),
        ]);

        return $log->fresh();
    }

    /** The JSON shape every action endpoint returns for a log row. */
    public function payload(ActionLog $log): array
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
