<?php

namespace App\Support\ControlPanel\Handlers;

use App\Support\ControlPanel\Action;
use App\Support\ControlPanel\ActionResult;

interface Handler
{
    public function handle(Action $action, ?string $arg, ?string $arg2 = null): ActionResult;
}
