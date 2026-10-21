<?php

namespace App\Support\ControlPanel;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Lifeboat's backup health for the dashboard's Backups card, read from two
 * JSON files in control_panel.backups.status_dir: box.json (the mini-PC's
 * 03:30 run) and pc.json (Gemini's daily pull). Either may be missing,
 * unreadable or half-written; that reads as "no status yet", never an error
 * page. Everything comes back as plain words ready for the view.
 */
class BackupStatus
{
    public const HEALTHY = 'healthy';

    public const ATTENTION = 'attention';

    public const FAILING = 'failing';

    private const HEADLINES = [
        self::HEALTHY => 'All backups healthy',
        self::ATTENTION => 'Needs attention',
        self::FAILING => 'Backups are failing',
    ];

    private const KINDS = [
        'db' => 'Database',
        'uploads' => 'Photos & files',
        'env' => 'Settings, encrypted',
    ];

    /** Older than this and the nightly run has been missed. */
    private const BOX_MAX_HOURS = 26;

    /** Gemini may be off for a day; two days without a pull needs a look. */
    private const PC_MAX_HOURS = 48;

    private const DISK_WARN_PCT = 85;

    /** A database whose newest copy is older than this many days is behind. */
    private const DB_MAX_DAYS = 2;

    /** How many sites a reason names before it says "and N more". */
    private const NAMED = 3;

    private CarbonImmutable $now;

    private string $tz;

    /** @var array<string, string> lower-case site name => how to write it */
    private array $spellings = [];

    /**
     * @return array{level: string, headline: string, reasons: list<string>, box: array{level: string, text: string, when: ?string}, pc: array{level: string, text: string, when: ?string}, facts: list<array{label: string, value: string}>, items: list<array{site: string, server: ?string, what: string, date: string, size: string, level: string, note: ?string}>}
     */
    public static function load(): array
    {
        return (new self)->build();
    }

    private function build(): array
    {
        $this->tz = (string) config('control_panel.backups.timezone', 'America/Chicago');
        $this->now = CarbonImmutable::instance(now())->setTimezone($this->tz);

        $dir = $this->dir((string) config('control_panel.backups.status_dir', ''));
        [$box, $boxProblem] = $this->readJson($dir.DIRECTORY_SEPARATOR.'box.json');
        [$pc, $pcProblem] = $this->readJson($dir.DIRECTORY_SEPARATOR.'pc.json');

        $rows = array_values(array_filter(
            is_array($box['items'] ?? null) ? $box['items'] : [],
            fn ($row) => is_array($row) && is_string($row['site'] ?? null) && $row['site'] !== '',
        ));

        // Droplet dirs are lower-case ("droplet-singularcoalescence"); borrow
        // the mini-PC's spelling of the same site when there is one.
        foreach ([...array_column($rows, 'site'), ...config('control_panel.sites', [])] as $name) {
            $this->spellings[strtolower($name)] ??= $name;
        }

        $failing = [];
        $attention = [];

        // --- The mini-PC's nightly run -------------------------------------
        $lastRun = $this->time($box['lastRun'] ?? null);
        $boxStale = $lastRun === null || $lastRun->lt($this->now->subHours(self::BOX_MAX_HOURS));
        $failedSites = array_values(array_filter(
            is_array($box['failedSites'] ?? null) ? $box['failedSites'] : [],
            fn ($s) => is_string($s) && $s !== '',
        ));
        $boxFailed = $box !== null && (
            ($box['result'] ?? null) !== 'ok' || $this->int($box['failed'] ?? null) > 0 || $failedSites !== []
        );

        if ($box === null) {
            $failing[] = $boxProblem === 'missing'
                ? "The mini-PC hasn't sent a backup report yet."
                : "The mini-PC's backup report couldn't be read.";
        } elseif ($lastRun === null) {
            $failing[] = "The mini-PC's backup report doesn't say when it last ran.";
        } elseif ($boxStale) {
            $failing[] = 'The mini-PC last backed up '.$lastRun->diffForHumans().'.';
        }

        $when = $boxStale ? 'on its last run' : 'last night';
        if ($boxFailed) {
            $failing[] = $failedSites === []
                ? "The mini-PC's backup run had a problem {$when}."
                : $this->names(array_map(fn ($s) => $this->siteName($s)[0], $failedSites), 'site')." didn't back up {$when}.";
        }

        $usedPct = $this->int($box['diskUsedPct'] ?? null);
        if ($usedPct !== null && $usedPct >= self::DISK_WARN_PCT) {
            $attention[] = "The mini-PC's disk is {$usedPct}% full.";
        }

        // --- Every site's newest copy --------------------------------------
        $items = [];
        $seen = [];
        $staleDbs = [];
        foreach ($rows as $row) {
            $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
            $date = $this->date($row['newestDate'] ?? null);
            // Uploads and settings only change when their content does, so
            // only a database can fall behind.
            $stale = $kind === 'db' && ($date === null || $date->lt($this->now->startOfDay()->subDays(self::DB_MAX_DAYS)));
            $failed = in_array($row['site'], $failedSites, true);
            $seen[] = $row['site'];
            [$site, $server] = $this->siteName($row['site']);

            if ($stale && ! $failed) {
                $staleDbs[] = $date === null
                    ? "{$site}'s database has no recent backup."
                    : "{$site}'s database hasn't backed up since {$this->day($date)}.";
            }

            $items[] = [
                'site' => $site,
                'server' => $server,
                'what' => self::KINDS[$kind] ?? 'Backup',
                'date' => $date === null ? 'Unknown' : ucfirst($this->day($date)),
                'size' => $this->size($this->int($row['bytes'] ?? null)),
                'level' => $failed ? 'failed' : ($stale ? 'stale' : 'ok'),
                'note' => $failed ? "Didn't back up {$when}" : ($stale ? 'More than '.self::DB_MAX_DAYS.' days old' : null),
                'kind' => $kind,
                'sort' => [$failed ? 0 : ($stale ? 1 : 2), $date?->timestamp ?? 0, strtolower($site)],
            ];
        }

        // A site that failed before it ever had a copy still gets a row.
        foreach (array_diff($failedSites, $seen) as $missing) {
            [$site, $server] = $this->siteName($missing);
            $items[] = ['site' => $site, 'server' => $server, 'what' => 'Backup', 'date' => 'No backup yet', 'size' => '—', 'level' => 'failed', 'note' => "Didn't back up {$when}", 'kind' => '', 'sort' => [0, 0, strtolower($site)]];
        }

        usort($items, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        // When the whole run is late every database is late with it; the
        // run's own reason already says so.
        if (! $boxStale && $staleDbs !== []) {
            $attention = array_merge($attention, array_slice($staleDbs, 0, self::NAMED));
            if (count($staleDbs) > self::NAMED) {
                $more = count($staleDbs) - self::NAMED;
                $attention[] = $more.' more '.($more === 1 ? 'database is' : 'databases are').' behind too (see Every site below).';
            }
        }

        // --- Gemini's daily pull -------------------------------------------
        $lastPull = $this->time($pc['lastSuccess'] ?? null);
        $pcStale = $lastPull === null || $lastPull->lt($this->now->subHours(self::PC_MAX_HOURS));
        if ($pc === null && $pcProblem !== 'missing') {
            $attention[] = "This PC's collection report couldn't be read.";
        } elseif ($lastPull === null) {
            $attention[] = "This PC hasn't collected the backups yet.";
        } elseif ($pcStale) {
            $attention[] = 'This PC last collected the backups '.$lastPull->diffForHumans().'.';
        }

        $level = $failing !== [] ? self::FAILING : ($attention !== [] ? self::ATTENTION : self::HEALTHY);

        $ok = $this->int($box['ok'] ?? null);
        $total = $this->int($box['total'] ?? null);
        $boxLevel = ($box === null || $boxStale || $boxFailed) ? self::FAILING
            : (($usedPct !== null && $usedPct >= self::DISK_WARN_PCT) || $staleDbs !== [] ? self::ATTENTION : self::HEALTHY);

        $settings = collect($items)->firstWhere('kind', 'env');
        $uploads = collect($items)->where('kind', 'uploads')->count();
        $free = $this->int($box['diskFreeBytes'] ?? null);

        return [
            'level' => $level,
            'headline' => self::HEADLINES[$level],
            'reasons' => $level === self::HEALTHY
                ? ['Every site was backed up last night and this PC has a copy.']
                : array_merge($failing, $attention),
            'box' => [
                'level' => $boxLevel,
                'text' => $box === null || $lastRun === null
                    ? 'Mini-PC: no status yet'
                    : 'Mini-PC backed up '.($ok !== null && $total !== null ? "{$ok} of {$total} sites" : 'its sites'),
                'when' => $lastRun ? $this->when($lastRun) : null,
            ],
            'pc' => [
                'level' => $pcStale ? self::ATTENTION : self::HEALTHY,
                'text' => $lastPull === null ? 'This PC: no status yet' : 'This PC collected them',
                'when' => $lastPull ? $this->when($lastPull) : null,
            ],
            'facts' => $box === null ? [] : [
                ['label' => 'Space used by backups', 'value' => $this->size($this->int($box['backupsBytes'] ?? null))],
                ['label' => 'Disk free', 'value' => $this->size($free).($usedPct !== null ? " ({$usedPct}% used)" : '')],
                ['label' => 'Photos & files covered', 'value' => $uploads === 0 ? 'None yet' : $uploads.' '.($uploads === 1 ? 'site' : 'sites')],
                ['label' => 'Settings bundle', 'value' => $settings ? $settings['date'] : 'Not made yet'],
            ],
            'items' => array_map(fn ($i) => array_diff_key($i, ['kind' => 0, 'sort' => 0]), $items),
        ];
    }

    /** A relative status_dir (e.g. in .env.dusk) is relative to the app. */
    private function dir(string $dir): string
    {
        $dir = rtrim($dir, '/\\');

        return preg_match('#^([/\\\\]|[A-Za-z]:)#', $dir) === 1 ? $dir : base_path($dir);
    }

    /**
     * The decoded file, or null plus why: 'missing' (or not visible to
     * www-data) or 'unreadable' (can't open it, or not a JSON object).
     *
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function readJson(string $path): array
    {
        try {
            if (! @is_file($path)) {
                return [null, 'missing'];
            }
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($data) && ! array_is_list($data) ? [$data, ''] : [null, 'unreadable'];
        } catch (Throwable) {
            return [null, 'unreadable'];
        }
    }

    /** The files' times carry no zone: they are the box's (and Gemini's) local time. */
    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $this->tz)->setTimezone($this->tz);
        } catch (Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $this->tz)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** "3:30 am today", "10:00 am yesterday", "Sun 3:30 am", "Oct 12, 3:30 am". */
    private function when(CarbonImmutable $t): string
    {
        $time = $t->format('g:i a');
        // Rounded: a day either side of a clock change is 23 or 25 hours.
        $days = (int) round($t->startOfDay()->diffInDays($this->now->startOfDay(), false));

        return match (true) {
            $days === 0 => "{$time} today",
            $days === 1 => "{$time} yesterday",
            $days > 1 && $days < 7 => $t->format('D')." {$time}",
            $t->year === $this->now->year => $t->format('M j').", {$time}",
            default => $t->format('M j, Y').", {$time}",
        };
    }

    /** "today", "yesterday", "Oct 17", "Oct 17, 2025". */
    private function day(CarbonImmutable $d): string
    {
        $days = (int) round($d->diffInDays($this->now->startOfDay(), false));

        return match (true) {
            $days === 0 => 'today',
            $days === 1 => 'yesterday',
            $d->year === $this->now->year => $d->format('M j'),
            default => $d->format('M j, Y'),
        };
    }

    /**
     * The name to show and where it lives: "droplet-kitchen" is Kitchen on the
     * public server; "_env" is the one settings bundle for every site.
     *
     * @return array{0: string, 1: ?string}
     */
    private function siteName(string $site): array
    {
        if ($site === '_env') {
            return ['All sites', null];
        }
        if (str_starts_with($site, 'droplet-')) {
            $name = substr($site, strlen('droplet-'));

            return [$name === '_env' ? 'All sites' : ($this->spellings[strtolower($name)] ?? ucfirst($name)), 'public server'];
        }

        return [$site, null];
    }

    /** "Budget", "Budget and Kitchen", "Budget, Kitchen, Studio and 2 more sites". */
    private function names(array $names, string $noun): string
    {
        $shown = array_slice($names, 0, self::NAMED);
        $more = count($names) - count($shown);

        if ($more > 0) {
            return implode(', ', $shown)." and {$more} more ".($more === 1 ? $noun : $noun.'s');
        }
        if (count($shown) === 1) {
            return $shown[0];
        }

        return implode(', ', array_slice($shown, 0, -1)).' and '.end($shown);
    }

    /** "12 KB", "195 MB", "1.2 GB". */
    private function size(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        $units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        if ($i === 0) {
            return $bytes.' bytes';
        }

        return ($value < 10 ? number_format($value, 1) : number_format($value)).' '.$units[$i];
    }
}
