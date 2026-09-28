<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ControlPanel\ActionDispatcher;
use App\Support\ControlPanel\ActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token-authed endpoints for an iOS Shortcut that wakes/sleeps Gemini (the
 * owner's PC) or Franklin and asks whether it is up; `pc` picks which. It can
 * also list the Claude projects and start a session in one on Gemini.
 * Deliberately a fixed menu of verbs rather than a generic "run any action" route: the phone token only ever unlocks
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
     * The project labels for a Shortcut's "Choose from List", A–Z. The label a
     * phone picks goes straight back as `project` to session().
     */
    public function projects(): JsonResponse
    {
        $labels = array_values(config('control_panel.projects', []));
        natcasesort($labels);

        return response()->json([
            'ok' => true,
            'message' => count($labels).' projects.',
            'projects' => array_values($labels),
        ]);
    }

    /**
     * Start a Claude remote-control session on Gemini in the project named by
     * the required `project` — its label ("Date Night") or key ("date-night"),
     * case-insensitive. Runs win.launch-claude exactly like the dashboard card.
     */
    public function session(Request $request, ActionRegistry $registry, ActionDispatcher $dispatcher): JsonResponse
    {
        $projects = config('control_panel.projects', []);
        $wanted = mb_strtolower(trim((string) $request->input('project', '')));

        if ($wanted === '') {
            return response()->json(['ok' => false, 'message' => 'No project was chosen. Add project=<name>.'], 422);
        }

        foreach ($projects as $key => $label) {
            if ($wanted === mb_strtolower((string) $key) || $wanted === mb_strtolower((string) $label)) {
                return $this->run($request, $registry, $dispatcher, 'win.launch-claude', (string) $key, "Starting a Claude session in {$label}.");
            }
        }

        return response()->json(['ok' => false, 'message' => 'There is no project called '.mb_substr($wanted, 0, 40).'.'], 422);
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
