<?php

namespace App\Mcp\Tools;

use App\Support\ControlPanel\HouseholdApps;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('shopping_groups')]
#[Title('Shopping list groups')]
#[Description('The shopping list\'s group names (aisles/stores), top of the list first — the values add_shopping_items accepts as group.')]
#[IsReadOnly]
class ShoppingGroups extends HouseholdTool
{
    public function handle(Request $request, HouseholdApps $apps): Response|ResponseFactory
    {
        $this->user($request);

        return $this->reply($apps->shoppingGroups());
    }
}
