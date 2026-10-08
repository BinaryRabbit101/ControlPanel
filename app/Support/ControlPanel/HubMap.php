<?php

namespace App\Support\ControlPanel;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads the HUB map JSON (uploaded from the owner's PC) and shapes it for the
 * /hub page: families in order, each with its fully-open cards (sorted by
 * kind then status) and one-line stubs for items that also belong there.
 *
 * Everything except id/name/family/status/kind may be missing, so every field
 * is read defensively. A missing, unreadable or invalid file yields null.
 */
class HubMap
{
    /** Project status order inside a family. */
    private const STATUS_ORDER = ['building', 'shipped', 'stable', 'dormant', 'planned', 'archived'];

    /** Kinds in display order: projects, then services, ideas last. */
    private const KIND_ORDER = ['project', 'service', 'idea'];

    /**
     * @return array{generated_at: ?CarbonImmutable, generated_raw: string, counts: array<string, int>, sections: array<int, array<string, mixed>>}|null
     */
    public function load(string $path): ?array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items'])) {
            return null;
        }

        $items = [];
        foreach ($data['items'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $this->str($row['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $items[$id] = $this->normaliseItem($id, $row);
        }

        $families = [];
        foreach ((array) ($data['families'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $this->str($row['id'] ?? null);
            if ($id === '' || isset($families[$id])) {
                continue;
            }
            $families[$id] = [
                'id' => $id,
                'title' => $this->str($row['title'] ?? null) ?: $id,
                'blurb' => $this->str($row['blurb'] ?? null),
            ];
        }

        // Items whose family isn't listed still need a home.
        foreach ($items as $item) {
            if (! isset($families[$item['family']])) {
                $families[$item['family']] = [
                    'id' => $item['family'],
                    'title' => $item['family'] === '' ? 'Other' : $item['family'],
                    'blurb' => '',
                ];
            }
        }

        // Every "A connects to B" is also "B is used by A", even when the map
        // only recorded one side.
        foreach ($items as $id => $item) {
            foreach ($item['connects'] as $link) {
                $target = $link['id'];
                if (isset($items[$target]) && ! in_array($id, array_column($items[$target]['used_by'], 'id'), true)) {
                    $items[$target]['used_by'][] = ['id' => $id, 'how' => $link['how']];
                }
            }
            foreach ($item['used_by'] as $link) {
                $source = $link['id'];
                if (isset($items[$source]) && ! in_array($id, array_column($items[$source]['connects'], 'id'), true)) {
                    $items[$source]['connects'][] = ['id' => $id, 'how' => $link['how']];
                }
            }
        }

        // Resolve connection targets to names + anchors (both directions).
        foreach ($items as $id => $item) {
            $items[$id]['connects'] = $this->resolve($item['connects'], $items);
            $items[$id]['used_by'] = $this->resolve($item['used_by'], $items);
            $items[$id]['also_in'] = array_values(array_map(
                fn ($fid) => ['id' => $fid, 'title' => $families[$fid]['title'] ?? $fid],
                $item['also_in'],
            ));
        }

        $sections = [];
        foreach ($families as $fid => $family) {
            $cards = array_values(array_filter($items, fn ($i) => $i['family'] === $fid));
            usort($cards, fn ($a, $b) => $this->sortKey($a) <=> $this->sortKey($b));

            $stubs = [];
            foreach ($items as $item) {
                if ($item['family'] !== $fid && in_array($fid, array_column($item['also_in'], 'id'), true)) {
                    $stubs[] = [
                        'name' => $item['name'],
                        'anchor' => $item['anchor'],
                        'home' => $families[$item['family']]['title'] ?? $item['family'],
                    ];
                }
            }
            usort($stubs, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            if ($cards === [] && $stubs === []) {
                continue;
            }

            $sections[] = $family + ['cards' => $cards, 'stubs' => $stubs];
        }

        $counts = ['project' => 0, 'service' => 0, 'idea' => 0];
        foreach ($items as $item) {
            $counts[$item['kind']] = ($counts[$item['kind']] ?? 0) + 1;
        }

        $generatedRaw = $this->str($data['generated_at'] ?? null);

        return [
            'generated_at' => $this->date($generatedRaw),
            'generated_raw' => $generatedRaw,
            'counts' => $counts,
            'sections' => $sections,
        ];
    }

    /** @return array<string, mixed> */
    private function normaliseItem(string $id, array $row): array
    {
        $kind = strtolower($this->str($row['kind'] ?? null));
        $lives = is_array($row['lives'] ?? null) ? $row['lives'] : [];
        $notes = $this->str($row['notes'] ?? null);

        return [
            'id' => $id,
            'anchor' => 'item-'.$this->slug($id),
            'name' => $this->str($row['name'] ?? null) ?: $id,
            'kind' => in_array($kind, self::KIND_ORDER, true) ? $kind : 'project',
            'family' => $this->str($row['family'] ?? null),
            'also_in' => array_values(array_filter(array_map(
                fn ($v) => $this->str($v),
                is_array($row['also_in'] ?? null) ? $row['also_in'] : [],
            ), fn ($v) => $v !== '' && $v !== $this->str($row['family'] ?? null))),
            'status' => strtolower($this->str($row['status'] ?? null)),
            'what' => $this->str($row['what'] ?? null),
            'next' => $this->str($row['next'] ?? null),
            'last_active' => $this->str($row['last_active'] ?? null),
            'backup' => strtolower($this->str($row['backup'] ?? null)),
            'backup_note' => $this->str($row['backup_note'] ?? null),
            'folder' => $this->str($lives['folder'] ?? null),
            'repo' => $this->repo($lives['repo'] ?? null),
            'urls' => array_values(array_filter(
                array_map(fn ($u) => $this->str($u), is_array($lives['urls'] ?? null) ? $lives['urls'] : []),
                fn ($u) => preg_match('#^https?://#i', $u) === 1,
            )),
            'box' => $this->str($lives['box'] ?? null),
            'connects' => $this->links($row['connects'] ?? null, 'to'),
            'used_by' => $this->links($row['used_by'] ?? null, 'from'),
            'session_key' => $this->str($row['session_key'] ?? null),
            // Long notes are left off to keep the page scannable.
            'notes' => mb_strlen($notes) < 400 ? $notes : '',
        ];
    }

    /** @return array<int, array{id: string, how: string}> */
    private function links(mixed $value, string $key): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $row) {
            $id = is_array($row) ? $this->str($row[$key] ?? null) : '';
            if ($id !== '') {
                $out[] = ['id' => $id, 'how' => $this->str($row['how'] ?? null)];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{id: string, how: string}>  $links
     * @param  array<string, array<string, mixed>>  $items
     * @return array<int, array{name: string, anchor: ?string, how: string}>
     */
    private function resolve(array $links, array $items): array
    {
        return array_map(fn ($l) => [
            'name' => $items[$l['id']]['name'] ?? $l['id'],
            'anchor' => $items[$l['id']]['anchor'] ?? null,
            'how' => $l['how'],
        ], $links);
    }

    /** @return array{int, int, string} */
    private function sortKey(array $item): array
    {
        $kind = array_search($item['kind'], self::KIND_ORDER, true);
        $status = array_search($item['status'], self::STATUS_ORDER, true);

        return [
            $kind === false ? 0 : $kind,
            $status === false ? count(self::STATUS_ORDER) : $status,
            mb_strtolower($item['name']),
        ];
    }

    /** owner/name only — anything else is dropped rather than linked. */
    private function repo(mixed $value): string
    {
        $repo = trim(preg_replace('#^https?://github\.com/#i', '', $this->str($value)), '/');

        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1 ? $repo : '';
    }

    private function slug(string $id): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $id), '-') ?: 'x';
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
