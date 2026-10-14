<?php

namespace App\Support\ControlPanel\Handlers;

use App\Support\ControlPanel\Action;
use App\Support\ControlPanel\ActionResult;
use App\Support\ControlPanel\DevicePinger;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sends a Wake-on-LAN "magic packet" over UDP. Pure PHP (ext-sockets), no
 * external binary and no elevated privileges required. When the action names
 * a `pingDevice`, it then pings that PC until it answers (success) or the
 * configured wait runs out (failed), so "success" means the PC is really up.
 */
class WakeOnLanHandler implements Handler
{
    public function handle(Action $action, ?string $arg, ?string $arg2 = null): ActionResult
    {
        $mac = $this->resolveMac($action, $arg);

        if ($mac === null || $mac === '') {
            return new ActionResult(false, null, '', 'No MAC address configured for this target.');
        }

        $name = $action->category;
        $pinger = app(DevicePinger::class);
        $ip = $action->pingDevice !== null ? $pinger->ipFor($action->pingDevice) : null;

        // Already up? Then there is nothing to wake and nothing to wait for.
        if ($ip !== null && $pinger->answers($ip)) {
            return new ActionResult(true, 0, "{$name} is already awake.\nAnswered a ping at {$ip}; no magic packet needed.");
        }

        try {
            $this->wake(
                $mac,
                (string) config('control_panel.network.broadcast'),
                (int) config('control_panel.network.wol_port'),
            );
        } catch (Throwable $e) {
            report($e);

            return new ActionResult(false, null, '', $e->getMessage());
        }

        $packet = "Magic packet sent to {$mac}.";

        if ($ip === null) {
            // Nothing to confirm against: the packet going out is all we know.
            return new ActionResult(true, 0, $packet);
        }

        return $this->waitForAnswer($pinger, $ip, $name, $packet);
    }

    /**
     * A magic packet "succeeding" only means it left the box: a PC that is
     * fully off (power cut, no WoL from S5) never answers it. Ping until the
     * PC replies or the wait runs out, and report what actually happened.
     */
    private function waitForAnswer(DevicePinger $pinger, string $ip, string $name, string $packet): ActionResult
    {
        $wait = max(1, (int) config('control_panel.network.wake_wait', 45));
        $every = max(1, (int) config('control_panel.network.wake_ping_every', 3));

        // Wall-clock waiting barely counts toward max_execution_time on Linux,
        // but give the request explicit headroom anyway.
        @set_time_limit($wait + 30);

        $started = now();
        $deadline = $started->copy()->addSeconds($wait);

        do {
            Sleep::for($every)->seconds();

            if ($pinger->answers($ip)) {
                $after = (int) round($started->diffInSeconds(now()));

                return new ActionResult(true, 0, "{$name} is awake.\n{$packet}\nAnswered a ping at {$ip} after {$after} s.");
            }
        } while (now()->lt($deadline));

        return new ActionResult(
            false,
            1,
            "{$packet}\nNo ping answer from {$ip} within {$wait} s.",
            "{$name} didn't wake up. It may be fully shut down rather than asleep.",
        );
    }

    private function resolveMac(Action $action, ?string $arg): ?string
    {
        // A device picked from the dropdown, or one pinned on the action itself
        // (e.g. franklin.wake); otherwise the main Windows PC.
        $id = $action->argKind === 'device' ? $arg : $action->device;

        if ($id !== null) {
            foreach (config('control_panel.devices', []) as $device) {
                if (($device['id'] ?? null) === $id) {
                    return $device['mac'] ?? null;
                }
            }

            return null;
        }

        return config('control_panel.windows.mac');
    }

    private function wake(string $mac, string $broadcast, int $port): void
    {
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $mac);

        if (strlen((string) $clean) !== 12) {
            throw new InvalidArgumentException("Invalid MAC address: {$mac}");
        }

        $packet = str_repeat("\xFF", 6).str_repeat(pack('H*', $clean), 16);

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Unable to create UDP socket (is the sockets extension enabled?).');
        }

        try {
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $sent = @socket_sendto($socket, $packet, strlen($packet), 0, $broadcast, $port);

            if ($sent === false) {
                throw new RuntimeException('Failed to send magic packet: '.socket_strerror(socket_last_error($socket)));
            }
        } finally {
            socket_close($socket);
        }
    }
}
