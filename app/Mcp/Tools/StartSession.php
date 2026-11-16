<?php

namespace App\Mcp\Tools;

use App\Support\ControlPanel\ShortcutVerbs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('start_session')]
#[Title('Start a Claude session')]
#[Description('Start a new Claude Code remote-control session on Gemini in a project. Gemini must be awake.')]
#[IsDestructive(false)]
class StartSession extends HouseholdTool
{
    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $data = $request->validate(['project' => ['required', 'string', 'max:100']]);

        return $this->reply($verbs->session($this->user($request), $data['project']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('The project\'s name as list_projects gives it (e.g. "Date Night"), any case.')
                ->required(),
        ];
    }
}
