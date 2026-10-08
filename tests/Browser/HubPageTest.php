<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The HUB map page: one long open page, readable at phone width with no
 * horizontal scroll, and reachable from the nav. The served app reads the
 * default map path, so the fake fixture is put there for the run (any real
 * map already there is set aside and put back afterwards).
 */
class HubPageTest extends DuskTestCase
{
    private string $mapPath;

    private ?string $backup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapPath = storage_path('app/hub/map.json');
        if (is_file($this->mapPath)) {
            $this->backup = $this->mapPath.'.dusk-backup';
            rename($this->mapPath, $this->backup);
        }
        @mkdir(dirname($this->mapPath), 0775, true);
        copy(base_path('tests/Fixtures/hub-map.json'), $this->mapPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->mapPath);
        if ($this->backup !== null) {
            rename($this->backup, $this->mapPath);
        }

        parent::tearDown();
    }

    public function test_hub_fits_a_phone_and_a_desktop(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->resize(1280, 900)
                ->visit('/dashboard')
                ->assertPresent('@nav-hub')
                ->click('@nav-hub')
                ->waitFor('@item-seedling')
                ->assertPathIs('/hub')
                ->assertSee('Garden Tools')
                ->assertSeeIn('@item-trowel', 'Seedling')
                ->assertPresent('@start-controlpanel')
                ->assertMissing('@start-not-a-real-key');

            $this->assertNoHorizontalOverflow($browser);

            // Desktop: three columns, so the first two cards share a row.
            $a = $this->rect($browser, 'item-seedling');
            $b = $this->rect($browser, 'item-trowel');
            $c = $this->rect($browser, 'item-compost');
            $this->assertEqualsWithDelta($a['top'], $b['top'], 1);
            $this->assertEqualsWithDelta($a['top'], $c['top'], 1);
            $browser->screenshot('hub-desktop');

            $browser->resize(375, 812)->visit('/hub')->waitFor('@item-seedling');
            $this->assertNoHorizontalOverflow($browser);

            // Phone: one column, cards stacked.
            $a = $this->rect($browser, 'item-seedling');
            $b = $this->rect($browser, 'item-trowel');
            $this->assertGreaterThan($a['top'], $b['top']);
            $browser->screenshot('hub-phone');
        });
    }

    private function assertNoHorizontalOverflow(Browser $browser): void
    {
        [$scroll, $client] = $browser->script(
            'return [document.documentElement.scrollWidth, document.documentElement.clientWidth];'
        )[0];

        $this->assertLessThanOrEqual($client, $scroll, "Page scrolls sideways ({$scroll}px > {$client}px)");
    }

    /** @return array{top: float, left: float} */
    private function rect(Browser $browser, string $dusk): array
    {
        return $browser->script(
            "const r = document.querySelector('[dusk=\"{$dusk}\"]').getBoundingClientRect(); return {top: r.top, left: r.left};"
        )[0];
    }
}
