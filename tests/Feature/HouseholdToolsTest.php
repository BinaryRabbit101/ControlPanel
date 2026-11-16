<?php

namespace Tests\Feature;

use App\Mcp\Servers\HouseholdServer;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Every Household tool through laravel/mcp's test client, signed in as a
 * user. PC/session tools run the real ActionDispatcher with the wrappers
 * faked (Process::fake), so each one is logged to action_logs as that user
 * and says the same sentence the Shortcut API says. Shopping List and
 * Reminders are faked over HTTP: the right token header goes out, and a
 * missing token is a friendly "isn't connected yet", never an exception.
 */
class HouseholdToolsTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'http://shop.test';

    private const REM = 'http://rem.test';

    private const SESSION = '553979ad-2d64-4276-bcdf-b13fecd335bc';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config()->set('control_panel.household', [
            'public_host' => 'mcp.thecorgisquad.com',
            'shoppinglist_url' => self::SHOP,
            'shoppinglist_token' => 'shop-key',
            'reminders_url' => self::REM,
            'reminders_token' => 'rem-key',
            'timeout' => 8,
            'connect_timeout' => 3,
        ]);

        // Never send a real magic packet; waiting is instant.
        config()->set('control_panel.network.broadcast', '127.0.0.1');
        config()->set('control_panel.windows.mac', '00:11:22:33:44:55');
        Sleep::fake(syncWithCarbon: true);

        Http::preventStrayRequests();
    }

    private function server(): mixed
    {
        return HouseholdServer::actingAs($this->user);
    }

    /**
     * tools/list over HTTP at /mcp with a Passport token, as Claude asks it.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function listedTools(): Collection
    {
        Passport::actingAs($this->user, ['mcp:use'], 'api');

        return collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->json('result.tools'))->keyBy('name');
    }

    // ---- the menu -------------------------------------------------------

    public function test_the_server_offers_exactly_the_household_menu(): void
    {
        $names = $this->listedTools()->keys()->sort()->values()->all();

        $this->assertSame([
            'add_shopping_items', 'create_reminder', 'end_session', 'list_projects', 'list_recent_sessions',
            'list_running_sessions', 'pc_status', 'resume_session', 'send_push', 'shopping_groups', 'shopping_list',
            'sleep_pc', 'start_session', 'todays_reminders', 'wake_pc',
        ], $names);
    }

    public function test_tools_carry_read_only_and_destructive_hints(): void
    {
        $tools = $this->listedTools();

        $this->assertTrue($tools['pc_status']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['shopping_list']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['sleep_pc']['annotations']['destructiveHint']);
        $this->assertTrue($tools['end_session']['annotations']['destructiveHint']);
        $this->assertFalse($tools['wake_pc']['annotations']['destructiveHint']);
        $this->assertSame(['gemini', 'franklin'], $tools['pc_status']['inputSchema']['properties']['pc']['enum']);
    }

    // ---- PCs ------------------------------------------------------------

    public function test_pc_status_says_awake_or_asleep_without_calling_asleep_an_error(): void
    {
        Process::fake(['*' => Process::result(output: 'Reply')]);
        $this->server()->tool(PcStatus::class, ['pc' => 'gemini'])
            ->assertOk()
            ->assertSee('Gemini is awake.')
            ->assertStructuredContent(fn ($json) => $json->where('ok', true)->where('action.action_id', 'win.ping')->etc());

        Process::fake(['*' => Process::result(errorOutput: 'Request timed out.', exitCode: 1)]);
        $this->server()->tool(PcStatus::class, ['pc' => 'franklin'])
            ->assertOk()
            ->assertSee('Franklin is asleep.');

        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'win.ping']);
        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'franklin.ping']);
    }

    public function test_an_unknown_pc_is_refused_and_nothing_runs(): void
    {
        Process::fake();

        $this->server()->tool(SleepPc::class, ['pc' => 'nas'])->assertHasErrors();
        $this->server()->tool(SleepPc::class, [])->assertHasErrors();

        Process::assertNothingRan();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_sleep_pc_runs_the_sleep_wrapper_as_the_user(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS')]);

        $this->server()->tool(SleepPc::class, ['pc' => 'franklin'])
            ->assertOk()
            ->assertSee('Putting Franklin to sleep.');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/franklin-sleep.sh'));
        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'franklin.sleep', 'status' => 'success']);
    }

    public function test_wake_pc_reports_the_handlers_own_sentence(): void
    {
        Process::fake(['*' => Process::result(output: '64 bytes from 192.168.0.197')]);

        // Already up: the handler's own words come back.
        $this->server()->tool(WakePc::class, ['pc' => 'gemini'])
            ->assertOk()
            ->assertSee('Gemini is already awake.');

        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'win.wake', 'status' => 'success']);
    }

    public function test_a_failed_sleep_is_a_tool_error_with_a_sentence(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host timed out', exitCode: 255)]);

        $this->server()->tool(SleepPc::class, ['pc' => 'gemini'])
            ->assertHasErrors(['That did not work']);
    }

    public function test_a_disabled_action_is_refused(): void
    {
        config()->set('control_panel.disabled', ['win.sleep']);
        Process::fake();

        $this->server()->tool(SleepPc::class, ['pc' => 'gemini'])->assertHasErrors(['That action is disabled.']);

        Process::assertNothingRan();
    }

    // ---- Claude sessions --------------------------------------------------

    public function test_list_projects_lists_labels_a_to_z(): void
    {
        config()->set('control_panel.projects', ['hub' => 'HUB', 'date-night' => 'Date Night', 'budget' => 'Budget']);

        $this->server()->tool(ListProjects::class)
            ->assertOk()
            ->assertSee('3 projects.')
            ->assertStructuredContent(fn ($json) => $json->where('projects', ['Budget', 'Date Night', 'HUB'])->etc());
    }

    public function test_start_session_launches_the_project(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS')]);

        $this->server()->tool(StartSession::class, ['project' => 'date night'])
            ->assertOk()
            ->assertSee('Starting a Claude session in Date Night.');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/win-launch-claude.sh')
            && in_array('date-night', $process->command, true));
        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'win.launch-claude', 'arg' => 'date-night']);
    }

    public function test_start_session_refuses_an_unknown_project(): void
    {
        Process::fake();

        $this->server()->tool(StartSession::class, ['project' => 'nope'])->assertHasErrors(['There is no project called nope.']);

        Process::assertNothingRan();
    }

    private function recentJson(): string
    {
        return json_encode(['skipped' => 0, 'sessions' => [
            ['id' => self::SESSION, 'title' => 'Hidden horizons video', 'updated' => '2026-10-14T13:30:00Z', 'project' => 'hub', 'remote' => true, 'running' => false, 'reconnectable' => false],
        ]]);
    }

    public function test_recent_sessions_and_resume(): void
    {
        Carbon::setTestNow('2026-10-14T15:30:00Z');
        Process::fake([
            '*win-recent-all-claude.sh*' => Process::result(output: $this->recentJson()),
            '*' => Process::result(output: 'SUCCESS'),
        ]);

        $this->server()->tool(ListRecentSessions::class)
            ->assertOk()
            ->assertSee('1 recent session.')
            ->assertSee(self::SESSION);

        $this->server()->tool(ResumeSession::class, ['session' => strtoupper(self::SESSION)])
            ->assertOk()
            ->assertSee('Resuming "Hidden horizons video" in HUB.');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/win-launch-claude.sh')
            && in_array(self::SESSION, $process->command, true));
        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'win.resume-claude', 'arg2' => self::SESSION]);

        Carbon::setTestNow();
    }

    public function test_resume_refuses_a_non_uuid_and_nothing_launches(): void
    {
        Process::fake();

        $this->server()->tool(ResumeSession::class, ['session' => 'rm -rf /'])->assertHasErrors(["That isn't a session id."]);

        Process::assertNothingRan();
    }

    public function test_running_sessions_and_end_session(): void
    {
        Process::fake([
            '*win-list-claude.sh*' => Process::result(output: json_encode([
                ['pid' => 4120, 'origin' => 'controlpanel', 'name' => 'hub-2e', 'project' => 'hub', 'sessionId' => self::SESSION],
            ])),
            '*' => Process::result(output: 'SUCCESS'),
        ]);

        $this->server()->tool(ListRunningSessions::class)
            ->assertOk()
            ->assertSee('1 Claude session is running on Gemini.')
            ->assertStructuredContent(fn ($json) => $json->where('sessions.0.pid', '4120')->etc());

        $this->server()->tool(EndSession::class, ['pid' => '4120'])
            ->assertOk()
            ->assertSee('Ending that Claude session.');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/win-end-claude.sh')
            && in_array('4120', $process->command, true));
        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'win.end-claude', 'arg' => '4120']);
    }

    public function test_end_session_refuses_anything_but_a_pid(): void
    {
        Process::fake();

        $this->server()->tool(EndSession::class, ['pid' => '12; reboot'])->assertHasErrors();
        $this->server()->tool(EndSession::class, ['pid' => '0'])->assertHasErrors();

        Process::assertNothingRan();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_an_unreachable_gemini_is_one_plain_sentence(): void
    {
        Process::fake(['*' => Process::result(errorOutput: "ssh: connect to host 192.168.0.197 port 22: Connection timed out\n", exitCode: 255)]);

        $this->server()->tool(ListRunningSessions::class)->assertHasErrors(["Couldn't reach Gemini. Is it awake?"]);
    }

    // ---- Shopping List ---------------------------------------------------

    public function test_shopping_list_reads_the_widget_feed_with_the_api_token(): void
    {
        Http::fake([self::SHOP.'/api/widget/list' => Http::response([
            'ok' => true, 'count' => 2, 'text' => 'Milk, Eggs (dozen)',
            'groups' => [['name' => 'Dairy', 'items' => [['name' => 'Milk', 'note' => null], ['name' => 'Eggs', 'note' => 'dozen']]]],
        ])]);

        $this->server()->tool(ShoppingList::class)
            ->assertOk()
            ->assertSee('Milk, Eggs (dozen)')
            ->assertStructuredContent(fn ($json) => $json->where('count', 2)->has('groups', 1)->etc());

        Http::assertSent(fn (Request $r) => $r->url() === self::SHOP.'/api/widget/list'
            && $r->method() === 'GET'
            && $r->header('X-Api-Token') === ['shop-key']);
    }

    public function test_shopping_groups(): void
    {
        Http::fake([self::SHOP.'/api/widget/groups' => Http::response(['ok' => true, 'options' => ['Top of the list', 'Costco']])]);

        $this->server()->tool(ShoppingGroups::class)
            ->assertOk()
            ->assertSee('Groups: Top of the list, Costco.');
    }

    public function test_adding_one_item_sends_name_note_and_group_and_passes_the_message_through(): void
    {
        Http::fake([self::SHOP.'/api/widget/items' => Http::response(['ok' => true, 'duplicate' => false, 'message' => 'Added Milk to Dairy.'], 201)]);

        $this->server()->tool(AddShoppingItems::class, ['name' => 'Milk', 'note' => '2 litres', 'group' => 'Dairy'])
            ->assertOk()
            ->assertSee('Added Milk to Dairy.');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->header('X-Api-Token') === ['shop-key']
            && $r->data() === ['name' => 'Milk', 'note' => '2 litres', 'group' => 'Dairy']);
    }

    public function test_adding_several_items_sends_one_per_line(): void
    {
        Http::fake([self::SHOP.'/api/widget/items' => Http::response(['ok' => true, 'added' => 2, 'message' => 'Added 2 at the top: Milk, Eggs.'], 201)]);

        $this->server()->tool(AddShoppingItems::class, ['items' => ['Milk', ' Eggs ', '']])
            ->assertOk()
            ->assertSee('Added 2 at the top: Milk, Eggs.');

        Http::assertSent(fn (Request $r) => $r->data() === ['text' => "Milk\nEggs"]);
    }

    public function test_the_shopping_lists_refusal_is_passed_through(): void
    {
        Http::fake([self::SHOP.'/api/widget/items' => Http::response(['ok' => false, 'message' => 'That is 60 items. Send at most 50 at a time.'], 422)]);

        $this->server()->tool(AddShoppingItems::class, ['items' => ['a', 'b']])
            ->assertHasErrors(['That is 60 items.']);
    }

    public function test_a_missing_shopping_token_is_friendly(): void
    {
        config()->set('control_panel.household.shoppinglist_token', null);
        Http::fake();

        $this->server()->tool(ShoppingList::class)->assertHasErrors(["Shopping List isn't connected yet."]);
        $this->server()->tool(AddShoppingItems::class, ['name' => 'Milk'])->assertHasErrors(["Shopping List isn't connected yet."]);

        Http::assertNothingSent();
    }

    public function test_an_unreachable_app_never_leaks_its_url_or_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for '.self::REM.'/api/widget/today?token=rem-key'));

        $response = $this->server()->tool(TodaysReminders::class)->assertHasErrors(["Couldn't reach Reminders."]);

        $response->assertDontSee('rem-key');
    }

    // ---- Reminders --------------------------------------------------------

    public function test_create_reminder_posts_with_the_shortcut_token_header(): void
    {
        Http::fake([self::REM.'/api/shortcut/reminders' => Http::response([
            'id' => 7, 'title' => 'Bins', 'due_label' => 'Tue 7:00 PM', 'message' => 'Reminder set — Tue 7:00 PM',
        ], 201)]);

        $this->server()->tool(CreateReminder::class, ['title' => 'Bins', 'due_date' => '2026-11-17', 'due_time' => '19:00', 'notes' => ''])
            ->assertOk()
            ->assertSee('Reminder set — Tue 7:00 PM');

        Http::assertSent(fn (Request $r) => $r->url() === self::REM.'/api/shortcut/reminders'
            && $r->header('X-Shortcut-Token') === ['rem-key']
            && ! $r->hasHeader('X-Api-Token')
            // Only what was given: an empty field is never sent.
            && $r->data() === ['title' => 'Bins', 'due_date' => '2026-11-17', 'due_time' => '19:00']);
    }

    public function test_a_reminders_validation_error_reads_back_its_first_message(): void
    {
        Http::fake([self::REM.'/*' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['due_time' => ['The due time field must match the format H:i.']]], 422)]);

        $this->server()->tool(CreateReminder::class, ['title' => 'Bins', 'due_time' => 'teatime'])
            ->assertHasErrors(['The due time field must match the format H:i.']);
    }

    public function test_todays_reminders_reads_the_widget_feed_with_the_token_in_the_query(): void
    {
        Http::fake([self::REM.'/api/widget/today*' => Http::response([
            'overdue_count' => 1,
            'today' => [['time' => '9:00 AM', 'title' => 'Bins', 'is_overdue' => true], ['time' => '7:00 PM', 'title' => 'Call Mum', 'is_overdue' => false]],
            'upcoming' => [], 'next_upcoming' => null, 'pending_total' => 4,
        ])]);

        $this->server()->tool(TodaysReminders::class)
            ->assertOk()
            ->assertSee('2 due today: Bins at 9:00 AM, Call Mum at 7:00 PM. 1 is overdue.');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::REM.'/api/widget/today?')
            && ($r->data()['token'] ?? null) === 'rem-key');
    }

    public function test_send_push_posts_with_the_api_token(): void
    {
        Http::fake([self::REM.'/api/push' => Http::response(['ok' => true, 'sent' => 2, 'failed' => 0, 'skipped' => [], 'message' => 'Sent to 2 devices.'])]);

        $this->server()->tool(SendPush::class, ['title' => 'Dinner', 'body' => 'Ready in 5', 'to' => 'both'])
            ->assertOk()
            ->assertSee('Sent to 2 devices.');

        Http::assertSent(fn (Request $r) => $r->header('X-Api-Token') === ['rem-key']
            && $r->data() === ['title' => 'Dinner', 'body' => 'Ready in 5', 'to' => 'both']);
    }

    public function test_send_push_only_targets_me_or_both(): void
    {
        Http::fake();

        $this->server()->tool(SendPush::class, ['title' => 'x', 'body' => 'y', 'to' => '[3]'])->assertHasErrors();

        Http::assertNothingSent();
    }

    public function test_a_missing_reminders_token_is_friendly(): void
    {
        config()->set('control_panel.household.reminders_token', '');
        Http::fake();

        $this->server()->tool(TodaysReminders::class)->assertHasErrors(["Reminders isn't connected yet."]);
        $this->server()->tool(CreateReminder::class, ['title' => 'x'])->assertHasErrors(["Reminders isn't connected yet."]);
        $this->server()->tool(SendPush::class, ['title' => 'x', 'body' => 'y', 'to' => 'me'])->assertHasErrors(["Reminders isn't connected yet."]);

        Http::assertNothingSent();
    }

    public function test_a_turned_down_key_says_so(): void
    {
        Http::fake([self::REM.'/*' => Http::response(['message' => 'Invalid token — copy it again from Settings → Reminders.'], 403)]);

        $this->server()->tool(TodaysReminders::class)->assertHasErrors(['Reminders turned the key down.']);
    }
}
