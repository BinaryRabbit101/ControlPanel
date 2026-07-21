<?php

namespace App\Support\ControlPanel\Handlers;

use App\Support\ControlPanel\Action;
use App\Support\ControlPanel\ActionResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs a fixed, root-owned wrapper script on the mini-PC, optionally via a
 * narrow sudo grant. Uses the array-argv form of the Process facade (no shell
 * string is ever assembled), matching the Budget app's precedent.
 */
class ScriptHandler implements Handler
{
    public function handle(Action $action, ?string $arg, ?string $arg2 = null): ActionResult
    {
        $path = rtrim((string) config('control_panel.bin'), '/') . '/' . $action->script;

        $command = match ($action->runAs) {
            'root' => ['sudo', '-n', $path],
            'gemini' => ['sudo', '-n', '-u', 'gemini', $path],
            default => [$path],
        };

        if ($arg !== null && $arg !== '') {
            $command[] = $arg;
        }

        $result = Process::timeout($action->timeout)->run($command);

        return new ActionResult(
            $result->successful(),
            $result->exitCode(),
            trim($result->output()),
            trim($result->errorOutput()),
        );
    }
}
