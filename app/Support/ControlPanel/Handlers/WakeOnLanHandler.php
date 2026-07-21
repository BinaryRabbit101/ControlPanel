<?php

namespace App\Support\ControlPanel\Handlers;

use App\Support\ControlPanel\Action;
use App\Support\ControlPanel\ActionResult;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sends a Wake-on-LAN "magic packet" over UDP. Pure PHP (ext-sockets), no
 * external binary and no elevated privileges required.
 */
class WakeOnLanHandler implements Handler
{
    public function handle(Action $action, ?string $arg, ?string $arg2 = null): ActionResult
    {
        $mac = $this->resolveMac($action, $arg);

        if ($mac === null || $mac === '') {
            return new ActionResult(false, null, '', 'No MAC address configured for this target.');
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

        return new ActionResult(true, 0, "Magic packet sent to {$mac}.");
    }

    private function resolveMac(Action $action, ?string $arg): ?string
    {
        if ($action->argKind === 'device') {
            foreach (config('control_panel.devices', []) as $device) {
                if (($device['id'] ?? null) === $arg) {
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

        $packet = str_repeat("\xFF", 6) . str_repeat(pack('H*', $clean), 16);

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Unable to create UDP socket (is the sockets extension enabled?).');
        }

        try {
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $sent = @socket_sendto($socket, $packet, strlen($packet), 0, $broadcast, $port);

            if ($sent === false) {
                throw new RuntimeException('Failed to send magic packet: ' . socket_strerror(socket_last_error($socket)));
            }
        } finally {
            socket_close($socket);
        }
    }
}
