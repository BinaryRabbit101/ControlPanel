<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-200 leading-tight">
            {{ __('Control Panel') }}
        </h2>
    </x-slot>

    @php
        $argOptions = [
            'site' => collect($sites)->map(fn ($s) => ['value' => $s, 'label' => $s])->values(),
            'device' => collect($devices)->map(fn ($d) => ['value' => $d['id'], 'label' => $d['label'] ?? $d['id']])->values(),
            'project' => collect($projects)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'model' => collect($models)->map(fn ($m) => ['value' => $m['id'], 'label' => $m['label']])->values(),
            // 'session' is populated live over SSH by the Alpine component.
            'session' => collect(),
        ];
    @endphp

    <div class="py-8" x-data="controlPanel()">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Result panel --}}
            <div x-show="result.visible" x-cloak
                 class="bg-gray-800 shadow rounded-lg p-4 border-l-4"
                 :class="result.status === 'failed' ? 'border-red-500' : (result.terminal ? 'border-green-500' : 'border-blue-500')">
                <div class="flex items-center justify-between">
                    <div class="font-semibold text-gray-200">
                        <span x-text="result.label"></span>
                        <span class="text-sm font-normal text-gray-400" x-text="result.arg ? '(' + result.arg + ')' : ''"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <svg x-show="!result.terminal" class="animate-spin h-4 w-4 text-blue-500" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span class="text-xs uppercase tracking-wide px-2 py-0.5 rounded"
                              :class="{
                                'bg-blue-900/50 text-blue-300': result.status === 'running' || result.status === 'pending',
                                'bg-green-900/50 text-green-300': result.status === 'success',
                                'bg-red-900/50 text-red-300': result.status === 'failed',
                              }"
                              x-text="result.status"></span>
                        <button @click="result.visible = false" class="text-gray-400 hover:text-gray-200">&times;</button>
                    </div>
                </div>
                <template x-if="result.output">
                    <pre dusk="result-output" class="mt-3 text-xs bg-gray-900 text-gray-100 rounded p-3 overflow-x-auto whitespace-pre-wrap" x-text="result.output"></pre>
                </template>
                {{-- stderr. Only an error if the action actually failed — plenty
                     of tools (ssh especially) write warnings here on success. --}}
                <template x-if="result.error">
                    <pre class="mt-2 text-xs rounded p-3 overflow-x-auto whitespace-pre-wrap"
                         :class="result.status === 'failed' ? 'bg-red-950 text-red-200' : 'bg-amber-950 text-amber-200'"
                         x-text="result.error"></pre>
                </template>
            </div>

            {{-- Action cards --}}
            @foreach ($actions as $category => $group)
                <div x-data="section('{{ $category }}')"
                     class="bg-gray-800 shadow rounded-lg overflow-hidden">
                    <button type="button" @click="open = !open"
                            class="w-full px-5 py-3 border-b border-gray-700 flex items-center justify-between text-left"
                            :class="open || 'border-b-transparent'">
                        <h3 class="font-semibold text-gray-200">
                            {{ $category }}
                            <span class="ml-1 text-xs font-normal text-gray-400">({{ $group->count() }})</span>
                        </h3>
                        <svg class="h-5 w-5 text-gray-400 transition-transform duration-200"
                             :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"></path>
                        </svg>
                    </button>
                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="grid grid-cols-1 sm:grid-cols-2 gap-px bg-gray-700">
                        {{-- Two columns, registry order: Wake | Sleep, Start | End.
                             The description is a hover title, not a second line. --}}
                        @foreach ($group as $action)
                            <div dusk="action-{{ $action->id }}" title="{{ $action->description }}"
                                 class="bg-gray-800 px-5 py-4 flex flex-wrap content-start items-center justify-between gap-3">
                                <div>
                                    <div class="font-medium text-gray-200">
                                        {{ $action->label }}
                                        @if ($action->destructive)
                                            <span class="ml-2 text-xs px-1.5 py-0.5 rounded bg-red-900/50 text-red-300">destructive</span>
                                        @endif
                                        @if ($action->async)
                                            <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-amber-900/50 text-amber-300">async</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 shrink-0">
                                    @if ($action->argKind !== 'none')
                                        @php $opts = $argOptions[$action->argKind] ?? collect(); @endphp
                                        <select data-arg="{{ $action->id }}"
                                                @if ($action->argKind === 'session') data-dynamic="session" @endif
                                                @if ($action->id === 'win.launch-claude') dusk="launch-project" @change="loadRecent()" @endif
                                                class="text-sm rounded bg-gray-700 border-gray-600 text-gray-100">
                                            @if ($action->argKind === 'session')
                                                <option value="">(loading…)</option>
                                            @else
                                                @forelse ($opts as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @empty
                                                    <option value="">(none configured)</option>
                                                @endforelse
                                            @endif
                                        </select>
                                        @if ($action->argKind === 'session')
                                            <button type="button" @click="loadSessions()" title="Refresh sessions"
                                                    class="text-gray-400 hover:text-gray-200">
                                                <svg class="h-4 w-4" :class="sessionsLoading && 'animate-spin'" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"></path>
                                                </svg>
                                            </button>
                                        @endif
                                    @endif

                                    @if ($action->argKind2 !== 'none')
                                        @php $opts2 = $argOptions[$action->argKind2] ?? collect(); @endphp
                                        <select data-arg2="{{ $action->id }}"
                                                class="text-sm rounded bg-gray-700 border-gray-600 text-gray-100">
                                            @forelse ($opts2 as $opt)
                                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                            @empty
                                                <option value="">(none configured)</option>
                                            @endforelse
                                        </select>
                                    @endif

                                    <button
                                        @if ($action->enabled)
                                            @click="run('{{ $action->id }}', '{{ $action->label }}', '{{ $action->argKind }}', {{ $action->destructive ? 'true' : 'false' }})"
                                        @else disabled @endif
                                        class="text-sm font-medium px-4 py-2 rounded text-white disabled:opacity-40 disabled:cursor-not-allowed
                                               {{ $action->destructive ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700' }}">
                                        {{ $action->enabled ? 'Run' : 'Disabled' }}
                                    </button>
                                </div>

                                {{-- Resume recent: the chosen project's earlier sessions,
                                     newest first, so one lost to a power cut can be
                                     brought back from the phone. --}}
                                @if ($action->id === 'win.launch-claude' && $action->enabled)
                                    <div dusk="resume-recent" class="basis-full min-w-0 border-t border-gray-700 pt-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="text-sm font-medium text-gray-300">
                                                Resume recent
                                                <span class="font-normal text-gray-400" x-show="recent.label" x-text="'in ' + recent.label"></span>
                                            </div>
                                            <button type="button" @click="loadRecent()" title="Refresh recent sessions"
                                                    class="text-gray-400 hover:text-gray-200">
                                                <svg class="h-4 w-4" :class="recent.loading && 'animate-spin'" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"></path>
                                                </svg>
                                            </button>
                                        </div>

                                        <p x-show="recent.loading && !recent.rows.length" class="mt-2 text-sm text-gray-400">
                                            Looking for recent sessions on Gemini…
                                        </p>
                                        <div x-show="!recent.loading && recent.error" x-cloak dusk="recent-error" class="mt-2 text-sm text-amber-300">
                                            <span x-text="recent.error"></span>
                                            <button type="button" @click="loadRecent()" class="ml-1 underline text-amber-200 hover:text-amber-100">Try again</button>
                                        </div>
                                        <p x-show="!recent.loading && !recent.error && !recent.rows.length" x-cloak dusk="recent-empty" class="mt-2 text-sm text-gray-400">
                                            No recent sessions for this project. Use Run above to start a new one.
                                        </p>

                                        {{-- Grouped by who started it, newest first in each. --}}
                                        <template x-for="g in byOrigin(recent.rows)" :key="g.origin">
                                            <div dusk="recent-group" class="mt-2">
                                                <div dusk="recent-group-label" class="text-xs font-medium text-gray-400" x-text="g.label"></div>
                                                <ul class="divide-y divide-gray-700">
                                                    <template x-for="s in g.rows" :key="s.id">
                                                        <li dusk="recent-row" class="py-2 flex items-center gap-3">
                                                            <div class="min-w-0 flex-1">
                                                                <div class="text-sm text-gray-100 truncate" x-text="s.title" :title="s.title"></div>
                                                                <div class="text-xs text-gray-400 truncate">
                                                                    <span x-text="s.ago || 'unknown time'"></span>
                                                                    <template x-if="s.name"><span x-text="' · named ' + s.name"></span></template>
                                                                    <template x-if="s.running">
                                                                        <span class="ml-1 px-1.5 rounded bg-green-900/50 text-green-300">running</span>
                                                                    </template>
                                                                </div>
                                                                <div x-show="s.last_prompt" class="text-xs text-gray-500 truncate"
                                                                     x-text="'Last: ' + s.last_prompt" :title="s.last_prompt"></div>
                                                            </div>
                                                            <template x-if="!s.running">
                                                                <button type="button" dusk="recent-resume" @click="resumeSession(s, false)"
                                                                        class="shrink-0 text-sm font-medium px-3 py-1.5 rounded text-white bg-indigo-600 hover:bg-indigo-700">
                                                                    Resume
                                                                </button>
                                                            </template>
                                                            <template x-if="s.running && s.reconnectable">
                                                                <button type="button" dusk="recent-reconnect" @click="resumeSession(s, true)"
                                                                        title="Use this when the Claude app shows the session as disconnected."
                                                                        class="shrink-0 text-sm font-medium px-3 py-1.5 rounded text-white bg-amber-600 hover:bg-amber-700">
                                                                    Reconnect
                                                                </button>
                                                            </template>
                                                            <template x-if="s.running && !s.reconnectable">
                                                                <span class="shrink-0 text-xs text-gray-400 text-right w-20">Open it in the Claude app</span>
                                                            </template>
                                                        </li>
                                                    </template>
                                                </ul>
                                            </div>
                                        </template>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        {{-- Blank cell so an odd last card doesn't leave a grey hole. --}}
                        @if ($group->count() % 2 === 1)
                            <div class="hidden sm:block bg-gray-800"></div>
                        @endif
                    </div>
                </div>
            @endforeach

            @include('control-panel.partials.backups')

            {{-- Recent actions --}}
            <div class="bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-700 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-200">Recent actions</h3>
                    <button @click="window.location.reload()" class="text-xs text-indigo-400 hover:underline">refresh</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-gray-400 border-b border-gray-700">
                            <tr>
                                <th class="px-5 py-2 font-medium">When</th>
                                <th class="px-5 py-2 font-medium">Action</th>
                                <th class="px-5 py-2 font-medium">Arg</th>
                                <th class="px-5 py-2 font-medium">By</th>
                                <th class="px-5 py-2 font-medium">Status</th>
                                <th class="px-5 py-2 font-medium">Exit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            @forelse ($logs as $log)
                                <tr>
                                    <td class="px-5 py-2 text-gray-400 whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</td>
                                    <td class="px-5 py-2 font-mono text-xs">{{ $log->action_id }}</td>
                                    <td class="px-5 py-2">{{ $log->arg ?? '—' }}</td>
                                    <td class="px-5 py-2">{{ $log->user?->name ?? '—' }}</td>
                                    <td class="px-5 py-2">
                                        <span class="text-xs px-2 py-0.5 rounded
                                            @class([
                                                'bg-green-900/50 text-green-300' => $log->status === 'success',
                                                'bg-red-900/50 text-red-300' => $log->status === 'failed',
                                                'bg-blue-900/50 text-blue-300' => in_array($log->status, ['pending','running']),
                                            ])">{{ $log->status }}</span>
                                    </td>
                                    <td class="px-5 py-2">{{ $log->exit_code ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-4 text-center text-gray-400">No actions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        window.CP_CSRF = @json(csrf_token());

        // Collapsible section whose open/closed state is remembered per category.
        function section(category) {
            const key = 'cp:section:' + category;
            return {
                open: (localStorage.getItem(key) ?? 'open') !== 'closed',
                init() {
                    this.$watch('open', v => localStorage.setItem(key, v ? 'open' : 'closed'));
                },
            };
        }

        function controlPanel() {
            return {
                result: { visible: false, label: '', arg: '', status: '', output: '', error: '', terminal: true },
                sessions: [],
                sessionsError: '',
                sessionsLoading: false,

                recent: { project: '', label: '', rows: [], loading: false, error: '' },
                recentTicket: 0,

                init() {
                    this.loadSessions();
                    this.loadRecent();
                },

                // The chosen project's recent sessions on Gemini (read-only, never
                // logged). Only the newest request's answer is used, so a slow one
                // can't overwrite a later one (or another project's list).
                async loadRecent() {
                    const select = document.querySelector('select[data-arg="win.launch-claude"]');
                    if (!select || !select.value) return;
                    const project = select.value;
                    const ticket = ++this.recentTicket;
                    const label = select.options[select.selectedIndex]?.text || project;
                    if (this.recent.project !== project) this.recent.rows = [];
                    this.recent = { ...this.recent, project, label, loading: true, error: '' };
                    let data;
                    try {
                        const res = await fetch('/actions/recent?project=' + encodeURIComponent(project), { headers: { 'Accept': 'application/json' } });
                        data = res.ok ? await res.json() : { sessions: [], error: 'Could not list sessions (HTTP ' + res.status + ').' };
                    } catch (e) {
                        data = { sessions: [], error: "Couldn't reach the control panel. Check your connection." };
                    }
                    if (ticket !== this.recentTicket) return;
                    this.recent.rows = data.sessions || [];
                    this.recent.error = data.error || '';
                    this.recent.loading = false;
                },

                // Resume a session, or (reconnect) end the process that resumed it
                // and resume it again. Same result panel as every other action.
                async resumeSession(s, reconnect) {
                    const id = reconnect ? 'win.reconnect-claude' : 'win.resume-claude';
                    const label = reconnect ? 'Reconnect Session' : 'Resume Session';
                    if (reconnect && !confirm('Reconnect "' + s.title + '"?\n\nThis ends its current process on Gemini and resumes the same session again. Use it when the Claude app shows the session as disconnected.')) {
                        return;
                    }
                    const project = this.recent.project;
                    this.result = { visible: true, label, arg: this.recent.label + ' · ' + s.title, status: 'running', output: reconnect ? 'Ending the old process, then resuming…' : '', error: '', terminal: false };
                    window.scrollTo({ top: 0, behavior: 'smooth' });

                    try {
                        const res = await fetch('/actions/' + id, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.CP_CSRF },
                            body: JSON.stringify({ arg: project, arg2: s.id }),
                        });
                        if (!res.ok) {
                            const body = res.status === 422 ? await res.json() : {};
                            this.finish('failed', '', body.message || 'Request failed (HTTP ' + res.status + ').');
                            return;
                        }
                        const data = await res.json();
                        this.apply(data);
                        if (data.status === 'success') {
                            this.result.output = (data.output ? data.output + '\n' : '')
                                + '"' + s.title + '" is starting on Gemini. It shows up in the Claude app in a few seconds.';
                        }
                    } catch (e) {
                        this.finish('failed', '', String(e));
                        return;
                    }
                    // It starts on the desktop a moment later; then it reads as running.
                    setTimeout(() => { this.loadSessions(); this.loadRecent(); }, 5000);
                },

                // Fetch the live list of running Claude sessions and (re)fill the
                // "End Session" dropdown(s). Read-only; never logged.
                async loadSessions() {
                    this.sessionsLoading = true;
                    try {
                        const res = await fetch('/actions/sessions', { headers: { 'Accept': 'application/json' } });
                        const data = res.ok ? await res.json() : { sessions: [], error: 'HTTP ' + res.status };
                        this.sessions = data.sessions || [];
                        this.sessionsError = data.error || '';
                    } catch (e) {
                        this.sessions = [];
                        this.sessionsError = String(e);
                    } finally {
                        this.sessionsLoading = false;
                        this.fillSessionSelects();
                    }
                },

                // Two groups: sessions ControlPanel started, then the ones with
                // Remote Control on that were started elsewhere (a terminal, the
                // app). Each group only when it has rows.
                fillSessionSelects() {
                    document.querySelectorAll('select[data-dynamic="session"]').forEach(sel => {
                        const prev = sel.value;
                        sel.innerHTML = '';
                        if (!this.sessions.length) {
                            const o = document.createElement('option');
                            o.value = '';
                            o.textContent = this.sessionsError ? '(unavailable)' : '(none running)';
                            sel.appendChild(o);
                            return;
                        }
                        this.byOrigin(this.sessions).forEach(g => {
                            const group = document.createElement('optgroup');
                            group.label = g.label;
                            g.rows.forEach(s => {
                                const o = document.createElement('option');
                                o.value = s.pid;
                                o.textContent = this.sessionLabel(s);
                                group.appendChild(o);
                            });
                            sel.appendChild(group);
                        });
                        if (prev && this.sessions.some(s => s.pid === prev)) sel.value = prev;
                    });
                },

                // Rows split into "Started from ControlPanel" / "Started elsewhere"
                // (in that order, empty groups dropped), keeping their order.
                byOrigin(rows) {
                    return [
                        { origin: 'controlpanel', label: 'Started from ControlPanel' },
                        { origin: 'elsewhere', label: 'Started elsewhere' },
                    ].map(g => ({ ...g, rows: rows.filter(s => (s.origin === 'controlpanel' ? 'controlpanel' : 'elsewhere') === g.origin) }))
                        .filter(g => g.rows.length);
                },

                // "hub-74 · opus-4-8 · 10:13": the name the Claude app shows, the
                // model when one was chosen, and when it started (never the
                // project: every session starts in HUB).
                sessionLabel(s) {
                    return [s.name || s.project, s.model && s.model !== 'default' ? s.model : '', this.startedAt(s.started)]
                        .filter(Boolean).join(' · ');
                },

                // Gemini's local start time ("2026-10-20T10:13:39", no zone; the
                // dashboard is viewed in the same zone): "10:13" today, "Mon 18:02"
                // this past week, "Oct 12 18:02" before that.
                startedAt(iso) {
                    const d = iso ? new Date(iso) : null;
                    if (!d || isNaN(d)) return '';
                    const hm = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    if (d >= today) return hm;
                    if (today - d < 6 * 86400000) return d.toLocaleDateString('en-US', { weekday: 'short' }) + ' ' + hm;
                    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + hm;
                },

                async run(id, label, argKind, destructive) {
                    let arg = null;
                    if (argKind !== 'none') {
                        const select = document.querySelector('select[data-arg="' + id + '"]');
                        arg = select ? select.value : null;
                        if (!arg) { alert('No target configured for this action.'); return; }
                    }
                    // Optional second argument (e.g. the chosen model).
                    let arg2 = null;
                    const select2 = document.querySelector('select[data-arg2="' + id + '"]');
                    if (select2) arg2 = select2.value;

                    const label2 = arg2 && arg2 !== 'default' ? ' · ' + arg2 : '';
                    if (destructive && !confirm('Run "' + label + '"' + (arg ? ' (' + arg + ')' : '') + '? This is a destructive action.')) {
                        return;
                    }

                    // Wake waits (up to a minute) for the PC to answer a ping
                    // before replying, so say so instead of a bare spinner.
                    const waiting = id.endsWith('.wake')
                        ? 'Waking ' + label.replace(/^Wake\s+/, '') + ' and waiting for it to answer. This can take up to a minute.'
                        : '';
                    this.result = { visible: true, label, arg: (arg || '') + label2, status: 'running', output: waiting, error: '', terminal: false };

                    try {
                        const res = await fetch('/actions/' + encodeURIComponent(id), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': window.CP_CSRF,
                            },
                            body: JSON.stringify({ arg, arg2 }),
                        });

                        if (res.status === 422) {
                            const body = await res.json();
                            this.finish('failed', '', (body.message || 'Validation failed.'));
                            return;
                        }
                        if (!res.ok) {
                            this.finish('failed', '', 'Request failed (HTTP ' + res.status + ').');
                            return;
                        }

                        const data = await res.json();
                        this.apply(data);
                        if (!data.terminal) {
                            this.poll(data.log_id);
                        } else {
                            this.afterTerminal(id);
                        }
                    } catch (e) {
                        this.finish('failed', '', String(e));
                    }
                },

                // Launching or ending a session changes what's running — refresh
                // the live list (give the box a moment to settle first).
                afterTerminal(id) {
                    if (id === 'win.launch-claude' || id === 'win.end-claude') {
                        setTimeout(() => { this.loadSessions(); this.loadRecent(); }, 1500);
                    }
                },

                async poll(logId) {
                    for (let i = 0; i < 400; i++) {
                        await new Promise(r => setTimeout(r, 1500));
                        try {
                            const res = await fetch('/actions/logs/' + logId + '/status', {
                                headers: { 'Accept': 'application/json' },
                            });
                            if (!res.ok) continue;
                            const data = await res.json();
                            this.apply(data);
                            if (data.terminal) return;
                        } catch (e) { /* keep polling */ }
                    }
                    this.result.terminal = true;
                },

                apply(data) {
                    this.result.status = data.status;
                    this.result.output = data.output || '';
                    this.result.error = data.error || '';
                    this.result.terminal = !!data.terminal;
                },

                finish(status, output, error) {
                    this.result.status = status;
                    this.result.output = output;
                    this.result.error = error;
                    this.result.terminal = true;
                },
            };
        }
    </script>

    <style>[x-cloak]{display:none!important;}</style>
</x-app-layout>
