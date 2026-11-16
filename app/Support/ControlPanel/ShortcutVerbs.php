<?php

namespace App\Support\ControlPanel;

use App\Http\Controllers\Api\ShortcutController;
use App\Mcp\Servers\HouseholdServer;
use App\Models\User;

/**
 * The fixed menu of verbs a phone may ask for — wake / sleep / status of a
 * PC, list projects, start / resume / end a Claude session on Gemini — and
 * the one sentence each answers with. Shared by the iOS Shortcut API
 * ({@see ShortcutController}) and the Household MCP
 * connector ({@see HouseholdServer}) so both take the same
 * path and say the same words.
 *
 * Every action goes through {@see ActionDispatcher} as the given user, so it
 * is validated against the allowlists, written to action_logs and run by the
 * same handlers as a dashboard click. Nothing here ever builds a shell string.
 */
class ShortcutVerbs
{
    public function __construct(
        private ActionRegistry $registry,
        private ActionDispatcher $dispatcher,
        private RecentSessions $recent,
        private RunningSessions $running,
    ) {}

    public function wake(User $user, ?string $pc): VerbResult
    {
        if (($pc = $this->pc($pc)) instanceof VerbResult) {
            return $pc;
        }

        // Wake waits for the PC to answer a ping (up to ~45 s), so the reply
        // is the real outcome the handler reports, not "packet sent".
        return $this->run($user, $pc['wake'], null, "Sent {$pc['name']} a wake-up packet.", speakResult: true);
    }

    public function sleep(User $user, ?string $pc): VerbResult
    {
        if (($pc = $this->pc($pc)) instanceof VerbResult) {
            return $pc;
        }

        return $this->run($user, $pc['sleep'], null, "Putting {$pc['name']} to sleep.");
    }

    public function status(User $user, ?string $pc): VerbResult
    {
        if (($pc = $this->pc($pc)) instanceof VerbResult) {
            return $pc;
        }

        $name = ucfirst($pc['name']);

        return $this->run($user, $pc['ping'], null, "{$name} is awake.", "{$name} is asleep.");
    }

    /**
     * The project labels, A–Z. The label a phone picks goes straight back as
     * `project` to session().
     */
    public function projects(): VerbResult
    {
        $labels = array_values(config('control_panel.projects', []));
        natcasesort($labels);

        return new VerbResult(true, count($labels).' projects.', ['projects' => array_values($labels)]);
    }

    /**
     * Start a Claude remote-control session on Gemini in the project named by
     * `project` — its label ("Date Night") or key ("date-night"),
     * case-insensitive. Runs win.launch-claude exactly like the dashboard card.
     */
    public function session(User $user, ?string $project): VerbResult
    {
        if (($project = $this->project($project)) instanceof VerbResult) {
            return $project;
        }

        return $this->run($user, 'win.launch-claude', $project['key'], "Starting a Claude session in {$project['label']}.");
    }

    /**
     * The project's recent Claude sessions on Gemini, newest first. `choices`
     * maps a readable line ("Title · 2h ago") to the session id that goes back
     * as `session` to resume(). Sessions running without a way to reconnect
     * are left out of `choices` but stay in `sessions`. No project: the newest
     * sessions across every project, one call.
     */
    public function recent(?string $project): VerbResult
    {
        if (trim((string) $project) === '') {
            return $this->recentEverywhere();
        }

        if (($project = $this->project($project)) instanceof VerbResult) {
            return $project;
        }

        $list = $this->recent->for($project['key']);
        if ($list['error'] !== null) {
            return new VerbResult(false, $list['error'], ['sessions' => [], 'choices' => (object) []]);
        }

        $choices = $this->choices($list['sessions'], false);
        $count = count($choices);

        return new VerbResult(
            true,
            $count === 0
                ? "No recent sessions for {$project['label']}."
                : ($count === 1 ? "1 recent session in {$project['label']}." : "{$count} recent sessions in {$project['label']}."),
            ['sessions' => $list['sessions'], 'choices' => (object) $choices],
        );
    }

