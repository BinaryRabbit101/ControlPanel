<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ControlPanel\ActionDispatcher;
use App\Support\ControlPanel\ActionRegistry;
use App\Support\ControlPanel\RecentSessions;
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

        // Wake waits for the PC to answer a ping (up to ~45 s), so the reply
        // is the real outcome the handler reports, not "packet sent".
        return $this->run($request, $registry, $dispatcher, $pc['wake'], null, "Sent {$pc['name']} a wake-up packet.", speakResult: true);
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
        if (($project = $this->project($request)) instanceof JsonResponse) {
            return $project;
        }

        return $this->run($request, $registry, $dispatcher, 'win.launch-claude', $project['key'], "Starting a Claude session in {$project['label']}.");
    }

    /**
     * The project's recent Claude sessions on Gemini, newest first, for a
     * Shortcut's "Choose from List". `choices` maps a readable line ("Title ·
     * 2h ago") to the session id that goes back as `session` to resume().
     * Sessions running without a way to reconnect are left out of `choices`
     * (nothing the phone can do with them) but stay in `sessions`.
     */
    public function recent(Request $request, RecentSessions $recent): JsonResponse
    {
        // No project: the newest sessions across every project, one call.
        if (trim((string) $request->input('project', '')) === '') {
            return $this->recentEverywhere($recent);
        }

        if (($project = $this->project($request)) instanceof JsonResponse) {
            return $project;
        }

        $list = $recent->for($project['key']);
        if ($list['error'] !== null) {
            return response()->json(['ok' => false, 'message' => $list['error'], 'sessions' => [], 'choices' => (object) []]);
        }

        $choices = $this->choices($list['sessions'], false);
        $count = count($choices);

        return response()->json([
            'ok' => true,
            'message' => $count === 0
                ? "No recent sessions for {$project['label']}."
                : ($count === 1 ? "1 recent session in {$project['label']}." : "{$count} recent sessions in {$project['label']}."),
            'sessions' => $list['sessions'],
            'choices' => (object) $choices,
        ]);
    }

    /**
     * recent() with no project: about 30 sessions across all projects, newest
     * first; each `choices` key is "Title · Project · 2h ago". `skipped` =
     * sessions in folders that belong to no ControlPanel project (not listed).
     */
    private function recentEverywhere(RecentSessions $recent): JsonResponse
    {
        $list = $recent->all();
        if ($list['error'] !== null) {
            return response()->json(['ok' => false, 'message' => $list['error'], 'sessions' => [], 'choices' => (object) [], 'skipped' => 0]);
        }

        $choices = $this->choices($list['sessions'], true);
        $count = count($choices);

        return response()->json([
            'ok' => true,
            'message' => $count === 0
                ? 'No recent sessions.'
                : ($count === 1 ? '1 recent session.' : "{$count} recent sessions."),
            'sessions' => $list['sessions'],
            'choices' => (object) $choices,
            'skipped' => $list['skipped'],
        ]);
    }

    /**
     * "Title · 2h ago" (or "Title · Project · 2h ago") => session id. Running
     * sessions that can't be reconnected are left out: nothing the phone can
     * do with them.
     *
     * @param  array<int, array<string, mixed>>  $sessions
     * @return array<string, string>
     */
    private function choices(array $sessions, bool $withProject): array
    {
        $choices = [];
        foreach ($sessions as $s) {
            if ($s['running'] && ! $s['reconnectable']) {
                continue;
            }
            $line = $s['title']
                .($withProject ? ' · '.$s['project_label'] : '')
                .($s['ago'] !== '' ? ' · '.$s['ago'] : '')
                .($s['running'] ? ' · running, reconnects' : '');
            $key = $line;
            for ($n = 2; isset($choices[$key]); $n++) {
                $key = "{$line} ({$n})";
            }
            $choices[$key] = $s['id'];
        }

        return $choices;
    }

    /**
     * Resume a session on Gemini with remote control: `session` = an id from
     * recent(), and optionally `project` as for session(). Without `project`
     * the session's own folder decides it. A session that is still running
     * (its process outlived its link to the app) is reconnected: the process
     * that resumed exactly that id is ended and the session resumed again.
     */
    public function resume(Request $request, ActionRegistry $registry, ActionDispatcher $dispatcher, RecentSessions $recent): JsonResponse
    {
        $anyProject = trim((string) $request->input('project', '')) === '';
        if (! $anyProject && ($project = $this->project($request)) instanceof JsonResponse) {
            return $project;
        }

        $id = strtolower(trim((string) $request->input('session', '')));
        if ($id === '') {
            return response()->json(['ok' => false, 'message' => 'No session was chosen. Add session=<id from recent>.'], 422);
        }
        if (preg_match(ActionRegistry::SESSION_ID_PATTERN, $id) !== 1) {
            return response()->json(['ok' => false, 'message' => "That isn't a session id."], 422);
        }

        if ($anyProject) {
            $list = $recent->all($id);
            if ($list['error'] !== null) {
                return response()->json(['ok' => false, 'message' => $list['error']]);
            }
            $match = collect($list['sessions'])->firstWhere('id', $id);
            if ($match === null) {
                return response()->json(['ok' => false, 'message' => "That session isn't a Remote Control session in any ControlPanel project."], 422);
            }
            $project = ['key' => $match['project'], 'label' => $match['project_label']];
        } else {
            $list = $recent->for($project['key']);
            if ($list['error'] !== null) {
                return response()->json(['ok' => false, 'message' => $list['error']]);
            }
            $match = collect($list['sessions'])->firstWhere('id', $id);
            if ($match === null) {
                return response()->json(['ok' => false, 'message' => "That session isn't one of {$project['label']}'s recent sessions."], 422);
            }
        }

        if ($match['running'] && ! $match['reconnectable']) {
            return response()->json(['ok' => false, 'message' => "\"{$match['title']}\" is running on Gemini and wasn't started by resume, so it can't be reconnected from here."]);
        }

        return $match['running']
            ? $this->run($request, $registry, $dispatcher, 'win.reconnect-claude', $project['key'], "Reconnecting \"{$match['title']}\" in {$project['label']}.", arg2: $id)
            : $this->run($request, $registry, $dispatcher, 'win.resume-claude', $project['key'], "Resuming \"{$match['title']}\" in {$project['label']}.", arg2: $id);
    }

    /**
     * The project named by the required `project` — its label ("Date Night")
     * or key ("date-night"), case-insensitive.
     *
     * @return array{key: string, label: string}|JsonResponse
     */
    private function project(Request $request): array|JsonResponse
    {
        $wanted = mb_strtolower(trim((string) $request->input('project', '')));

        if ($wanted === '') {
            return response()->json(['ok' => false, 'message' => 'No project was chosen. Add project=<name>.'], 422);
        }

        foreach (config('control_panel.projects', []) as $key => $label) {
            if ($wanted === mb_strtolower((string) $key) || $wanted === mb_strtolower((string) $label)) {
                return ['key' => (string) $key, 'label' => (string) $label];
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
        bool $speakResult = false,
        ?string $arg2 = null,
    ): JsonResponse {
        $action = $registry->find($actionId);
        if ($action === null || ! $action->enabled) {
            return response()->json(['ok' => false, 'message' => 'That action is disabled.'], 403);
        }

        $log = $dispatcher->dispatch($request->user(), $action, $arg, $arg2);
        $ok = $log->status === 'success';

        if ($speakResult) {
            // The handler's own sentence: first output line on success, the
            // error on failure ("Gemini didn't wake up. …").
            $first = trim(strtok((string) $log->output, "\n") ?: '');
            $error = trim((string) $log->error);
            $okMessage = str_ends_with($first, 'awake.') ? $first : $okMessage;
            $failMessage = $error !== '' && ! str_contains($error, "\n") ? $error : null;
        }

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
