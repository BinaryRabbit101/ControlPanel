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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_recent_sessions')]
#[Title('List recent Claude sessions')]
#[Description('Recent Claude Remote Control sessions on Gemini, newest first, for resume_session. `choices` maps a readable line to the session id. Leave out project for the newest sessions across every project.')]
#[IsReadOnly]
class ListRecentSessions extends HouseholdTool
{
    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $data = $request->validate(['project' => ['nullable', 'string', 'max:100']]);
        $this->user($request);

        return $this->reply($verbs->recent($data['project'] ?? null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('Optional: a project name from list_projects. Leave out for every project.'),
        ];
    }
}
