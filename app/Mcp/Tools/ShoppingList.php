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

#[Name('shopping_list')]
#[Title('What\'s on the shopping list?')]
#[Description('Everything still on the household shopping list (not yet ticked off), by group.')]
#[IsReadOnly]
class ShoppingList extends HouseholdTool
{
    public function handle(Request $request, HouseholdApps $apps): Response|ResponseFactory
    {
        $this->user($request);

        return $this->reply($apps->shoppingList());
    }
}
