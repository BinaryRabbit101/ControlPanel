<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\User;
use App\Support\ControlPanel\ActionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Wake only reports success once the PC answers a ping: the magic packet
 * leaving the box says nothing about a PC that is fully powered off.
 */
class WakeConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Never send a real magic packet onto the LAN from a test.
        config()->set('control_panel.network.broadcast', '127.0.0.1');
        config()->set('control_panel.network.wol_port', 9);
        config()->set('control_panel.windows.mac', '00:11:22:33:44:55');
        config()->set('control_panel.devices.1.mac', '66:77:88:99:AA:BB');

        // Waiting is instant, and Carbon moves with it so the 45 s deadline is real.
        Sleep::fake(syncWithCarbon: true);

        $this->user = User::factory()->create();
    }

    private function down(): mixed
    {
        return Process::result(errorOutput: 'Request timed out.', exitCode: 1);
    }

    private function up(): mixed
    {
        return Process::result(output: '64 bytes from 192.168.0.197: icmp_seq=1');
    }

    public function test_dashboard_wake_succeeds_once_the_pc_answers(): void
    {
        Process::fake(['*' => Process::sequence([$this->down(), $this->down(), $this->down(), $this->up()])]);

        $this->actingAs($this->user)
            ->postJson('/actions/win.wake')
            ->assertOk()
            ->assertJson(['action_id' => 'win.wake', 'status' => 'success', 'terminal' => true]);

        $log = ActionLog::sole();
        $this->assertStringStartsWith('Gemini is awake.', $log->output);
        $this->assertStringContainsString('Magic packet sent to 00:11:22:33:44:55.', $log->output);
        $this->assertStringContainsString('Answered a ping at 192.168.0.197 after 9 s.', $log->output);

        // Pinged the configured Gemini IP: once before the packet, three times after.
        Process::assertRanTimes(fn ($process) => in_array('192.168.0.197', $process->command, true), 4);
        Sleep::assertSleptTimes(3);
    }

    public function test_dashboard_wake_fails_when_the_pc_never_answers(): void
    {
        Process::fake(['*' => $this->down()]);

        $this->actingAs($this->user)
            ->postJson('/actions/win.wake')
            ->assertOk()
            ->assertJson([
                'status' => 'failed',
                'exit_code' => 1,
                'error' => "Gemini didn't wake up. It may be fully shut down rather than asleep.",
            ]);

        $log = ActionLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Magic packet sent to 00:11:22:33:44:55.', $log->output);
        $this->assertStringContainsString('No ping answer from 192.168.0.197 within 45 s.', $log->output);

        // 45 s at one ping every 3 s.
        Sleep::assertSleptTimes(15);
    }

    public function test_an_already_awake_pc_is_reported_without_waiting(): void
    {
        Process::fake(['*' => $this->up()]);

        $this->actingAs($this->user)
            ->postJson('/actions/win.wake')
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertStringStartsWith('Gemini is already awake.', ActionLog::sole()->output);
        Process::assertRanTimes(fn () => true, 1);
        Sleep::assertNeverSlept();
    }

    public function test_shortcut_wake_speaks_the_real_outcome(): void
    {
        $token = $this->user->mintApiToken();
        Process::fake(['*' => Process::sequence([$this->down(), $this->up()])]);

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/wake?pc=gemini')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Gemini is awake.'])
            ->assertJsonPath('action.action_id', 'win.wake');
    }

    public function test_shortcut_wake_says_when_the_pc_did_not_come_up(): void
    {
        $token = $this->user->mintApiToken();
        Process::fake(['*' => $this->down()]);

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/wake?pc=franklin')
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'message' => "Franklin didn't wake up. It may be fully shut down rather than asleep.",
            ])
            ->assertJsonPath('action.action_id', 'franklin.wake');

        Process::assertRan(fn ($process) => in_array('192.168.0.108', $process->command, true));
        $this->assertStringContainsString('Magic packet sent to 66:77:88:99:AA:BB.', ActionLog::sole()->output);
    }

    public function test_shortcut_wake_on_an_awake_pc_says_so(): void
    {
        $token = $this->user->mintApiToken();
        Process::fake(['*' => $this->up()]);

        $this->withHeader('X-Api-Token', $token)
            ->postJson('/api/shortcut/wake?pc=franklin')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Franklin is already awake.']);
    }

    public function test_wake_actions_allow_for_the_wait(): void
    {
        $registry = app(ActionRegistry::class);

        foreach (['win.wake' => 'windows-pc', 'franklin.wake' => 'franklin'] as $id => $device) {
            $action = $registry->find($id);
            $this->assertSame($device, $action->pingDevice);
            $this->assertFalse($action->async);
            $this->assertGreaterThan((int) config('control_panel.network.wake_wait'), $action->timeout);
        }
    }
}
