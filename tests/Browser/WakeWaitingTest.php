<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Wake now waits up to a minute for the PC to answer a ping, so while the
 * request is out the result panel says what it's doing, then shows the real
 * outcome. The action request is stubbed in the page: no packet, no ping.
 */
class WakeWaitingTest extends DuskTestCase
{
    public function test_wake_shows_a_waiting_message_then_the_outcome(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@action-win.wake');

            // Hold the wake request open until the test releases it.
            $browser->script("
                const realFetch = window.fetch;
                window.fetch = (url, opts) => {
                    if (String(url).endsWith('/actions/win.wake')) {
                        return new Promise(resolve => {
                            window.__releaseWake = () => resolve(new Response(JSON.stringify({
                                log_id: 1, action_id: 'win.wake', status: 'failed', exit_code: 1, terminal: true,
                                output: 'Magic packet sent to 34:5A:60:BB:6F:81.\\nNo ping answer from 192.168.0.197 within 45 s.',
                                error: \"Gemini didn't wake up. It may be fully shut down rather than asleep.\",
                            }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
                        });
                    }
                    return realFetch(url, opts);
                };
                const h = [...document.querySelectorAll('h3')].find(h => h.textContent.trim().startsWith('Gemini'));
                if (! Alpine.\$data(h.closest('[x-data]')).open) h.closest('button').click();
            ");

            $browser->pause(300)
                ->click('@action-win.wake button')
                ->waitFor('@result-output')
                ->assertSeeIn('@result-output', 'Waking Gemini and waiting for it to answer. This can take up to a minute.')
                ->screenshot('wake-waiting');

            $browser->script('window.__releaseWake()');

            $browser->waitForTextIn('@result-output', 'No ping answer')
                ->assertSee("Gemini didn't wake up. It may be fully shut down rather than asleep.")
                ->assertDontSee('This can take up to a minute.')
                ->screenshot('wake-failed');
        });
    }
}
