<?php

namespace Tests\Feature;

use App\Models\ActionLog;
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
            ->assertSee('Wake Windows PC');
    }

    public function test_a_read_only_action_runs_and_is_logged(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/actions/lan.ping', ['arg' => 'localhost']);

        $response->assertOk()
            ->assertJson(['action_id' => 'lan.ping', 'status' => 'success', 'terminal' => true]);

        $this->assertDatabaseHas('action_logs', [
            'action_id' => 'lan.ping',
            'arg' => 'localhost',
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
            ->assertSee('End Claude session')
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
                    ['pid' => '8124', 'project' => 'controlpanel', 'model' => 'opus-4-8'],
                ],
            ]);
    }
}
