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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('wake_pc')]
#[Title('Wake a PC')]
#[Description('Wake a PC with Wake-on-LAN and wait (up to about 45 seconds) until it answers. gemini = the owner\'s PC, franklin = Emily\'s PC.')]
#[IsDestructive(false)]
#[IsIdempotent]
class WakePc extends HouseholdTool
{
    use PicksPc;

    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $data = $request->validate($this->pcRules());

        return $this->reply($verbs->wake($this->user($request), $data['pc']));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['pc' => $this->pcSchema($schema)];
    }
}
