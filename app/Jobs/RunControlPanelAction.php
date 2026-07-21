<?php

namespace App\Jobs;

use App\Models\ActionLog;
use App\Support\ControlPanel\ActionRegistry;
use App\Support\ControlPanel\ActionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a slow action (e.g. a deploy) off the request cycle. The UI polls the
 * ActionLog row until it reaches a terminal status.
 */
class RunControlPanelAction implements ShouldQueue
{
    use Queueable;

    public int $timeout = 960;
    public int $tries = 1;

    public function __construct(
        public int $logId,
        public string $actionId,
        public ?string $arg,
        public ?string $arg2 = null,
    ) {
    }

    public function handle(ActionRunner $runner, ActionRegistry $registry): void
    {
        $log = ActionLog::find($this->logId);
        if ($log === null) {
            return;
        }

        $action = $registry->find($this->actionId);
        if ($action === null) {
            $log->update(['status' => 'failed', 'error' => 'Action no longer exists.', 'finished_at' => now()]);

            return;
        }

        $log->update(['status' => 'running', 'started_at' => now()]);

        try {
            $result = $runner->run($action, $this->arg, $this->arg2);

            $log->update([
                'status' => $result->ok ? 'success' : 'failed',
                'exit_code' => $result->exitCode,
                'output' => Str::limit($result->output, 60000, ''),
                'error' => Str::limit($result->error, 10000, ''),
                'finished_at' => now(),
            ]);

            if (! $result->ok) {
                Log::warning('Control panel action failed', [
                    'action' => $this->actionId,
                    'arg' => $this->arg,
                    'exit_code' => $result->exitCode,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            $log->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        }
    }
}