    /**
     * recent() with no project: about 30 sessions across all projects, newest
     * first; each `choices` key is "Title · Project · 2h ago". `skipped` =
     * sessions in folders that belong to no ControlPanel project (not listed).
     */
    private function recentEverywhere(): VerbResult
    {
        $list = $this->recent->all();
        if ($list['error'] !== null) {
            return new VerbResult(false, $list['error'], ['sessions' => [], 'choices' => (object) [], 'skipped' => 0]);
        }

        $choices = $this->choices($list['sessions'], true);
        $count = count($choices);

        return new VerbResult(
            true,
            $count === 0
                ? 'No recent sessions.'
                : ($count === 1 ? '1 recent session.' : "{$count} recent sessions."),
            ['sessions' => $list['sessions'], 'choices' => (object) $choices, 'skipped' => $list['skipped']],
        );
    }

    /**
     * Resume a session on Gemini with remote control: `session` = an id from
     * recent(), and optionally `project` as for session(). Without `project`
     * the session's own folder decides it. A session that is still running
     * (its process outlived its link to the app) is reconnected: the process
     * that resumed exactly that id is ended and the session resumed again.
     */
    public function resume(User $user, ?string $session, ?string $project): VerbResult
    {
        $anyProject = trim((string) $project) === '';
        if (! $anyProject && ($project = $this->project($project)) instanceof VerbResult) {
            return $project;
        }

        $id = strtolower(trim((string) $session));
        if ($id === '') {
            return new VerbResult(false, 'No session was chosen. Add session=<id from recent>.', status: 422);
        }
        if (preg_match(ActionRegistry::SESSION_ID_PATTERN, $id) !== 1) {
            return new VerbResult(false, "That isn't a session id.", status: 422);
        }

        if ($anyProject) {
            $list = $this->recent->all($id);
            if ($list['error'] !== null) {
                return new VerbResult(false, $list['error']);
            }
            $match = collect($list['sessions'])->firstWhere('id', $id);
            if ($match === null) {
                return new VerbResult(false, "That session isn't a Remote Control session in any ControlPanel project.", status: 422);
            }
            $project = ['key' => $match['project'], 'label' => $match['project_label']];
        } else {
            $list = $this->recent->for($project['key']);
            if ($list['error'] !== null) {
                return new VerbResult(false, $list['error']);
            }
            $match = collect($list['sessions'])->firstWhere('id', $id);
            if ($match === null) {
                return new VerbResult(false, "That session isn't one of {$project['label']}'s recent sessions.", status: 422);
            }
        }

        if ($match['running'] && ! $match['reconnectable']) {
            return new VerbResult(false, "\"{$match['title']}\" is running on Gemini and wasn't started by resume, so it can't be reconnected from here.");
        }

        return $match['running']
            ? $this->run($user, 'win.reconnect-claude', $project['key'], "Reconnecting \"{$match['title']}\" in {$project['label']}.", arg2: $id)
            : $this->run($user, 'win.resume-claude', $project['key'], "Resuming \"{$match['title']}\" in {$project['label']}.", arg2: $id);
    }

    /**
     * The Claude sessions running on Gemini right now (the End Session list),
     * each with the `pid` endSession() takes. Read-only; not logged.
     */
    public function runningSessions(): VerbResult
    {
        $list = $this->running->list();
        if ($list['error'] !== null) {
            return new VerbResult(false, $this->unreachable($list['error']), ['sessions' => []]);
        }

        $count = count($list['sessions']);

        return new VerbResult(
            true,
            $count === 0
                ? 'No Claude sessions are running on Gemini.'
                : ($count === 1 ? '1 Claude session is running on Gemini.' : "{$count} Claude sessions are running on Gemini."),
            ['sessions' => $list['sessions']],
        );
    }

