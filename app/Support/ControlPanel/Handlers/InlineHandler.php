<?php

namespace App\Support\ControlPanel\Handlers;

use App\Support\ControlPanel\Action;
use App\Support\ControlPanel\ActionResult;
use Illuminate\Support\Facades\Process;

/**
 * Read-only inline operations that need no privilege bridge: health snapshot
 * and ping. All targets come from config (never user-composed).
 */
class InlineHandler implements Handler
{
    public function handle(Action $action, ?string $arg, ?string $arg2 = null): ActionResult
    {
        return match ($action->script) {
            'health' => $this->health($action),
            'ping' => $this->ping($action, $arg),
            default => new ActionResult(false, null, '', "Unknown inline operation: {$action->script}"),
        };
    }

    private function health(Action $action): ActionResult
    {
        $commands = [
            'Disk' => ['df', '-h'],
            'Memory' => ['free', '-m'],
            'Uptime' => ['uptime'],
            'Load average' => ['cat', '/proc/loadavg'],
        ];

        $output = '';
        foreach ($commands as $label => $command) {
            $result = Process::timeout($action->timeout)->run($command);
            $text = trim($result->output()) !== '' ? trim($result->output()) : trim($result->errorOutput());
            $output .= "== {$label} ==\n{$text}\n\n";
        }

        return new ActionResult(true, 0, trim($output));
    }

    private function ping(Action $action, ?string $arg): ActionResult
    {
        $ip = null;
        foreach (config('control_panel.devices', []) as $device) {
            if (($device['id'] ?? null) === $arg) {
                $ip = $device['ip'] ?? null;
                break;
            }
        }

        if ($ip === null || $ip === '') {
            return new ActionResult(false, null, '', "No IP configured for device: {$arg}");
        }

        // IP comes from trusted config, and Process uses array argv (no shell).
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', '2000', $ip]
            : ['ping', '-c', '1', '-W', '2', $ip];

        $result = Process::timeout($action->timeout)->run($command);
        $text = trim($result->output()) !== '' ? trim($result->output()) : trim($result->errorOutput());

        return new ActionResult($result->successful(), $result->exitCode(), $text);
    }
}
