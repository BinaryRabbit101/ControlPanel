<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-200 leading-tight">
            {{ __('HUB') }}
        </h2>
        @if ($map)
            @php $c = $map['counts']; @endphp
            <p class="mt-1 text-sm text-gray-400" dusk="hub-summary">
                Generated
                @if ($map['generated_at'])
                    <span title="{{ $map['generated_at']->format('M j, Y g:i A') }}">{{ $map['generated_at']->diffForHumans() }}</span>
                    ({{ $map['generated_at']->format('M j, Y g:i A') }})
                @else
                    {{ $map['generated_raw'] !== '' ? $map['generated_raw'] : 'at an unknown time' }}
                @endif
                · {{ $c['project'] ?? 0 }} {{ Str::plural('project', $c['project'] ?? 0) }}
                · {{ $c['service'] ?? 0 }} {{ Str::plural('service', $c['service'] ?? 0) }}
                · {{ $c['idea'] ?? 0 }} {{ Str::plural('idea', $c['idea'] ?? 0) }}
            </p>
        @endif
    </x-slot>

    @php
        $statusClasses = [
            'building' => 'bg-blue-900/60 text-blue-200',
            'shipped' => 'bg-green-900/60 text-green-200',
            'stable' => 'bg-teal-900/60 text-teal-200',
            'dormant' => 'bg-amber-900/60 text-amber-200',
            'planned' => 'bg-gray-700 text-gray-300',
            'archived' => 'bg-gray-800 text-gray-500 ring-1 ring-gray-700',
        ];
        $backupLabels = [
            'github' => ['GitHub', 'text-green-300'],
            'local-git' => ['Local git only', 'text-amber-300'],
            'none' => ['No backup', 'text-red-300'],
        ];
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10">

            @if (! $map)
                <div class="bg-gray-800 shadow rounded-lg p-6" dusk="hub-empty">
                    <h3 class="font-semibold text-gray-100 text-lg">The HUB map hasn't been synced yet</h3>
                    <p class="mt-2 text-gray-300">
                        This page shows every project, service and idea once the map has been sent over from the PC.
                        Nothing has arrived yet (or the last copy couldn't be read).
                    </p>
                    <p class="mt-4 text-gray-300">To sync it, run this on the PC:</p>
                    <code class="mt-2 block bg-gray-900 rounded p-3 text-sm text-gray-100 break-all select-all">py C:\Users\binar\Documents\HUB\scripts\build-map.py</code>
                    <p class="mt-4 text-sm text-gray-400">Then reload this page.</p>
                </div>
            @else
                @foreach ($map['sections'] as $section)
                    <section dusk="family-{{ $section['id'] }}" class="space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-100 break-words">{{ $section['title'] }}</h3>
                            @if ($section['blurb'] !== '')
                                <p class="mt-1 text-sm text-gray-400 break-words">{{ $section['blurb'] }}</p>
                            @endif
                        </div>

                        @if ($section['cards'])
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                @foreach ($section['cards'] as $item)
                                    @php $isIdea = $item['kind'] === 'idea'; @endphp
                                    <article id="{{ $item['anchor'] }}" dusk="{{ $item['anchor'] }}"
                                             @class([
                                                 'min-w-0 rounded-lg p-4 scroll-mt-4 break-words',
                                                 'bg-gray-800 shadow border border-gray-700' => ! $isIdea,
                                                 'bg-gray-900 border-2 border-dashed border-gray-700 opacity-70' => $isIdea,
                                             ])>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="font-semibold text-gray-100 break-words min-w-0">{{ $item['name'] }}</h4>
                                            @if ($item['status'] !== '')
                                                <span class="text-xs px-2 py-0.5 rounded {{ $statusClasses[$item['status']] ?? 'bg-gray-700 text-gray-300' }}">{{ $item['status'] }}</span>
                                            @endif
                                            @if ($item['kind'] !== 'project')
                                                <span class="text-xs px-2 py-0.5 rounded border border-gray-600 text-gray-400">{{ $item['kind'] }}</span>
                                            @endif
                                        </div>

                                        @if ($item['what'] !== '')
                                            <p class="mt-2 text-sm text-gray-300">{{ $item['what'] }}</p>
                                        @endif

                                        @if ($item['next'] !== '')
                                            <p class="mt-2 text-sm text-gray-200"><span class="font-medium text-indigo-300">Next:</span> {{ $item['next'] }}</p>
                                        @endif

                                        @if ($item['folder'] !== '' || $item['repo'] !== '' || $item['urls'] || $item['box'] !== '')
                                            <div class="mt-3 text-sm space-y-1">
                                                <div class="text-xs uppercase tracking-wide text-gray-500">Lives</div>
                                                @if ($item['folder'] !== '')
                                                    <code class="block bg-gray-900 rounded px-2 py-1 text-xs text-gray-200 break-all select-all">{{ $item['folder'] }}</code>
                                                @endif
                                                @if ($item['repo'] !== '')
                                                    <div class="break-all"><a href="https://github.com/{{ $item['repo'] }}" target="_blank" rel="noopener" class="text-indigo-400 hover:underline">github.com/{{ $item['repo'] }}</a></div>
                                                @endif
                                                @foreach ($item['urls'] as $url)
                                                    <div class="break-all"><a href="{{ $url }}" target="_blank" rel="noopener" class="text-indigo-400 hover:underline">{{ $url }}</a></div>
                                                @endforeach
                                                @if ($item['box'] !== '')
                                                    <div class="text-gray-400 break-all">Box: {{ $item['box'] }}</div>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($item['connects'] || $item['used_by'])
                                            <div class="mt-3 text-sm space-y-1">
                                                <div class="text-xs uppercase tracking-wide text-gray-500">Connections</div>
                                                @foreach ($item['connects'] as $link)
                                                    <div class="text-gray-300" data-link="connects">→
                                                        @if ($link['anchor'])
                                                            <a href="#{{ $link['anchor'] }}" class="text-indigo-400 hover:underline">{{ $link['name'] }}</a>
                                                        @else
                                                            {{ $link['name'] }}
                                                        @endif
                                                        @if ($link['how'] !== '')
                                                            — {{ $link['how'] }}
                                                        @endif
                                                    </div>
                                                @endforeach
                                                @foreach ($item['used_by'] as $link)
                                                    <div class="text-gray-300" data-link="used_by">←
                                                        @if ($link['anchor'])
                                                            <a href="#{{ $link['anchor'] }}" class="text-indigo-400 hover:underline">{{ $link['name'] }}</a>
                                                        @else
                                                            {{ $link['name'] }}
                                                        @endif
                                                        @if ($link['how'] !== '')
                                                            — {{ $link['how'] }}
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($item['also_in'])
                                            <p class="mt-3 text-sm text-gray-400">Also in: {{ collect($item['also_in'])->pluck('title')->join(', ') }}</p>
                                        @endif

                                        @if ($item['notes'] !== '')
                                            <p class="mt-3 text-sm text-gray-400">{!! nl2br(e($item['notes'])) !!}</p>
                                        @endif

                                        @if ($item['last_active'] !== '' || $item['backup'] !== '')
                                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-400">
                                                @if ($item['last_active'] !== '')
                                                    <span>Last active {{ $item['last_active'] }}</span>
                                                @endif
                                                @if ($item['backup'] !== '')
                                                    @php [$bLabel, $bClass] = $backupLabels[$item['backup']] ?? [$item['backup'], 'text-gray-300']; @endphp
                                                    <span>Backup: <span class="{{ $bClass }}">{{ $bLabel }}</span>{{ $item['backup_note'] !== '' ? ' — '.$item['backup_note'] : '' }}</span>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($canLaunch && $item['session_key'] !== '' && array_key_exists($item['session_key'], $projects))
                                            <div class="mt-3 flex flex-wrap items-center gap-2" x-data="hubSession(@js($item['session_key']))">
                                                <button type="button" @click="start()" :disabled="busy"
                                                        dusk="start-{{ $item['session_key'] }}"
                                                        class="text-xs font-medium px-3 py-1.5 rounded text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50">
                                                    Start Claude session
                                                </button>
                                                <span class="text-xs" :class="failed ? 'text-red-300' : 'text-gray-400'" x-text="message"></span>
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @endif

                        @if ($section['stubs'])
                            <ul class="space-y-1 text-sm">
                                @foreach ($section['stubs'] as $stub)
                                    <li class="text-gray-400 break-words" dusk="stub-{{ $stub['anchor'] }}">
                                        <a href="#{{ $stub['anchor'] }}" class="text-indigo-400 hover:underline">{{ $stub['name'] }}</a>
                                        — lives in {{ $stub['home'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            @endif
        </div>
    </div>

    <script>
        // Same action + endpoint as the dashboard's "Start Session" (account default model).
        function hubSession(key) {
            return {
                busy: false,
                failed: false,
                message: '',
                async start() {
                    this.busy = true;
                    this.failed = false;
                    this.message = 'Starting…';
                    try {
                        const res = await fetch('/actions/' + encodeURIComponent('win.launch-claude'), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ arg: key, arg2: null }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (res.ok && data.status !== 'failed') {
                            this.message = 'Session started.';
                        } else {
                            this.failed = true;
                            this.message = data.message || data.error || ('Could not start (HTTP ' + res.status + ').');
                        }
                    } catch (e) {
                        this.failed = true;
                        this.message = 'Could not start: ' + e;
                    } finally {
                        this.busy = false;
                    }
                },
            };
        }
    </script>
</x-app-layout>
