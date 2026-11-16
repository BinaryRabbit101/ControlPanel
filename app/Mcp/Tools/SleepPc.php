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

#[Name('sleep_pc')]
#[Title('Put a PC to sleep')]
#[Description('Put a PC to sleep. Anything open on it pauses, and running Claude sessions on Gemini drop. gemini = the owner\'s PC, franklin = Emily\'s PC.')]
#[IsDestructive]
class SleepPc extends HouseholdTool
{
    use PicksPc;

    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $data = $request->validate($this->pcRules());

        return $this->reply($verbs->sleep($this->user($request), $data['pc']));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['pc' => $this->pcSchema($schema)];
    }
}
