<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ControlPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_sees_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Control Panel')
            ->assertSee('Wake Gemini')
            ->assertSee('Ping Gemini')
            ->assertSee('Wake Franklin')
            ->assertSee('Sleep Franklin')
            ->assertSee('Ping Franklin')
            ->assertDontSee('Wake device');
    }

    public function test_franklin_sleep_runs_its_own_wrapper(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS')]);

        $this->actingAs(User::factory()->create())
            ->postJson('/actions/franklin.sleep')
            ->assertOk()
            ->assertJson(['action_id' => 'franklin.sleep', 'status' => 'success']);

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/franklin-sleep.sh'));
    }

    public function test_a_read_only_action_runs_and_is_logged(): void
    {
        Process::fake(['*' => Process::result(output: 'Reply from 192.168.0.108')]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/actions/franklin.ping');

        $response->assertOk()
            ->assertJson(['action_id' => 'franklin.ping', 'status' => 'success', 'terminal' => true]);

        Process::assertRan(fn ($process) => in_array('192.168.0.108', $process->command, true));

        $this->assertDatabaseHas('action_logs', [
            'action_id' => 'franklin.ping',
            'status' => 'success',
        ]);
    }

    public function test_invalid_site_argument_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/actions/mini.deploy', ['arg' => 'EvilSite; rm -rf /'])
            ->assertStatus(422);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_unknown_action_returns_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/actions/does.not.exist', ['arg' => null])
            ->assertNotFound();
    }

    public function test_launch_claude_has_no_model_picker(): void
    {
        Process::fake(['*' => Process::result(output: 'triggered')]);

        $this->actingAs(User::factory()->create())
            ->postJson('/actions/win.launch-claude', ['arg' => 'controlpanel', 'arg2' => 'opus-4-8'])
            ->assertOk()
            ->assertJson(['action_id' => 'win.launch-claude', 'arg' => 'controlpanel', 'arg2' => null]);

        $this->assertDatabaseHas('action_logs', [
            'action_id' => 'win.launch-claude',
            'arg' => 'controlpanel',
            'arg2' => null,
        ]);
    }

    public function test_end_claude_rejects_a_non_numeric_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/actions/win.end-claude', ['arg' => '123; shutdown'])
            ->assertStatus(422);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_hidden_utility_actions_are_not_shown_on_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('End Session')
            ->assertDontSee('List Claude sessions');
    }

    public function test_sessions_endpoint_returns_the_live_list(): void
    {
        Process::fake([
            '*' => Process::result(output: '[{"pid":8124,"project":"controlpanel","model":"opus-4-8","started":"2026-07-20T10:00:00"}]'),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/actions/sessions')
            ->assertOk()
            ->assertJson([
                'error' => null,
                'sessions' => [
                    // An older Windows script only lists ControlPanel's own sessions.
                    ['pid' => '8124', 'origin' => 'controlpanel', 'name' => 'controlpanel', 'project' => 'controlpanel', 'model' => 'opus-4-8', 'session_id' => ''],
                ],
            ]);
    }

    public function test_sessions_endpoint_passes_both_origins_through(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                ['pid' => 24796, 'origin' => 'controlpanel', 'name' => 'hub-74', 'project' => 'hub', 'model' => 'opus-4-8', 'started' => '2026-10-20T10:13:39', 'sessionId' => '00B39617-E9EC-4EEC-840C-5F1F1E307226'],
                ['pid' => 13232, 'origin' => 'elsewhere', 'name' => 'hub-12', 'project' => 'hub', 'model' => '', 'started' => '2026-10-20T08:41:00', 'sessionId' => '426a8229-45c3-46b8-8f04-1492d37b3017'],
                ['pid' => 9001, 'origin' => 'nonsense', 'name' => '', 'project' => '', 'sessionId' => 'not-a-uuid'],
                ['origin' => 'elsewhere', 'name' => 'no pid is dropped'],
            ])),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/actions/sessions')
            ->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonCount(3, 'sessions')
            ->assertJsonPath('sessions.0', [
                'pid' => '24796', 'origin' => 'controlpanel', 'name' => 'hub-74', 'project' => 'hub',
                'model' => 'opus-4-8', 'started' => '2026-10-20T10:13:39', 'session_id' => '00b39617-e9ec-4eec-840c-5f1f1e307226',
            ])
            ->assertJsonPath('sessions.1.origin', 'elsewhere')
            ->assertJsonPath('sessions.1.pid', '13232')
            ->assertJsonPath('sessions.1.name', 'hub-12')
            ->assertJsonPath('sessions.1.session_id', '426a8229-45c3-46b8-8f04-1492d37b3017')
            // Anything else reads as ControlPanel's; a blank name falls back to the project.
            ->assertJsonPath('sessions.2.origin', 'controlpanel')
            ->assertJsonPath('sessions.2.name', 'claude')
            ->assertJsonPath('sessions.2.session_id', '');

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_ending_a_session_started_elsewhere_goes_through_the_same_action(): void
    {
        Process::fake(['*' => Process::result(output: 'Ended session pid 13232 (hub-12).')]);

        $this->actingAs(User::factory()->create())
            ->postJson('/actions/win.end-claude', ['arg' => '13232'])
            ->assertOk()
            ->assertJson(['action_id' => 'win.end-claude', 'status' => 'success']);

        // Same wrapper, same digits-only pid; Windows decides whether it may end it.
        Process::assertRan(fn ($process) => $process->command === [config('control_panel.bin').'/win-end-claude.sh', '13232']);
        $this->assertDatabaseHas('action_logs', ['action_id' => 'win.end-claude', 'arg' => '13232']);
    }
}
