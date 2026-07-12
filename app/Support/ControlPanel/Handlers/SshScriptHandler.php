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
    public function handle(Action $action, ?string $arg): ActionResult
    {
        $path = rtrim((string) config('control_panel.bin'), '/') . '/' . $action->script;

        $command = [$path];
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
