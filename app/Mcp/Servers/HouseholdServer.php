<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddShoppingItems;
use App\Mcp\Tools\CreateReminder;
use App\Mcp\Tools\EndSession;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListRecentSessions;
use App\Mcp\Tools\ListRunningSessions;
use App\Mcp\Tools\PcStatus;
use App\Mcp\Tools\ResumeSession;
use App\Mcp\Tools\SendPush;
use App\Mcp\Tools\ShoppingGroups;
use App\Mcp\Tools\ShoppingList;
use App\Mcp\Tools\SleepPc;
use App\Mcp\Tools\StartSession;
use App\Mcp\Tools\TodaysReminders;
use App\Mcp\Tools\WakePc;
use Laravel\Mcp\Server;

/**
 * The Household connector: a claude.ai custom connector (used from the Claude
 * iPhone app) at https://mcp.thecorgisquad.com/mcp. A fixed menu of verbs —
 * the same ones the iOS Shortcut API offers, plus Shopping List and Reminders
 * — never mini-PC actions (deploy, rebuild, reboot, health). Signed in with
 * the owner's ControlPanel account through Passport OAuth; every action is
 * logged to action_logs as that account.
 */
class HouseholdServer extends Server
{
    protected string $name = 'Household';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Household controls for the owner and Emily.
        PCs: "gemini" is the owner's PC, "franklin" is Emily's PC. Claude sessions only run on Gemini.
        Shopping List and Reminders are the household's own apps.
        Each tool's first line is a short sentence meant to be read aloud; reply in the same spoken style.
        Before sleeping a PC or ending a Claude session, confirm with the user unless they clearly asked for it.
    MARKDOWN;

    protected array $tools = [
        PcStatus::class,
        WakePc::class,
        SleepPc::class,
        ListProjects::class,
        StartSession::class,
        ListRecentSessions::class,
        ResumeSession::class,
        ListRunningSessions::class,
        EndSession::class,
        ShoppingList::class,
        ShoppingGroups::class,
        AddShoppingItems::class,
        CreateReminder::class,
        TodaysReminders::class,
        SendPush::class,
    ];

    public int $defaultPaginationLength = 50;
}
