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

#[Name('end_session')]
#[Title('End a Claude session')]
#[Description('Stop one running Claude session on Gemini, by the pid list_running_sessions gave. Unsaved work in that session is lost.')]
#[IsDestructive]
class EndSession extends HouseholdTool
{
    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $data = $request->validate(['pid' => ['required', 'regex:/^[1-9][0-9]{0,6}$/']]);

        return $this->reply($verbs->endSession($this->user($request), (string) $data['pid']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pid' => $schema->string()
                ->pattern('^[1-9][0-9]{0,6}$')
                ->description('The pid from list_running_sessions, e.g. "4120".')
                ->required(),
        ];
    }
}
