<?php

namespace App\Http\Controllers\ControlPanel;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Support\ControlPanel\ActionDispatcher;
use App\Support\ControlPanel\ActionRegistry;
use App\Support\ControlPanel\RecentSessions;
use App\Support\ControlPanel\RunningSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionController extends Controller
{
    public function run(
        Request $request,
        string $action,
        ActionRegistry $registry,
        ActionDispatcher $dispatcher,
    ): JsonResponse {
        $definition = $registry->find($action);
        abort_if($definition === null, 404, 'Unknown action.');
        abort_unless($definition->enabled, 403, 'This action is disabled.');

        // Validates both args (422 on anything off the allowlist), logs, runs.
        $log = $dispatcher->dispatch(
            $request->user(), $definition, $request->input('arg'), $request->input('arg2'),
        );

        return response()->json($dispatcher->payload($log));
    }

    public function status(ActionLog $log, ActionDispatcher $dispatcher): JsonResponse
    {
        return response()->json($dispatcher->payload($log));
    }

    /**
     * Live list of running Claude sessions on Windows, used to populate the
     * "End Claude session" dropdown. Read-only; not written to action_logs.
     * Returns { sessions: [{pid, origin, name, project, model, started, session_id}], error }.
     * origin is "controlpanel" (a session ControlPanel launched; an older
     * Windows script only lists those) or "elsewhere" (Remote Control on,
     * started in a terminal or the app; the pid is claude.exe's own).
     */
    public function sessions(RunningSessions $running): JsonResponse
    {
        return response()->json($running->list());
    }

    /**
     * A project's recent Claude sessions, for the "Resume recent" list on the
     * Start Session card. Read-only; not written to action_logs.
     * Returns { sessions: [{id, title, ago, name, last_prompt, running, reconnectable, ...}], error }.
     */
    public function recent(Request $request, RecentSessions $recent): JsonResponse
    {
        return response()->json($recent->for((string) $request->query('project', '')));
    }
}
