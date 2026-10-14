<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * "Resume recent": list a project's recent Claude sessions on Gemini and
 * resume (or reconnect) one, from the dashboard and from the phone Shortcut.
 * The session id is an allowlist boundary: only an exact UUID ever reaches a
 * wrapper. SSH is faked; the wrappers' argv is asserted.
 */
class ResumeSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const IDLE = '553979ad-2d64-4276-bcdf-b13fecd335bc';

    private const STUCK = 'c6d71019-6bf3-49c5-80d7-7eac7e84942a';

    private const LIVE = '426a8229-45c3-46b8-8f04-1492d37b3017';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14T15:30:00Z');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** What win-recent-claude.sh prints: one idle, one reconnectable, one running elsewhere. */
    private function recentJson(): string
    {
        return json_encode([
            ['id' => self::LIVE, 'title' => 'Resuming sessions remotely', 'updated' => '2026-10-14T15:29:00Z', 'name' => 'hub-2e', 'remote' => true, 'lastPrompt' => 'Build resume', 'running' => true, 'reconnectable' => false],
            ['id' => self::STUCK, 'title' => 'BinaryRabbit Star Wars gameplay', 'updated' => '2026-10-14T14:00:00Z', 'name' => '', 'remote' => true, 'lastPrompt' => 'Split the video', 'running' => true, 'reconnectable' => true],
            ['id' => self::IDLE, 'title' => 'Hidden horizons video', 'updated' => '2026-10-14T13:30:00Z', 'name' => '', 'remote' => true, 'lastPrompt' => 'Make it public', 'running' => false, 'reconnectable' => false],
            ['id' => 'not-a-uuid', 'title' => 'junk row is dropped'],
        ]);
    }

    private function fakeWindows(): void
    {
        Process::fake([
            '*win-recent-claude.sh*' => Process::result(output: $this->recentJson()),
            '*' => Process::result(output: ''),
        ]);
    }

    // ---- dashboard -------------------------------------------------------

    public function test_the_dashboard_lists_a_projects_recent_sessions(): void
    {
        $this->fakeWindows();

        $this->actingAs($this->user)
            ->getJson('/actions/recent?project=hub')
            ->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonCount(3, 'sessions')
            ->assertJsonPath('sessions.0.id', self::LIVE)
            ->assertJsonPath('sessions.0.name', 'hub-2e')
            ->assertJsonPath('sessions.0.running', true)
            ->assertJsonPath('sessions.1.reconnectable', true)
            ->assertJsonPath('sessions.2.title', 'Hidden horizons video')
            ->assertJsonPath('sessions.2.ago', '2h ago')
            ->assertJsonPath('sessions.2.last_prompt', 'Make it public');

        Process::assertRan(fn ($process) => $process->command === [config('control_panel.bin').'/win-recent-claude.sh', 'hub']);
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_listing_an_unknown_project_is_refused(): void
    {
        Process::fake();

        $this->actingAs($this->user)->getJson('/actions/recent?project=nope')->assertStatus(422);
        $this->actingAs($this->user)->getJson('/actions/recent?project=hub;rm')->assertStatus(422);
        $this->actingAs($this->user)->getJson('/actions/recent')->assertStatus(422);

        Process::assertNothingRan();
    }

    public function test_an_unreachable_pc_explains_itself(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'ssh: connect to host 192.168.0.197 port 22: Connection timed out', exitCode: 255)]);

        $this->actingAs($this->user)
            ->getJson('/actions/recent?project=hub')
            ->assertOk()
            ->assertJsonPath('sessions', [])
            ->assertJsonPath('error', "Couldn't reach Gemini. Is it awake?");
    }

    public function test_resume_passes_the_project_and_session_id_to_the_launch_wrapper(): void
    {
        $this->fakeWindows();

        $this->actingAs($this->user)
            ->postJson('/actions/win.resume-claude', ['arg' => 'hub', 'arg2' => self::IDLE])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Process::assertRan(fn ($process) => $process->command === [config('control_panel.bin').'/win-launch-claude.sh', 'hub', self::IDLE]);
        $this->assertDatabaseHas('action_logs', ['action_id' => 'win.resume-claude', 'arg' => 'hub', 'arg2' => self::IDLE, 'status' => 'success']);
    }

    public function test_an_uppercase_session_id_is_normalised(): void
    {
        $this->fakeWindows();

        $this->actingAs($this->user)
            ->postJson('/actions/win.resume-claude', ['arg' => 'hub', 'arg2' => strtoupper(self::IDLE)])
            ->assertOk();

        Process::assertRan(fn ($process) => ($process->command[2] ?? null) === self::IDLE);
    }

    public function test_a_bad_session_id_is_rejected_and_nothing_runs(): void
    {
        Process::fake();

        $bad = [
            null,
            '',
            'not-a-uuid',
            self::IDLE.'; calc',
            self::IDLE.' --dangerously-skip-permissions',
            '553979ad2d644276bcdfb13fecd335bc',
            '553979ad-2d64-4276-bcdf-b13fecd335bz',
            '../../etc/passwd',
        ];

        foreach ($bad as $id) {
            $this->actingAs($this->user)
                ->postJson('/actions/win.resume-claude', ['arg' => 'hub', 'arg2' => $id])
                ->assertStatus(422)
                ->assertJsonValidationErrors('arg2');

            $this->actingAs($this->user)
                ->postJson('/actions/win.reconnect-claude', ['arg' => 'hub', 'arg2' => $id])
                ->assertStatus(422);
        }

        Process::assertNothingRan();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_resume_needs_a_known_project(): void
    {
        Process::fake();

        $this->actingAs($this->user)
            ->postJson('/actions/win.resume-claude', ['arg' => 'nope', 'arg2' => self::IDLE])
            ->assertStatus(422)
            ->assertJsonValidationErrors('arg');

        Process::assertNothingRan();
    }

    public function test_reconnect_runs_its_own_wrapper(): void
    {
        $this->fakeWindows();

        $this->actingAs($this->user)
            ->postJson('/actions/win.reconnect-claude', ['arg' => 'hub', 'arg2' => self::STUCK])
            ->assertOk();

        Process::assertRan(fn ($process) => $process->command === [config('control_panel.bin').'/win-reconnect-claude.sh', 'hub', self::STUCK]);
    }

    public function test_the_dashboard_shows_the_resume_recent_list(): void
    {
        Process::fake();

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Resume recent')
            ->assertSee('No recent sessions for this project.')
            // Utility actions are not cards of their own.
            ->assertDontSee('dusk="action-win.resume-claude"', false)
            ->assertDontSee('dusk="action-win.recent-claude"', false);
    }

    // ---- Shortcut API ----------------------------------------------------

    public function test_the_shortcut_needs_a_token(): void
    {
        Process::fake();

        $this->getJson('/api/shortcut/recent?project=hub')->assertStatus(401);
        $this->postJson('/api/shortcut/resume', ['project' => 'hub', 'session' => self::IDLE])->assertStatus(401);

        Process::assertNothingRan();
    }

    public function test_the_shortcut_lists_choices_by_label(): void
    {
        $this->fakeWindows();
        $token = $this->user->mintApiToken();

        $this->withHeader('X-Api-Token', $token)
            ->getJson('/api/shortcut/recent?project=HUB')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', '2 recent sessions in HUB.')
            ->assertJsonCount(3, 'sessions')
            // Running-but-not-reconnectable has nothing to offer the phone.
            ->assertJsonPath('choices', [
                'BinaryRabbit Star Wars gameplay · 1h ago · running, reconnects' => self::STUCK,
                'Hidden horizons video · 2h ago' => self::IDLE,
            ]);
    }

    public function test_the_shortcut_recent_needs_a_project(): void
    {
        Process::fake();
        $token = $this->user->mintApiToken();

        $this->withHeader('X-Api-Token', $token)->getJson('/api/shortcut/recent')
            ->assertStatus(422)->assertJsonPath('ok', false);
        $this->withHeader('X-Api-Token', $token)->getJson('/api/shortcut/recent?project=nope')
            ->assertStatus(422)->assertJsonPath('message', 'There is no project called nope.');

        Process::assertNothingRan();
    }

    public function test_the_shortcut_resumes_an_idle_session(): void
    {
        $this->fakeWindows();
        $token = $this->user->mintApiToken();

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/resume', ['project' => 'HUB', 'session' => self::IDLE])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Resuming "Hidden horizons video" in HUB.')
            ->assertJsonPath('action.action_id', 'win.resume-claude');

        Process::assertRan(fn ($process) => $process->command === [config('control_panel.bin').'/win-launch-claude.sh', 'hub', self::IDLE]);
        $this->assertDatabaseHas('action_logs', ['user_id' => $this->user->id, 'action_id' => 'win.resume-claude', 'arg2' => self::IDLE]);
    }

    public function test_the_shortcut_reconnects_a_running_resumed_session(): void
    {
        $this->fakeWindows();
        $token = $this->user->mintApiToken();

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/resume', ['project' => 'hub', 'session' => self::STUCK])
            ->assertOk()
            ->assertJsonPath('message', 'Reconnecting "BinaryRabbit Star Wars gameplay" in HUB.')
            ->assertJsonPath('action.action_id', 'win.reconnect-claude');

        Process::assertRan(fn ($process) => $process->command === [config('control_panel.bin').'/win-reconnect-claude.sh', 'hub', self::STUCK]);
    }

    public function test_the_shortcut_never_touches_a_session_it_cannot_reconnect(): void
    {
        $this->fakeWindows();
        $token = $this->user->mintApiToken();

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/resume', ['project' => 'hub', 'session' => self::LIVE])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->assertNothingLaunched();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_the_shortcut_rejects_a_bad_or_unknown_session(): void
    {
        $this->fakeWindows();
        $token = $this->user->mintApiToken();

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/resume', ['project' => 'hub'])
            ->assertStatus(422)->assertJsonPath('ok', false);

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/resume', ['project' => 'hub', 'session' => self::IDLE.'; calc'])
            ->assertStatus(422)->assertJsonPath('message', "That isn't a session id.");

        // A well-formed id that isn't one of this project's recent sessions.
        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/resume', ['project' => 'hub', 'session' => '00000000-0000-0000-0000-000000000000'])
            ->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertNothingLaunched();
        $this->assertDatabaseCount('action_logs', 0);
    }

    /** Neither launch wrapper ran: nothing was started or ended on Gemini. */
    private function assertNothingLaunched(): void
    {
        Process::assertDidntRun(fn ($process) => str_ends_with($process->command[0] ?? '', '/win-launch-claude.sh')
            || str_ends_with($process->command[0] ?? '', '/win-reconnect-claude.sh'));
    }
}
