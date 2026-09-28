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
            ->postJson('/api/shortcut/sleep?pc=gemini')
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
            ->postJson('/api/shortcut/sleep?pc=gemini')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Putting Gemini to sleep.'])
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
            ->postJson('/api/shortcut/sleep?pc=gemini')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_status_pings_the_windows_pc(): void
    {
        Process::fake(['*' => Process::result(output: 'Reply')]);

        $this->withHeader('X-Api-Token', $this->token)
            ->getJson('/api/shortcut/status?pc=gemini')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Gemini is awake.'])
            ->assertJsonPath('action.action_id', 'win.ping');

        Process::assertRan(fn ($process) => in_array('192.168.0.197', $process->command, true));
    }

    public function test_pc_franklin_sleeps_franklin(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS')]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep', ['pc' => 'franklin'])
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Putting Franklin to sleep.'])
            ->assertJsonPath('action.action_id', 'franklin.sleep');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/franklin-sleep.sh'));
    }

    public function test_pc_franklin_wakes_franklin(): void
    {
        $this->assertSame('franklin', config('control_panel.devices.1.id'));
        config()->set('control_panel.devices.1.mac', '');

        // No MAC configured yet: logged and reported as not ok, never the main PC's packet.
        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/wake?pc=franklin')
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('action.action_id', 'franklin.wake');
    }

    public function test_status_can_ping_franklin(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Request timed out.', exitCode: 1)]);

        $this->withHeader('X-Api-Token', $this->token)
            ->getJson('/api/shortcut/status?pc=franklin')
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'Franklin is asleep.'])
            ->assertJsonPath('action.action_id', 'franklin.ping');

        Process::assertRan(fn ($process) => in_array('192.168.0.108', $process->command, true));
    }

    public function test_an_unknown_pc_is_refused(): void
    {
        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep', ['pc' => 'nope'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_missing_pc_is_refused_rather_than_defaulting_to_gemini(): void
    {
        Process::fake();

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/wake')
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Process::assertNothingRan();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_blank_pc_is_refused_rather_than_defaulting_to_gemini(): void
    {
        Process::fake();

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep?pc=')
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Process::assertNothingRan();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_the_pc_name_is_case_insensitive(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS')]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep', ['pc' => ' Franklin '])
            ->assertOk()
            ->assertJsonPath('action.action_id', 'franklin.sleep');
    }

    public function test_a_disabled_action_is_refused(): void
    {
        config()->set('control_panel.disabled', ['win.sleep']);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep?pc=gemini')
            ->assertStatus(403)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_failed_wrapper_reports_not_ok(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host timed out', exitCode: 255)]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/sleep?pc=gemini')
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('action.status', 'failed');
    }

    // ---- Claude sessions ----------------------------------------------------

    public function test_projects_lists_the_labels_alphabetically(): void
    {
        config()->set('control_panel.projects', ['hub' => 'HUB', 'date-night' => 'Date Night', 'budget' => 'Budget']);

        $this->withHeader('X-Api-Token', $this->token)
            ->getJson('/api/shortcut/projects')
            ->assertOk()
            ->assertJson(['ok' => true, 'projects' => ['Budget', 'Date Night', 'HUB']]);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_projects_needs_a_token(): void
    {
        $this->getJson('/api/shortcut/projects')->assertStatus(401);
        $this->postJson('/api/shortcut/session', ['project' => 'HUB'])->assertStatus(401);
    }

    public function test_session_starts_the_chosen_project_by_label(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS')]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/session', ['project' => 'Date Night'])
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Starting a Claude session in Date Night.'])
            ->assertJsonPath('action.action_id', 'win.launch-claude')
            ->assertJsonPath('action.arg', 'date-night');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/win-launch-claude.sh')
            && in_array('date-night', $process->command, true));

        $this->assertDatabaseHas('action_logs', [
            'user_id' => $this->user->id,
            'action_id' => 'win.launch-claude',
            'arg' => 'date-night',
        ]);
    }

    public function test_session_accepts_a_key_case_insensitively(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS')]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/session?project=%20HUB%20')
            ->assertOk()
            ->assertJsonPath('action.arg', 'hub');
    }

    public function test_session_refuses_a_missing_or_unknown_project(): void
    {
        Process::fake();

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/session')
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/session', ['project' => 'nope'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Process::assertNothingRan();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_session_reports_an_unreachable_pc(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host timed out', exitCode: 255)]);

        $this->withHeader('X-Api-Token', $this->token)
            ->postJson('/api/shortcut/session', ['project' => 'HUB'])
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

        $this->withHeader('X-Api-Token', $plain)->getJson('/api/shortcut/status?pc=gemini')->assertOk();
    }

    public function test_rotating_invalidates_the_old_token(): void
    {
        $this->actingAs($this->user)->post('/profile/api-token')->assertRedirect('/profile');

        $this->withHeader('X-Api-Token', $this->token)->getJson('/api/shortcut/status')->assertStatus(401);
        $this->withHeader('X-Api-Token', session('api_token'))->getJson('/api/shortcut/status?pc=gemini')->assertOk();
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
