<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ControlPanel\ShortcutVerbs;
use App\Support\ControlPanel\VerbResult;
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
 *
 * The verbs and their sentences live in {@see ShortcutVerbs}, shared with the
 * Household MCP connector; this controller only reads the request and turns
 * the answer into JSON.
 */
class ShortcutController extends Controller
{
    public function __construct(private ShortcutVerbs $verbs) {}

    public function wake(Request $request): JsonResponse
    {
        return $this->json($this->verbs->wake($request->user(), (string) $request->input('pc', '')));
    }

    public function sleep(Request $request): JsonResponse
    {
        return $this->json($this->verbs->sleep($request->user(), (string) $request->input('pc', '')));
    }

    public function status(Request $request): JsonResponse
    {
        return $this->json($this->verbs->status($request->user(), (string) $request->input('pc', '')));
    }

    /**
     * The project labels for a Shortcut's "Choose from List", A–Z. The label a
     * phone picks goes straight back as `project` to session().
     */
    public function projects(): JsonResponse
    {
        return $this->json($this->verbs->projects());
    }

    /**
     * Start a Claude remote-control session on Gemini in the project named by
     * the required `project` — its label ("Date Night") or key ("date-night"),
     * case-insensitive. Runs win.launch-claude exactly like the dashboard card.
     */
    public function session(Request $request): JsonResponse
    {
        return $this->json($this->verbs->session($request->user(), (string) $request->input('project', '')));
    }

    /**
     * The project's recent Claude sessions on Gemini, newest first, for a
     * Shortcut's "Choose from List". `choices` maps a readable line ("Title ·
     * 2h ago") to the session id that goes back as `session` to resume().
     * Without `project`, the newest sessions across every project.
     */
    public function recent(Request $request): JsonResponse
    {
        return $this->json($this->verbs->recent((string) $request->input('project', '')));
    }

    /**
     * Resume a session on Gemini with remote control: `session` = an id from
     * recent(), and optionally `project` as for session(). A still-running
     * resumed session is reconnected instead.
     */
    public function resume(Request $request): JsonResponse
    {
        return $this->json($this->verbs->resume(
            $request->user(),
            (string) $request->input('session', ''),
            (string) $request->input('project', ''),
        ));
    }

    /**
     * The Claude sessions running on Gemini right now (the End Session list),
     * each with the `pid` that end() takes. Read-only; not logged.
     */
    public function running(): JsonResponse
    {
        return $this->json($this->verbs->runningSessions());
    }

    /**
     * Stop one running Claude session on Gemini: `pid` = a pid from running().
     * Runs win.end-claude exactly like the dashboard's End Session card.
     */
    public function end(Request $request): JsonResponse
    {
        return $this->json($this->verbs->endSession($request->user(), (string) $request->input('pid', '')));
    }

    private function json(VerbResult $result): JsonResponse
    {
        return response()->json($result->toArray(), $result->status);
    }
}
