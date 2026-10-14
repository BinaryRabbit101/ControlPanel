<?php

namespace App\Support\ControlPanel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * One ICMP ping to a configured device. Shared by the *.ping actions and the
 * wake actions (which ping to confirm the PC actually came up). The IP only
 * ever comes from config('control_panel.devices') and Process runs an array
 * argv, so nothing user-supplied reaches a shell.
 */
class DevicePinger
{
    /** The configured IP for a `devices` id, or null if there is none. */
    public function ipFor(?string $deviceId): ?string
    {
        foreach (config('control_panel.devices', []) as $device) {
            if (($device['id'] ?? null) === $deviceId) {
                $ip = $device['ip'] ?? null;

                return is_string($ip) && $ip !== '' ? $ip : null;
            }
        }

        return null;
    }

    /** Send one echo request (2 s reply window). */
    public function ping(string $ip, int $timeout = 15): ProcessResult
    {
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', '2000', $ip]
            : ['ping', '-c', '1', '-W', '2', $ip];

        return Process::timeout($timeout)->run($command);
    }

    /** True when the device answered; a hung ping counts as no answer. */
    public function answers(string $ip, int $timeout = 5): bool
    {
        try {
            return $this->ping($ip, $timeout)->successful();
        } catch (ProcessTimedOutException) {
            return false;
        }
    }
}
