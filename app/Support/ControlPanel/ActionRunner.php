<?php

namespace App\Support\ControlPanel;

use App\Support\ControlPanel\Handlers\Handler;
use App\Support\ControlPanel\Handlers\InlineHandler;
use App\Support\ControlPanel\Handlers\ScriptHandler;
use App\Support\ControlPanel\Handlers\SshScriptHandler;
use App\Support\ControlPanel\Handlers\WakeOnLanHandler;
use InvalidArgumentException;

/**
 * Resolves the correct handler for an action and executes it. The only place
 * that turns an Action into an ActionResult.
 */
class ActionRunner
{
    public function run(Action $action, ?string $arg = null): ActionResult
    {
        return $this->handlerFor($action)->handle($action, $arg);
    }

    private function handlerFor(Action $action): Handler
    {
        return match ($action->handler) {
            'wol' => new WakeOnLanHandler(),
            'script' => new ScriptHandler(),
            'ssh' => new SshScriptHandler(),
            'inline' => new InlineHandler(),
            default => throw new InvalidArgumentException("Unknown handler: {$action->handler}"),
        };
    }
}
