<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * "Resume recent" on the Start Session card: the chosen project's earlier
 * sessions with a Resume button each, running ones marked (Reconnect when the
 * session was resumed), an empty state and an error state that explain
 * themselves, and no sideways scrolling on a phone. Requests are stubbed in
 * the page: nothing reaches Gemini.
 */
class ResumeRecentTest extends DuskTestCase
{
    private const IDLE = '553979ad-2d64-4276-bcdf-b13fecd335bc';

    private const STUCK = 'c6d71019-6bf3-49c5-80d7-7eac7e84942a';

    /** Stub /actions/recent with $sessions (or an error) and record action POSTs. */
    private function stub(Browser $browser, array $sessions, ?string $error = null): void
    {
        $payload = json_encode(['sessions' => $sessions, 'error' => $error]);

        $browser->script("
            window.__posts = [];
            const realFetch = window.fetch;
            window.fetch = (url, opts) => {
                const u = String(url);
                const json = (body) => Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }));
                if (u.includes('/actions/recent')) return json({$payload});
                if (u.includes('/actions/sessions')) return json({ sessions: [], error: null });
                if (u.includes('/actions/win.')) {
                    window.__posts.push({ url: u, body: JSON.parse(opts.body) });
                    return json({ log_id: 1, action_id: u.split('/').pop(), status: 'success', exit_code: 0, terminal: true, output: '', error: '' });
                }
                return realFetch(url, opts);
            };
            const h = [...document.querySelectorAll('h3')].find(h => h.textContent.trim().startsWith('Gemini'));
            if (! Alpine.\$data(h.closest('[x-data]')).open) h.closest('button').click();
            Alpine.\$data(document.querySelector('[dusk=\"resume-recent\"]')).loadRecent();
        ");
        $browser->pause(400);
    }

    private function sessions(): array
    {
        return [
            ['id' => self::STUCK, 'title' => 'BinaryRabbit Star Wars gameplay', 'ago' => '1h ago', 'name' => '', 'last_prompt' => 'Split the video into two parts', 'running' => true, 'reconnectable' => true],
            ['id' => '426a8229-45c3-46b8-8f04-1492d37b3017', 'title' => 'Resuming sessions remotely on mobile', 'ago' => '1m ago', 'name' => 'hub-2e', 'last_prompt' => '', 'running' => true, 'reconnectable' => false],
            ['id' => self::IDLE, 'title' => str_repeat('A very long session title that keeps going ', 4), 'ago' => '2h ago', 'name' => '', 'last_prompt' => str_repeat('and a long last prompt too ', 8), 'running' => false, 'reconnectable' => false],
        ];
    }

    public function test_rows_resume_and_reconnect(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@resume-recent');

            $this->stub($browser, $this->sessions());

            $browser->assertSeeIn('@resume-recent', 'Resume recent')
                ->assertSeeIn('@resume-recent', 'BinaryRabbit Star Wars gameplay')
                ->assertSeeIn('@resume-recent', '2h ago')
                ->assertSeeIn('@resume-recent', 'named hub-2e')
                ->assertSeeIn('@resume-recent', 'Last: Split the video')
                ->assertSeeIn('@resume-recent', 'Open it in the Claude app')
                ->assertMissing('@recent-empty')
                ->screenshot('resume-recent');

            $rows = $browser->script("return document.querySelectorAll('[dusk=\"recent-row\"]').length")[0];
            $this->assertSame(3, $rows);
            // One Resume (the idle one), one Reconnect (the resumed one), none for the other.
            $this->assertSame(1, $browser->script("return document.querySelectorAll('[dusk=\"recent-resume\"]').length")[0]);
            $this->assertSame(1, $browser->script("return document.querySelectorAll('[dusk=\"recent-reconnect\"]').length")[0]);

            // Resume sends the selected project and exactly that session id.
            $project = $browser->value('@launch-project');
            $browser->click('@recent-resume')
                ->waitFor('@result-output')
                ->assertSeeIn('@result-output', 'is starting on Gemini');
            $posts = $browser->script('return window.__posts')[0];
            $this->assertStringEndsWith('/actions/win.resume-claude', $posts[0]['url']);
            $this->assertSame(['arg' => $project, 'arg2' => self::IDLE], $posts[0]['body']);

            // Reconnect asks first; dismissing sends nothing.
            $browser->click('@recent-reconnect')->assertDialogOpened(
                "Reconnect \"BinaryRabbit Star Wars gameplay\"?\n\nThis ends its current process on Gemini and resumes the same session again. Use it when the Claude app shows the session as disconnected."
            )->dismissDialog();
            $this->assertCount(1, $browser->script('return window.__posts')[0]);

            $browser->click('@recent-reconnect')->acceptDialog()->pause(300);
            $posts = $browser->script('return window.__posts')[0];
            $this->assertStringEndsWith('/actions/win.reconnect-claude', $posts[1]['url']);
            $this->assertSame(['arg' => $project, 'arg2' => self::STUCK], $posts[1]['body']);
        });
    }

    public function test_no_sideways_scroll_on_a_phone(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->resize(375, 812)
                ->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@resume-recent');

            $this->stub($browser, $this->sessions());

            $browser->assertSeeIn('@resume-recent', 'Reconnect')->screenshot('resume-recent-phone');

            [$scroll, $client] = $browser->script('return [document.documentElement.scrollWidth, document.documentElement.clientWidth]')[0];
            $this->assertLessThanOrEqual($client, $scroll, 'the dashboard must not scroll sideways on a phone');

            // Every row's button stays on screen.
            $right = $browser->script("return Math.max(...[...document.querySelectorAll('[dusk=\"recent-row\"] button')].map(b => b.getBoundingClientRect().right))")[0];
            $this->assertLessThanOrEqual($client, $right);

            $browser->resize(1280, 900);
        });
    }

    public function test_empty_and_error_states_explain_themselves(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@resume-recent');

            $this->stub($browser, []);
            $browser->assertVisible('@recent-empty')
                ->assertSeeIn('@recent-empty', 'No recent sessions for this project.')
                ->assertMissing('@recent-error');

            $this->stub($browser, [], "Couldn't reach Gemini. Is it awake?");
            $browser->assertVisible('@recent-error')
                ->assertSeeIn('@recent-error', "Couldn't reach Gemini. Is it awake?")
                ->assertSeeIn('@recent-error', 'Try again')
                ->assertMissing('@recent-empty')
                ->screenshot('resume-recent-error');
        });
    }
}
