<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ShortcutApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->mintApiToken();
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->postJson('/api/shortcut/wake')->assertStatus(401);
        $this->postJson('/api/shortcut/sleep')->assertStatus(401);
        $this->getJson('/api/shortcut/status')->assertStatus(401);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->withHeader('X-Api-Token', 'cp_nope')
            ->postJson('/api/shortcut/wake')
            ->assertStatus(401);
    }

    // The 401 body carries `message` so a Shortcut's "Get Dictionary Value →
    // message" step tells the owner what went wrong instead of showing nothing.
    public function test_a_rejection_explains_itself_to_the_shortcut(): void
    {
        $this->postJson('/api/shortcut/sleep')
            ->assertStatus(401)
            ->assertJson(['ok' => false])
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'No API token'));

        $this->withHeader('X-Api-Token', 'cp_nope')
            ->postJson('/api/shortcut/sleep')
            ->assertStatus(401)
            ->assertJson(['ok' => false])
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'not valid'));
    }

    // Only the standard header is honoured — a Shortcut copied from an older
    // site that still sends X-Shortcut-Token must be corrected, not silently accepted.
    public function test_the_older_x_shortcut_token_header_is_not_an_alias(): void
    {
        $this->withHeader('X-Shortcut-Token', $this->token)
            ->postJson('/api/shortcut/sleep')
            ->assertStatus(401);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_pasted_whitespace_around_the_token_is_ignored(): void
    {
        Process::fake(['*' => Process::result(output: 'ok')]);

        $this->withHeader('X-Api-Token', " {$this->token}\n")
            ->postJson('/api/shortcut/sleep')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_a_revoked_token_is_rejected(): void
    {
        $this->user->revokeApiToken();

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/wake')
            ->assertStatus(401);
    }

    public function test_only_the_hash_is_stored(): void
    {
        $this->assertDatabaseMissing('users', ['api_token_hash' => $this->token]);
        $this->assertDatabaseHas('users', ['api_token_hash' => hash('sha256', $this->token)]);
    }

    public function test_sleep_runs_the_wrapper_and_is_logged_to_the_token_owner(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS: Attempted to run the scheduled task "ControlPanel_SleepPC".')]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Putting the PC to sleep.'])
            ->assertJsonPath('action.action_id', 'win.sleep')
            ->assertJsonPath('action.status', 'success');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/win-sleep.sh'));

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $this->user->id,
            'action_id' => 'win.sleep',
            'status' => 'success',
        ]);
    }

    public function test_bearer_auth_is_accepted_too(): void
    {
        Process::fake(['*' => Process::result(output: 'ok')]);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/shortcut/sleep')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_status_pings_the_windows_pc(): void
    {
        $this->withHeader('X-Api-Token', $this->token)
            ->getJson('/api/shortcut/status')
            ->assertOk()
            ->assertJsonPath('action.action_id', 'lan.ping')
            ->assertJsonPath('action.arg', 'windows-pc');

        $this->assertDatabaseHas('action_logs', ['action_id' => 'lan.ping', 'arg' => 'windows-pc']);
    }

    public function test_a_disabled_action_is_refused(): void
    {
        config()->set('control_panel.disabled', ['win.sleep']);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep')
            ->assertStatus(403)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_failed_wrapper_reports_not_ok(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host timed out', exitCode: 255)]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep')
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('action.status', 'failed');
    }

    // ---- Profile → API token ------------------------------------------------

    public function test_profile_shows_generate_when_there_is_no_token(): void
    {
        $fresh = User::factory()->create();

        $this->actingAs($fresh)->get('/profile')
            ->assertOk()
            ->assertSee('API token')
            ->assertSee('Generate')
            ->assertDontSee('Revoke');
    }

    public function test_generating_a_token_shows_it_once_and_it_works(): void
    {
        $fresh = User::factory()->create();

        $response = $this->actingAs($fresh)->post('/profile/api-token');
        $response->assertRedirect('/profile')->assertSessionHas('api_token');

        $plain = session('api_token');
        $this->assertStringStartsWith('cp_', $plain);

        $this->actingAs($fresh)->get('/profile')->assertSee($plain);
        // Second visit: the plaintext is gone, Rotate/Revoke are offered.
        $this->actingAs($fresh)->get('/profile')->assertDontSee($plain)->assertSee('Rotate')->assertSee('Revoke');

        $this->withHeader('X-Api-Token', $plain)->getJson('/api/shortcut/status')->assertOk();
    }

    public function test_rotating_invalidates_the_old_token(): void
    {
        $this->actingAs($this->user)->post('/profile/api-token')->assertRedirect('/profile');

        $this->withHeader('X-Api-Token', $this->token)->getJson('/api/shortcut/status')->assertStatus(401);
        $this->withHeader('X-Api-Token', session('api_token'))->getJson('/api/shortcut/status')->assertOk();
    }

    public function test_revoking_from_the_profile_disables_the_token(): void
    {
        $this->actingAs($this->user)->delete('/profile/api-token')->assertRedirect('/profile');

        $this->assertFalse($this->user->fresh()->hasApiToken());
        $this->withHeader('X-Api-Token', $this->token)->getJson('/api/shortcut/status')->assertStatus(401);
    }

    public function test_guests_cannot_mint_tokens(): void
    {
        $this->post('/profile/api-token')->assertRedirect('/login');
    }
}
