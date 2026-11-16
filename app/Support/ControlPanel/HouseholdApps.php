<?php

namespace App\Support\ControlPanel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Household connector's way into Shopping List and Reminders: plain HTTP
 * calls to the phone-key endpoints those apps already serve, made with the
 * owner's tokens from config('control_panel.household'). Nothing here changes
 * either app; it only reads their replies and passes their own `message`
 * through, so Claude says what the app said.
 *
 * Tokens never appear in a reply or a log line: failures are reworded here
 * (an exception message can carry the URL, and the Reminders feed takes its
 * token in the query string), never re-thrown or reported.
 */
class HouseholdApps
{
    private const SHOPPING = 'Shopping List';

    private const REMINDERS = 'Reminders';

    /** GET {shoppinglist}/api/widget/list — the unticked items, grouped. */
    public function shoppingList(): VerbResult
    {
        return $this->call(self::SHOPPING, 'shoppinglist', fn (PendingRequest $http, string $base) => $http->get($base.'/api/widget/list'),
            fn (array $json) => (string) ($json['text'] ?? ''),
            ['count', 'groups'],
        );
    }

    /** GET {shoppinglist}/api/widget/groups — the group names, top of the list first. */
    public function shoppingGroups(): VerbResult
    {
        return $this->call(self::SHOPPING, 'shoppinglist', fn (PendingRequest $http, string $base) => $http->get($base.'/api/widget/groups'),
            function (array $json): string {
                $options = array_values(array_filter((array) ($json['options'] ?? []), 'is_string'));

                return $options === [] ? 'The list has no groups.' : 'Groups: '.implode(', ', $options).'.';
            },
            ['options'],
        );
    }

    /**
     * POST {shoppinglist}/api/widget/items — `{name, note?, group?}` for one
     * item or `{text, group?}` (one per line) for several.
     *
     * @param  array<string, string>  $payload
     */
    public function addShoppingItems(array $payload): VerbResult
    {
        return $this->call(self::SHOPPING, 'shoppinglist', fn (PendingRequest $http, string $base) => $http->post($base.'/api/widget/items', $payload),
            fn (array $json) => '',
            ['duplicate', 'added', 'duplicates', 'item', 'items'],
        );
    }

    /**
     * POST {reminders}/api/shortcut/reminders with `X-Shortcut-Token` (the
     * header that endpoint reads today).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createReminder(array $payload): VerbResult
    {
        return $this->call(self::REMINDERS, 'reminders', fn (PendingRequest $http, string $base, string $token) => $http
            ->withHeaders(['X-Shortcut-Token' => $token])
            ->post($base.'/api/shortcut/reminders', $payload),
            fn (array $json) => '',
            ['id', 'title', 'due_at', 'due_label', 'list', 'is_shared'],
            tokenHeader: false,
        );
    }

    /**
     * GET {reminders}/api/widget/today?token=… — the widget feed: what's due
     * today (and overdue), plus the next few after today.
     */
    public function todaysReminders(): VerbResult
    {
        return $this->call(self::REMINDERS, 'reminders', fn (PendingRequest $http, string $base, string $token) => $http
            ->get($base.'/api/widget/today', ['token' => $token]),
            fn (array $json) => $this->todaySentence($json),
            ['overdue_count', 'today', 'upcoming', 'next_upcoming', 'pending_total'],
            tokenHeader: false,
        );
    }

    /**
     * POST {reminders}/api/push with `X-Api-Token` — a push to the owner's
     * phone ("me") or both phones ("both").
     *
     * @param  array<string, string>  $payload
     */
    public function sendPush(array $payload): VerbResult
    {
        return $this->call(self::REMINDERS, 'reminders', fn (PendingRequest $http, string $base) => $http->post($base.'/api/push', $payload),
            fn (array $json) => '',
            ['sent', 'failed', 'skipped'],
        );
    }

    /**
     * @param  callable(PendingRequest, string, string): Response  $send
     * @param  callable(array<string, mixed>): string  $sentence  used when the app sent no `message`
     * @param  array<int, string>  $keep  fields of the app's reply worth handing on
     */
    private function call(string $app, string $key, callable $send, callable $sentence, array $keep, bool $tokenHeader = true): VerbResult
    {
        $token = trim((string) config("control_panel.household.{$key}_token", ''));
        $base = rtrim((string) config("control_panel.household.{$key}_url", ''), '/');

        if ($token === '' || $base === '') {
            return new VerbResult(false, "{$app} isn't connected yet.", ['connected' => false]);
        }

        $http = Http::acceptJson()
            ->timeout(max(1, (int) config('control_panel.household.timeout', 8)))
            ->connectTimeout(max(1, (int) config('control_panel.household.connect_timeout', 3)));

        if ($tokenHeader) {
            $http = $http->withHeaders(['X-Api-Token' => $token]);
        }

        try {
            $response = $send($http, $base, $token);
        } catch (Throwable) {
            return new VerbResult(false, "Couldn't reach {$app}. Try again in a minute.");
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $appMessage = is_string($json['message'] ?? null) ? trim($json['message']) : '';

        if (! $response->successful() || ($json['ok'] ?? true) === false) {
            return new VerbResult(false, $this->refusal($app, $response->status(), $appMessage, $json), [
                'http_status' => $response->status(),
            ]);
        }

        $message = $appMessage !== '' ? $appMessage : trim($sentence($json));

        return new VerbResult(true, $message !== '' ? $message : 'Done.', array_intersect_key($json, array_flip($keep)));
    }

    /**
     * The app's own words when it gave any, else a plain sentence. A 422's
     * first field error is the useful part ("The title field is required.").
     *
     * @param  array<string, mixed>  $json
     */
    private function refusal(string $app, int $status, string $appMessage, array $json): string
    {
        if ($status === 401 || $status === 403) {
            return "{$app} turned the key down. Its phone key may have changed.";
        }

        if ($status === 422 && is_array($json['errors'] ?? null)) {
            $first = collect($json['errors'])->flatten()->first();
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        if ($appMessage !== '' && mb_strlen($appMessage) <= 200) {
            return $appMessage;
        }

        return "{$app} couldn't do that (HTTP {$status}).";
    }

    /**
     * "Nothing due today." / "3 due today: Bins at 9:00 AM, …" plus the
     * overdue count — the widget feed carries no sentence of its own.
     *
     * @param  array<string, mixed>  $json
     */
    private function todaySentence(array $json): string
    {
        $today = array_values(array_filter((array) ($json['today'] ?? []), 'is_array'));
        $overdue = (int) ($json['overdue_count'] ?? 0);

        $lines = array_map(function (array $row): string {
            $title = trim((string) ($row['title'] ?? ''));
            $time = trim((string) ($row['time'] ?? ''));

            return $time !== '' ? "{$title} at {$time}" : $title;
        }, $today);

        $sentence = $lines === []
            ? 'Nothing due today.'
            : count($lines).' due today: '.implode(', ', $lines).'.';

        if ($overdue > 0) {
            $sentence .= $overdue === 1 ? ' 1 is overdue.' : " {$overdue} are overdue.";
        }

        $next = $json['next_upcoming'] ?? null;
        if ($lines === [] && is_array($next) && ($next['title'] ?? '') !== '') {
            $sentence .= ' Next: '.$next['title'].(($next['when'] ?? '') !== '' ? ', '.$next['when'] : '').'.';
        }

        return $sentence;
    }
}
