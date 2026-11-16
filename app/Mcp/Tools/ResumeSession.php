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

#[Name('resume_session')]
#[Title('Resume a Claude session')]
#[Description('Bring back an earlier Claude session on Gemini with remote control, by the session id list_recent_sessions gave. A session still running whose app link dropped is reconnected instead.')]
#[IsDestructive(false)]
class ResumeSession extends HouseholdTool
{
    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $data = $request->validate([
            'session' => ['required', 'string', 'max:64'],
            'project' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->reply($verbs->resume($this->user($request), $data['session'], $data['project'] ?? null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'session' => $schema->string()
                ->description('The session id (a UUID) from list_recent_sessions.')
                ->required(),
            'project' => $schema->string()
                ->description('Optional: the project it belongs to. Leave out to let the session\'s own folder decide.'),
        ];
    }
}
