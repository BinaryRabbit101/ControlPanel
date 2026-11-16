<?php

namespace App\Mcp\Tools;

use App\Support\ControlPanel\ShortcutVerbs;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_running_sessions')]
#[Title('List running Claude sessions')]
#[Description('The Claude sessions running on Gemini right now, each with the pid end_session takes.')]
#[IsReadOnly]
class ListRunningSessions extends HouseholdTool
{
    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $this->user($request);

        return $this->reply($verbs->runningSessions());
    }
}
