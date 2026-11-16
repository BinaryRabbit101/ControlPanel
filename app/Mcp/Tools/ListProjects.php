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

#[Name('list_projects')]
#[Title('List Claude projects')]
#[Description('The projects a Claude session can be started in on Gemini, A to Z.')]
#[IsReadOnly]
class ListProjects extends HouseholdTool
{
    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $this->user($request);

        return $this->reply($verbs->projects());
    }
}
