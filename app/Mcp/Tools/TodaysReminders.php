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

#[Name('todays_reminders')]
#[Title('Today\'s reminders')]
#[Description('What is due today in the Reminders app (overdue included), and what comes next.')]
#[IsReadOnly]
class TodaysReminders extends HouseholdTool
{
    public function handle(Request $request, HouseholdApps $apps): Response|ResponseFactory
    {
        $this->user($request);

        return $this->reply($apps->todaysReminders());
    }
}
