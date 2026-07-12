<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