    /**
     * Stop one running Claude session on Gemini by the `pid` runningSessions()
     * listed. Windows re-checks the pid is a Claude session before ending it.
     */
    public function endSession(User $user, ?string $pid): VerbResult
    {
        $pid = trim((string) $pid);
        if ($pid === '') {
            return new VerbResult(false, 'No session was chosen. List the running sessions first.', status: 422);
        }
        if (preg_match('/^[1-9][0-9]{0,6}$/', $pid) !== 1) {
            return new VerbResult(false, "That isn't a running session's number.", status: 422);
        }

        return $this->run($user, 'win.end-claude', $pid, 'Ending that Claude session.');
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
     * The project named by `project` — its label ("Date Night") or key
     * ("date-night"), case-insensitive.
     *
     * @return array{key: string, label: string}|VerbResult
     */
    public function project(?string $project): array|VerbResult
    {
        $wanted = mb_strtolower(trim((string) $project));

        if ($wanted === '') {
            return new VerbResult(false, 'No project was chosen. Add project=<name>.', status: 422);
        }

        foreach (config('control_panel.projects', []) as $key => $label) {
            if ($wanted === mb_strtolower((string) $key) || $wanted === mb_strtolower((string) $label)) {
                return ['key' => (string) $key, 'label' => (string) $label];
            }
        }

        return new VerbResult(false, 'There is no project called '.mb_substr($wanted, 0, 40).'.', status: 422);
    }

    /**
     * The machine named by `pc`, case-insensitive. There is no default: a
     * missing or blank `pc` is a Shortcut whose menu choice never reached the
     * URL, and guessing a PC would wake or sleep the wrong machine.
     *
     * @return array{wake: string, sleep: string, ping: string, name: string}|VerbResult
     */
    public function pc(?string $pc): array|VerbResult
    {
        $pcs = config('control_panel.shortcut.pcs', []);
        $key = mb_strtolower(trim((string) $pc));

        if ($key === '') {
            return new VerbResult(false, 'No PC was chosen. Add pc=gemini or pc=franklin.', status: 422);
        }

        if (! is_array($pcs[$key] ?? null)) {
            return new VerbResult(false, 'There is no PC called '.mb_substr($key, 0, 40).'.', status: 422);
        }

        return $pcs[$key];
    }

    private function run(
        User $user,
        string $actionId,
        ?string $arg,
        string $okMessage,
        ?string $failMessage = null,
        bool $speakResult = false,
        ?string $arg2 = null,
    ): VerbResult {
        $action = $this->registry->find($actionId);
        if ($action === null || ! $action->enabled) {
            return new VerbResult(false, 'That action is disabled.', status: 403);
        }

        $log = $this->dispatcher->dispatch($user, $action, $arg, $arg2);
        $ok = $log->status === 'success';

        if ($speakResult) {
            // The handler's own sentence: first output line on success, the
            // error on failure ("Gemini didn't wake up. …").
            $first = trim(strtok((string) $log->output, "\n") ?: '');
            $error = trim((string) $log->error);
            $okMessage = str_ends_with($first, 'awake.') ? $first : $okMessage;
            $failMessage = $error !== '' && ! str_contains($error, "\n") ? $error : null;
        }

        // `message` is what the phone shows/speaks; everything else is for debugging.
        return new VerbResult(
            $ok,
            $ok ? $okMessage : ($failMessage ?? $this->failure($log->error)),
            ['action' => $this->dispatcher->payload($log)],
        );
    }

    /** A sentence for a failed session listing, not raw ssh stderr. */
    private function unreachable(string $error): string
    {
        $error = trim($error);

        if ($error === '' || str_contains($error, 'Could not reach') || stripos($error, 'timed out') !== false
            || str_contains($error, 'No route') || str_contains($error, 'Connection refused')) {
            return "Couldn't reach Gemini. Is it awake?";
        }

        if (! str_contains($error, "\n") && str_ends_with($error, '.') && mb_strlen($error) <= 80) {
            return $error;
        }

        return "Couldn't list Gemini's sessions: ".mb_substr(strtok($error, "\n") ?: $error, 0, 160);
    }

    private function failure(?string $error): string
    {
        $error = trim((string) $error);

        return $error === '' ? 'That did not work.' : 'That did not work: '.mb_substr($error, 0, 200);
    }
}
