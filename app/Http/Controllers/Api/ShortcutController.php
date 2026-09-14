<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ControlPanel\ActionDispatcher;
use App\Support\ControlPanel\ActionRegistry;
use Illuminate\Http\JsonResponse;

/**
 * Token-authed endpoints for an iOS Shortcut that wakes/sleeps the Windows PC
 * (and asks whether it is up). Deliberately a fixed menu of three verbs rather
 * than a generic "run any action" route: the phone secret only ever unlocks
 * these. Each verb maps onto an existing registry action so it is validated,
 * logged and executed exactly like a dashboard click.
 */
class ShortcutController extends Controller
{
    public function wake(ActionRegistry $registry, ActionDispatcher $dispatcher): JsonResponse
    {
        return $this->run($registry, $dispatcher, 'win.wake', null, 'Waking the PC.');
    }

    public function sleep(ActionRegistry $registry, ActionDispatcher $dispatcher): JsonResponse
    {
        return $this->run($registry, $dispatcher, 'win.sleep', null, 'Putting the PC to sleep.');
    }

    public function status(ActionRegistry $registry, ActionDispatcher $dispatcher): JsonResponse
    {
        $device = (string) config('control_panel.shortcut.device', 'windows-pc');

        return $this->run($registry, $dispatcher, 'lan.ping', $device, 'The PC is awake.', 'The PC is asleep.');
    }

    private function run(
        ActionRegistry $registry,
        ActionDispatcher $dispatcher,
        string $actionId,
        ?string $arg,
        string $okMessage,
        ?string $failMessage = null,
    ): JsonResponse {
        $action = $registry->find($actionId);
        if ($action === null || ! $action->enabled) {
            return response()->json(['ok' => false, 'message' => 'That action is disabled.'], 403);
        }

        // Attributed to the seeded admin: the token is a per-instance secret,
        // not a per-user one, and the admin is the only account that exists.
        $user = User::query()->orderBy('id')->first();
        if ($user === null) {
            return response()->json(['ok' => false, 'message' => 'No admin user is seeded.'], 500);
        }

        $log = $dispatcher->dispatch($user, $action, $arg);
        $ok = $log->status === 'success';

        // `message` is what the Shortcut shows/speaks; everything else is for debugging.
        return response()->json([
            'ok' => $ok,
            'message' => $ok ? $okMessage : ($failMessage ?? $this->failure($log->error)),
            'action' => $dispatcher->payload($log),
        ]);
    }

    private function failure(?string $error): string
    {
        $error = trim((string) $error);

        return $error === '' ? 'That did not work.' : 'That did not work: '.mb_substr($error, 0, 200);
    }
}
