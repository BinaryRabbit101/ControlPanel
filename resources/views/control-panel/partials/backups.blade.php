{{-- Backups (Lifeboat): one headline and why, the mini-PC's run and this
     PC's pull, a few facts, then every site's newest copy behind a
     disclosure (anything late at the top). Read-only; built by BackupStatus. --}}
@php
    $tone = [
        'healthy' => ['box' => 'bg-green-900/30 border-green-700/60', 'text' => 'text-green-300', 'dot' => 'bg-green-400'],
        'attention' => ['box' => 'bg-amber-900/30 border-amber-700/60', 'text' => 'text-amber-300', 'dot' => 'bg-amber-400'],
        'failing' => ['box' => 'bg-red-900/30 border-red-700/60', 'text' => 'text-red-300', 'dot' => 'bg-red-400'],
    ];
    $itemTone = [
        'ok' => ['dot' => 'bg-green-400', 'text' => 'text-gray-300'],
        'stale' => ['dot' => 'bg-amber-400', 'text' => 'text-amber-300'],
        'failed' => ['dot' => 'bg-red-400', 'text' => 'text-red-300'],
    ];
    $t = $tone[$backups['level']];
@endphp

<div dusk="backups" class="bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-700">
        <h3 class="font-semibold text-gray-200">Backups</h3>
    </div>

    <div class="p-5 space-y-4">
        <div dusk="backups-status" data-level="{{ $backups['level'] }}" class="rounded-lg border px-4 py-3 {{ $t['box'] }}">
            <div class="flex items-center gap-2 text-lg font-semibold {{ $t['text'] }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    @if ($backups['level'] === 'healthy')
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"></path>
                    @elseif ($backups['level'] === 'attention')
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path>
                    @else
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"></path>
                    @endif
                </svg>
                <span dusk="backups-headline">{{ $backups['headline'] }}</span>
            </div>
            <ul class="mt-1 space-y-0.5 text-sm text-gray-200">
                @foreach ($backups['reasons'] as $reason)
                    <li dusk="backups-reason">{{ $reason }}</li>
                @endforeach
            </ul>
        </div>

        {{-- The two hops: the box makes the copies, this PC takes them home. --}}
        <ul class="space-y-1.5 text-sm text-gray-200">
            @foreach (['box', 'pc'] as $hop)
                <li dusk="backups-{{ $hop }}" class="flex items-start gap-2">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $tone[$backups[$hop]['level']]['dot'] }}"></span>
                    <span>
                        {{ $backups[$hop]['text'] }}
                        @if ($backups[$hop]['when'])
                            <span class="text-gray-400">· {{ $backups[$hop]['when'] }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>

        @if ($backups['facts'])
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                @foreach ($backups['facts'] as $fact)
                    <div class="rounded bg-gray-900/50 px-3 py-2 min-w-0">
                        <dt class="text-xs text-gray-400">{{ $fact['label'] }}</dt>
                        <dd class="text-sm font-medium text-gray-100 break-words">{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($backups['items'])
            <details dusk="backups-sites" class="group rounded border border-gray-700">
                <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden px-3 py-2 flex items-center justify-between text-sm font-medium text-gray-200">
                    <span>Every site <span class="font-normal text-gray-400">({{ count($backups['items']) }})</span></span>
                    <svg class="h-5 w-5 text-gray-400 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"></path>
                    </svg>
                </summary>
                <ul class="divide-y divide-gray-700 border-t border-gray-700">
                    @foreach ($backups['items'] as $item)
                        <li dusk="backups-site" data-level="{{ $item['level'] }}" class="px-3 py-2 flex items-start gap-2">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $itemTone[$item['level']]['dot'] }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="min-w-0 break-words text-sm text-gray-100">
                                        {{ $item['site'] }}
                                        @if ($item['server'])
                                            <span class="text-xs text-gray-400">· {{ $item['server'] }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-sm {{ $itemTone[$item['level']]['text'] }}">{{ $item['date'] }}</span>
                                </div>
                                <div class="text-xs text-gray-400">
                                    {{ $item['what'] }} · {{ $item['size'] }}
                                    @if ($item['note'])
                                        <span class="{{ $itemTone[$item['level']]['text'] }}">· {{ $item['note'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</div>
