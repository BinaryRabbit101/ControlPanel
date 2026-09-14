<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ShortcutApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-shortcut-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('control_panel.shortcut.token', self::TOKEN);
        User::factory()->create();
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
        $this->withHeader('X-Shortcut-Token', 'nope')
            ->postJson('/api/shortcut/wake')
            ->assertStatus(401);
    }

    public function test_an_empty_configured_token_disables_the_endpoints(): void
    {
        config()->set('control_panel.shortcut.token', '');

        $this->withHeader('X-Shortcut-Token', '')
            ->postJson('/api/shortcut/wake')
            ->assertStatus(401);
    }

    public function test_sleep_runs_the_wrapper_and_is_logged_to_the_admin(): void
    {
        Process::fake(['*' => Process::result(output: 'SUCCESS: Attempted to run the scheduled task "ControlPanel_SleepPC".')]);

        $this->withHeader('X-Shortcut-Token', self::TOKEN)
            ->postJson('/api/shortcut/sleep')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Putting the PC to sleep.'])
            ->assertJsonPath('action.action_id', 'win.sleep')
            ->assertJsonPath('action.status', 'success');

        Process::assertRan(fn ($process) => str_ends_with($process->command[0] ?? '', '/win-sleep.sh'));

        $this->assertDatabaseHas('action_logs', [
            'user_id' => User::query()->orderBy('id')->value('id'),
            'action_id' => 'win.sleep',
            'status' => 'success',
        ]);
    }

    public function test_bearer_auth_is_accepted_too(): void
    {
        Process::fake(['*' => Process::result(output: 'ok')]);

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/shortcut/sleep')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_status_pings_the_windows_pc(): void
    {
        $this->withHeader('X-Shortcut-Token', self::TOKEN)
            ->getJson('/api/shortcut/status')
            ->assertOk()
            ->assertJsonPath('action.action_id', 'lan.ping')
            ->assertJsonPath('action.arg', 'windows-pc');

        $this->assertDatabaseHas('action_logs', ['action_id' => 'lan.ping', 'arg' => 'windows-pc']);
    }

    public function test_a_disabled_action_is_refused(): void
    {
        config()->set('control_panel.disabled', ['win.sleep']);

        $this->withHeader('X-Shortcut-Token', self::TOKEN)
            ->postJson('/api/shortcut/sleep')
            ->assertStatus(403)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_failed_wrapper_reports_not_ok(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host timed out', exitCode: 255)]);

        $this->withHeader('X-Shortcut-Token', self::TOKEN)
            ->postJson('/api/shortcut/sleep')
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('action.status', 'failed');
    }
}
