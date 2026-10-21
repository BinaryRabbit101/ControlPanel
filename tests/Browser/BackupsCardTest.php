<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Backups card on a real page: an amber headline that says why, "Every
 * site" closed until tapped with the late database on top, and no sideways
 * scrolling on a phone. The status files are written into the directory the
 * served app reads (CP_BACKUP_STATUS_DIR in .env.dusk.local), dated from
 * today so the run is always "last night".
 */
class BackupsCardTest extends DuskTestCase
{
    private function dir(): string
    {
        $dir = (string) config('control_panel.backups.status_dir');

        return preg_match('#^([/\\\\]|[A-Za-z]:)#', $dir) === 1 ? $dir : base_path($dir);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $tz = (string) config('control_panel.backups.timezone');
        $now = now()->setTimezone($tz);
        $today = $now->format('Y-m-d');

        $items = [];
        foreach (['AiCampaignManager', 'Budget', 'Cafe', 'ControlPanel', 'Hearth', 'Mantel', 'Navigation', 'PasswordVault', 'Studio', 'TheCorgiSquad'] as $site) {
            $items[] = ['site' => $site, 'kind' => 'db', 'newestDate' => $today, 'bytes' => 48000 + strlen($site) * 9000, 'count' => 7];
        }
        $items[] = ['site' => 'Kitchen', 'kind' => 'db', 'newestDate' => $now->copy()->subDays(4)->format('Y-m-d'), 'bytes' => 310000, 'count' => 7];
        $items[] = ['site' => 'droplet-singularcoalescence', 'kind' => 'uploads', 'newestDate' => $now->copy()->subDays(16)->format('Y-m-d'), 'bytes' => 86000000, 'count' => 1];
        $items[] = ['site' => '_env', 'kind' => 'env', 'newestDate' => $today, 'bytes' => 9000, 'count' => 1];

        File::ensureDirectoryExists($this->dir());
        File::put($this->dir().'/box.json', json_encode([
            'lastRun' => $now->copy()->subHours(2)->format('Y-m-d\TH:i:s'), 'result' => 'ok',
            'total' => count($items), 'ok' => count($items), 'failed' => 0, 'failedSites' => [], 'items' => $items,
            'backupsBytes' => 1288490188, 'deployBakBytes' => 0, 'deploySnapshotsPruned' => 0,
            'diskFreeBytes' => 62277025792, 'diskUsedPct' => 42,
        ]));
        File::put($this->dir().'/pc.json', json_encode([
            'lastRun' => $now->copy()->subHour()->format('Y-m-d\TH:i:s'), 'lastResult' => 'ok',
            'lastSuccess' => $now->copy()->subHour()->format('Y-m-d\TH:i:s'), 'pulled' => 27, 'failed' => 0,
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir());

        parent::tearDown();
    }

    public function test_the_card_explains_itself_and_lists_every_site(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@backups')
                ->assertSeeIn('@backups-headline', 'Needs attention')
                ->assertAttribute('@backups-status', 'data-level', 'attention')
                ->assertSeeIn('@backups-reason', "Kitchen's database hasn't backed up since")
                ->assertSeeIn('@backups-box', 'Mini-PC backed up 13 of 13 sites')
                ->assertSeeIn('@backups-pc', 'This PC collected them')
                // Closed until tapped.
                ->assertDontSee('Photos & files · 82 MB')
                ->click('@backups-sites summary')
                ->pause(200)
                ->assertSeeIn('@backups-sites', 'More than 2 days old')
                ->assertSeeIn('@backups-sites', 'SingularCoalescence · public server')
                ->screenshot('backups-desktop');

            $first = $browser->script("return document.querySelector('[dusk=\"backups-site\"]').dataset.level + ' ' + document.querySelector('[dusk=\"backups-site\"]').textContent.trim().split(/\\s+/)[0]")[0];
            $this->assertSame('stale Kitchen', $first, 'the late database sits at the top');
        });
    }

    public function test_no_sideways_scroll_on_a_phone(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->resize(375, 812)
                ->loginAs($user)
                ->visit('/dashboard')
                ->waitFor('@backups')
                ->click('@backups-sites summary')
                ->pause(200)
                ->scrollIntoView('@backups')
                ->screenshot('backups-phone');

            [$scroll, $client] = $browser->script('return [document.documentElement.scrollWidth, document.documentElement.clientWidth]')[0];
            $this->assertLessThanOrEqual($client, $scroll, 'the dashboard must not scroll sideways on a phone');

            $right = $browser->script("return Math.max(...[...document.querySelectorAll('[dusk=\"backups\"] *')].map(e => e.getBoundingClientRect().right))")[0];
            $this->assertLessThanOrEqual($client, $right, 'nothing in the card pokes off screen');

            $browser->resize(1280, 900);
        });
    }
}
