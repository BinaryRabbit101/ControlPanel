<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Dashboard layout: a Gemini section, a Franklin section, then Mini-PC (no LAN
 * section). Every card is one of two columns: Wake | Sleep and Start | End sit
 * side by side, nothing spans the row. Positions are read from the real layout.
 */
class DashboardLayoutTest extends DuskTestCase
{
    public function test_sections_and_paired_cards(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@action-win.wake');

            $sections = $browser->script(
                "return [...document.querySelectorAll('h3')].map(h => h.firstChild.textContent.trim())"
            )[0];
            $this->assertSame(['Gemini', 'Franklin', 'Mini-PC', 'Recent actions'], $sections);

            // Open every section (their open state is remembered per browser).
            foreach (['Gemini', 'Franklin', 'Mini-PC'] as $section) {
                $browser->script("
                    const h = [...document.querySelectorAll('h3')].find(h => h.textContent.trim().startsWith('{$section}'));
                    const box = h.closest('[x-data]');
                    if (! Alpine.\$data(box).open) h.closest('button').click();
                ");
            }
            $browser->pause(300);

            $this->assertSideBySide($browser, 'win.wake', 'win.sleep');
            $this->assertSideBySide($browser, 'win.launch-claude', 'win.end-claude');
            $this->assertSideBySide($browser, 'franklin.wake', 'franklin.sleep');

            $this->assertSideBySide($browser, 'mini.health', 'mini.deploy');

            // Ping takes the left column on its own row, same width as Wake.
            $this->assertSameColumn($browser, 'win.ping', 'win.wake');
            $this->assertSameColumn($browser, 'franklin.ping', 'franklin.wake');

            $browser->assertSeeIn('@action-win.launch-claude', 'Start Session')
                ->assertSeeIn('@action-win.end-claude', 'End Session')
                ->assertMissing('@action-franklin.launch-claude')
                // Descriptions are hover titles only.
                ->assertDontSeeIn('@action-win.ping', 'Is Gemini awake?')
                ->assertAttribute('@action-win.ping', 'title', 'Is Gemini awake? (read-only)')
                ->assertMissing('@action-mini.reload-nginx')
                ->assertMissing('@action-mini.restart-phpfpm')
                ->screenshot('dashboard-layout');
        });
    }

    /** @return array{top: float, left: float, right: float} */
    private function rect(Browser $browser, string $id): array
    {
        return $browser->script(
            "const r = document.querySelector('[dusk=\"action-{$id}\"]').getBoundingClientRect();"
            .' return {top: r.top, left: r.left, right: r.right};'
        )[0];
    }

    private function assertSideBySide(Browser $browser, string $leftId, string $rightId): void
    {
        $a = $this->rect($browser, $leftId);
        $b = $this->rect($browser, $rightId);

        $this->assertEqualsWithDelta($a['top'], $b['top'], 1, "{$leftId} and {$rightId} should share a row");
        $this->assertGreaterThanOrEqual($a['right'], $b['left'], "{$rightId} should sit right of {$leftId}");
    }

    private function assertSameColumn(Browser $browser, string $id, string $aboveId): void
    {
        $r = $this->rect($browser, $id);
        $above = $this->rect($browser, $aboveId);

        $this->assertEqualsWithDelta($above['left'], $r['left'], 1, "{$id} should line up under {$aboveId}");
        $this->assertEqualsWithDelta($above['right'], $r['right'], 1, "{$id} should be one column wide");
    }
}
