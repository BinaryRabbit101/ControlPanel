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

#[Name('pc_status')]
#[Title('Is the PC awake?')]
#[Description('Ask whether a PC is awake (pings it). gemini = the owner\'s PC, franklin = Emily\'s PC.')]
#[IsReadOnly]
class PcStatus extends HouseholdTool
{
    use PicksPc;

    public function handle(Request $request, ShortcutVerbs $verbs): Response|ResponseFactory
    {
        $data = $request->validate($this->pcRules());

        // "Gemini is asleep." is an answer, not a failure.
        return $this->reply($verbs->status($this->user($request), $data['pc']), errorWhenNotOk: false);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['pc' => $this->pcSchema($schema)];
    }
}
