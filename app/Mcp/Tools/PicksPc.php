<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;

/**
 * The `pc` argument: one of the machines in control_panel.shortcut.pcs
 * (gemini, franklin), as an enum so Claude can only pick a real one.
 */
trait PicksPc
{
    /** @return array<int, string> */
    protected function pcs(): array
    {
        return array_map('strval', array_keys(config('control_panel.shortcut.pcs', [])));
    }

    protected function pcSchema(JsonSchema $schema): Type
    {
        return $schema->string()
            ->enum($this->pcs())
            ->description('Which PC: gemini (the owner\'s PC) or franklin (Emily\'s PC).')
            ->required();
    }

    /** @return array<string, mixed> */
    protected function pcRules(): array
    {
        return ['pc' => ['required', 'string', Rule::in($this->pcs())]];
    }
}
