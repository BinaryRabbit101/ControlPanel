<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ControlPanel\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Backups card reads Lifeboat's box.json + pc.json from a directory on the
 * box. Each test writes its own pair into a temp dir; "now" is noon on
 * 2026-10-20 in the box's zone, so last night's run was at 03:30 today.
 */
class BackupsCardTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cp-backups-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['control_panel.backups.status_dir' => $this->dir, 'control_panel.backups.timezone' => 'America/Chicago']);
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'America/Chicago'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function box(array $overrides = []): array
    {
        return array_merge([
            'lastRun' => '2026-10-20T03:30:11', 'result' => 'ok', 'total' => 3, 'ok' => 3, 'failed' => 0, 'failedSites' => [],
            'items' => [
                ['site' => 'Budget', 'kind' => 'db', 'newestFile' => 'Budget-20261020.sqlite.gz', 'newestDate' => '2026-10-20', 'bytes' => 12345, 'count' => 7],
                ['site' => 'LittlePocketMeseum', 'kind' => 'uploads', 'newestFile' => 'LittlePocketMeseum-uploads-20261020.tar.gz', 'newestDate' => '2026-10-20', 'bytes' => 204000000, 'count' => 1],
                ['site' => '_env', 'kind' => 'env', 'newestFile' => '_env-20261020.tar.gz.age', 'newestDate' => '2026-10-20', 'bytes' => 9000, 'count' => 1],
            ],
            'backupsBytes' => 1288490188, 'deployBakBytes' => 0, 'deploySnapshotsPruned' => 0, 'diskFreeBytes' => 62277025792, 'diskUsedPct' => 42,
        ], $overrides);
    }

    private function pc(array $overrides = []): array
    {
        return array_merge([
            'lastRun' => '2026-10-20T10:00:04', 'lastResult' => 'ok', 'lastSuccess' => '2026-10-20T10:00:04',
            'pulled' => 27, 'failed' => 0, 'pruned' => 0, 'localFiles' => 266, 'localMb' => 122.4,
            'newestOnBox' => '2026-10-20', 'boxLastSummary' => 'SUMMARY ok=3 failed=0', 'uploadedAt' => '2026-10-20T10:00:09',
        ], $overrides);
    }

    private function write(?array $box, ?array $pc): void
    {
        if ($box !== null) {
            File::put($this->dir.'/box.json', json_encode($box));
        }
        if ($pc !== null) {
            File::put($this->dir.'/pc.json', json_encode($pc));
        }
    }

    private function dashboard()
    {
        return $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
    }

    public function test_healthy_backups_read_green_with_both_hops(): void
    {
        $this->write($this->box(), $this->pc());

        $status = BackupStatus::load();
        $this->assertSame('healthy', $status['level']);
        $this->assertSame(['Every site was backed up last night and this PC has a copy.'], $status['reasons']);

        $this->dashboard()
            ->assertSee('All backups healthy')
            ->assertSee('Mini-PC backed up 3 of 3 sites')
            ->assertSee('3:30 am today')
            ->assertSee('This PC collected them')
            ->assertSee('10:00 am today')
            ->assertSee('1.2 GB')
            ->assertSee('58 GB (42% used)')
            ->assertSee('Photos &amp; files covered', false)
            ->assertSee('Settings, encrypted')
            ->assertSee('All sites')
            ->assertDontSee('Needs attention')
            ->assertDontSee('Backups are failing');
    }

    public function test_the_card_sits_between_the_mini_pc_section_and_recent_actions(): void
    {
        $this->write($this->box(), $this->pc());

        $this->dashboard()->assertSeeInOrder(['Health check', '>Backups<', 'All backups healthy', 'Recent actions'], false);
    }

    public function test_a_failed_run_reads_red_and_names_the_site(): void
    {
        $this->write($this->box(['result' => 'failed', 'ok' => 2, 'failed' => 1, 'failedSites' => ['Budget']]), $this->pc());

        $status = BackupStatus::load();
        $this->assertSame('failing', $status['level']);
        $this->assertSame(["Budget didn't back up last night."], $status['reasons']);
        $this->assertSame('failed', $status['items'][0]['level']);
        $this->assertSame('Budget', $status['items'][0]['site']);

        $this->dashboard()
            ->assertSee('Backups are failing')
            ->assertSee('Budget didn&#039;t back up last night.', false)
            ->assertSee('Mini-PC backed up 2 of 3 sites');
    }

    public function test_many_failed_sites_are_summarised(): void
    {
        $this->write($this->box(['result' => 'failed', 'failedSites' => ['Budget', 'droplet-kitchen', 'Studio', 'Mantel', 'Cafe']]), $this->pc());

        $this->assertSame(
            ["Budget, Kitchen, Studio and 2 more sites didn't back up last night."],
            BackupStatus::load()['reasons'],
        );
    }

    public function test_a_run_older_than_26_hours_reads_red(): void
    {
        $this->write($this->box(['lastRun' => '2026-10-17T03:30:11']), $this->pc());

        $status = BackupStatus::load();
        $this->assertSame('failing', $status['level']);
        $this->assertSame(['The mini-PC last backed up 3 days ago.'], $status['reasons'], 'late databases are not listed again');
        $this->assertSame('Sat 3:30 am', $status['box']['when']);
    }

    public function test_a_pc_that_has_not_collected_for_two_days_needs_attention(): void
    {
        $this->write($this->box(), $this->pc(['lastRun' => '2026-10-20T10:00:00', 'lastResult' => 'failed', 'lastSuccess' => '2026-10-17T10:00:00']));

        $status = BackupStatus::load();
        $this->assertSame('attention', $status['level']);
        $this->assertSame(['This PC last collected the backups 3 days ago.'], $status['reasons']);
        $this->assertSame('attention', $status['pc']['level']);

        $this->dashboard()
            ->assertSee('Needs attention')
            ->assertSee('This PC last collected the backups 3 days ago.');
    }

    public function test_a_nearly_full_disk_needs_attention(): void
    {
        $this->write($this->box(['diskUsedPct' => 91]), $this->pc());

        $status = BackupStatus::load();
        $this->assertSame('attention', $status['level']);
        $this->assertSame(["The mini-PC's disk is 91% full."], $status['reasons']);
    }

    public function test_only_a_late_database_is_flagged_and_it_sorts_first(): void
    {
        $box = $this->box();
        $box['items'][] = ['site' => 'Kitchen', 'kind' => 'db', 'newestDate' => '2026-10-17', 'bytes' => 2048, 'count' => 7];
        $box['items'][] = ['site' => 'Mantel', 'kind' => 'db', 'newestDate' => '2026-10-18', 'bytes' => 2048, 'count' => 7];
        // Uploads and settings only change when their content does.
        $box['items'][] = ['site' => 'droplet-singularcoalescence', 'kind' => 'uploads', 'newestDate' => '2026-10-04', 'bytes' => 86000000, 'count' => 1];
        $this->write($box, $this->pc());

        $status = BackupStatus::load();
        $this->assertSame('attention', $status['level']);
        $this->assertSame(["Kitchen's database hasn't backed up since Oct 17."], $status['reasons']);

        $this->assertSame(['Kitchen', 'stale'], [$status['items'][0]['site'], $status['items'][0]['level']]);
        $this->assertSame('More than 2 days old', $status['items'][0]['note']);
        $singular = collect($status['items'])->firstWhere('site', 'SingularCoalescence');
        $this->assertSame(['ok', 'public server', 'Photos & files'], [$singular['level'], $singular['server'], $singular['what']]);
        $this->assertSame('ok', collect($status['items'])->firstWhere('site', 'Mantel')['level']);
    }

    public function test_the_public_servers_settings_bundle_reads_as_all_sites(): void
    {
        $box = $this->box();
        $box['items'][] = ['site' => 'droplet-_env', 'kind' => 'env', 'newestDate' => '2026-10-04', 'bytes' => 1800, 'count' => 1];
        $this->write($box, $this->pc());

        $bundle = collect(BackupStatus::load()['items'])->firstWhere('server', 'public server');
        $this->assertSame(['All sites', 'Settings, encrypted', 'ok'], [$bundle['site'], $bundle['what'], $bundle['level']]);
    }

    public function test_missing_files_read_as_no_status_yet(): void
    {
        $status = BackupStatus::load();
        $this->assertSame('failing', $status['level']);
        $this->assertSame([
            "The mini-PC hasn't sent a backup report yet.",
            "This PC hasn't collected the backups yet.",
        ], $status['reasons']);
        $this->assertSame([], $status['facts']);
        $this->assertSame([], $status['items']);

        $this->dashboard()
            ->assertSee('Backups are failing')
            ->assertSee('Mini-PC: no status yet')
            ->assertSee('This PC: no status yet')
            ->assertDontSee('Every site');
    }

    public function test_a_missing_directory_does_not_break_the_dashboard(): void
    {
        config(['control_panel.backups.status_dir' => $this->dir.'/nope']);

        $this->dashboard()->assertSee('Mini-PC: no status yet');
    }

    public function test_malformed_json_reads_as_unreadable_not_an_error(): void
    {
        File::put($this->dir.'/box.json', '{"lastRun": "2026-10-20T03:3');
        File::put($this->dir.'/pc.json', '[1, 2, 3]');

        $status = BackupStatus::load();
        $this->assertSame('failing', $status['level']);
        $this->assertSame([
            "The mini-PC's backup report couldn't be read.",
            "This PC's collection report couldn't be read.",
        ], $status['reasons']);

        $this->dashboard()->assertSee('Backups are failing');
    }

    public function test_wrongly_typed_fields_never_crash(): void
    {
        $this->write([
            'lastRun' => 'not a date', 'result' => ['ok'], 'total' => 'many', 'failedSites' => 'Budget',
            'items' => [null, 'Budget', ['kind' => 'db'], ['site' => 'Cafe', 'kind' => 'db', 'newestDate' => 20261020, 'bytes' => 'big']],
            'diskUsedPct' => 'lots',
        ], ['lastSuccess' => 12345]);

        $status = BackupStatus::load();
        $this->assertSame('failing', $status['level']);
        $this->assertSame(['Cafe'], array_column($status['items'], 'site'));
        $this->assertSame(['stale', 'Unknown', '—'], [$status['items'][0]['level'], $status['items'][0]['date'], $status['items'][0]['size']]);

        $this->dashboard()->assertSee('Backups are failing');
    }
}
