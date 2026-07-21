<?php

namespace App\Support\ControlPanel\Handlers;

use App\Support\ControlPanel\Action;
use App\Support\ControlPanel\ActionResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs a wrapper script that SSHes into the Windows PC. No sudo — the script
 * uses the dedicated www-data-owned SSH key. The wrapper resolves host/user/key
 * from /opt/controlpanel/bin/config.env and pins the remote command.
 */
class SshScriptHandler implements Handler
{
    public function handle(Action $action, ?string $arg, ?string $arg2 = null): ActionResult
    {
        $path = rtrim((string) config('control_panel.bin'), '/') . '/' . $action->script;

        // Positional argv only — never a shell string. Both args are already
        // allowlist-validated; the wrapper charset-guards them again.
        $command = [$path];
        if ($arg !== null && $arg !== '') {
            $command[] = $arg;
        }
        if ($arg2 !== null && $arg2 !== '') {
            // Keep positions stable: if arg is empty but arg2 isn't, pass "".
            if (count($command) === 1) {
                $command[] = '';
            }
            $command[] = $arg2;
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
