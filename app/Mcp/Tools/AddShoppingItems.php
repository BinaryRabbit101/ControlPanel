<?php

namespace App\Mcp\Tools;

use App\Support\ControlPanel\HouseholdApps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('add_shopping_items')]
#[Title('Add to the shopping list')]
#[Description('Add one or more items to the household shopping list. Give `items` (a list of names) or one `name` with an optional `note` (e.g. "2 litres"). Items already on the list are not added twice.')]
#[IsDestructive(false)]
class AddShoppingItems extends HouseholdTool
{
    public function handle(Request $request, HouseholdApps $apps): Response|ResponseFactory
    {
        $data = $request->validate([
            'items' => ['nullable', 'array', 'max:50', 'required_without:name'],
            'items.*' => ['string', 'max:120'],
            'name' => ['nullable', 'string', 'max:120', 'required_without:items'],
            'note' => ['nullable', 'string', 'max:120'],
            'group' => ['nullable', 'string', 'max:40'],
        ]);
        $this->user($request);

        $names = array_values(array_filter(
            array_map(fn ($n) => trim((string) $n), $data['items'] ?? [$data['name'] ?? '']),
            fn (string $n) => $n !== '',
        ));

        if ($names === []) {
            return Response::error('Say what to add to the list.');
        }

        $group = trim((string) ($data['group'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));

        // One item keeps its note; several go as one-per-line text, as the
        // Shopping List's own Shortcut sends them.
        $payload = count($names) === 1
            ? array_filter(['name' => $names[0], 'note' => $note, 'group' => $group], fn (string $v) => $v !== '')
            : array_filter(['text' => implode("\n", $names), 'group' => $group], fn (string $v) => $v !== '');

        return $this->reply($apps->addShoppingItems($payload));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->string())
                ->description('Item names to add, e.g. ["milk", "eggs"].'),
            'name' => $schema->string()
                ->description('One item to add (instead of items).'),
            'note' => $schema->string()
                ->description('Optional note for a single item, e.g. "the big tub".'),
            'group' => $schema->string()
                ->description('Optional group from shopping_groups. Leave out for the top of the list.'),
        ];
    }
}
