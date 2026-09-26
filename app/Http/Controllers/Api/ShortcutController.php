<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ControlPanel\ActionDispatcher;
use App\Support\ControlPanel\ActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token-authed endpoints for an iOS Shortcut that wakes/sleeps Gemini (the
 * owner's PC) or Franklin and asks whether it is up; `pc` picks which.
 * Deliberately a fixed menu of three verbs rather than a generic "run any action" route: the phone token only ever unlocks
 * these. Each verb maps onto an existing registry action so it is validated,
 * logged and executed exactly like a dashboard click, attributed to the user
 * whose API token was presented.
 */
class ShortcutController extends Controller
{
    public function wake(Request $request, ActionRegistry $registry, ActionDispatcher $dispatcher): JsonResponse
    {
        if (($pc = $this->pc($request)) instanceof JsonResponse) {
            return $pc;
        }

        return $this->run($request, $registry, $dispatcher, $pc['wake'], null, "Waking {$pc['name']}.");
    }

    public function sleep(Request $request, ActionRegistry $registry, ActionDispatcher $dispatcher): JsonResponse
    {
        if (($pc = $this->pc($request)) instanceof JsonResponse) {
            return $pc;
        }

        return $this->run($request, $registry, $dispatcher, $pc['sleep'], null, "Putting {$pc['name']} to sleep.");
    }

    public function status(Request $request, ActionRegistry $registry, ActionDispatcher $dispatcher): JsonResponse
    {
        if (($pc = $this->pc($request)) instanceof JsonResponse) {
            return $pc;
        }

        $name = ucfirst($pc['name']);

        return $this->run($request, $registry, $dispatcher, $pc['ping'], null, "{$name} is awake.", "{$name} is asleep.");
    }

    /**
     * The machine named by the required `pc` parameter (query or body),
     * case-insensitive. There is no default: a missing or blank `pc` is a
     * Shortcut whose menu choice never reached the URL, and guessing a PC
     * would wake or sleep the wrong machine.
     *
     * @return array{wake: string, sleep: string, ping: string, name: string}|JsonResponse
     */
    private function pc(Request $request): array|JsonResponse
    {
        $pcs = config('control_panel.shortcut.pcs', []);
        $key = mb_strtolower(trim((string) $request->input('pc', '')));

        if ($key === '') {
            return response()->json(['ok' => false, 'message' => 'No PC was chosen. Add pc=gemini or pc=franklin.'], 422);
        }

        if (! is_array($pcs[$key] ?? null)) {
            return response()->json(['ok' => false, 'message' => 'There is no PC called '.mb_substr($key, 0, 40).'.'], 422);
        }

        return $pcs[$key];
    }

    private function run(
        Request $request,
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

        $log = $dispatcher->dispatch($request->user(), $action, $arg);
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
