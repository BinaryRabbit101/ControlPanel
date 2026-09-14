<section x-data="{ copied: false }" dusk="api-token">
    <header>
        <h2 class="text-lg font-medium text-gray-100">
            {{ __('API token') }}
        </h2>

        <p class="mt-1 text-sm text-gray-400">
            {{ __('Lets an iPhone Shortcut wake or sleep the Windows PC without signing in. One token per account; it is shown once, so copy it right away.') }}
        </p>
    </header>

    @if (session('status') === 'api-token-minted' && session('api_token'))
        <div class="mt-6 rounded-md border border-indigo-500/40 bg-gray-900 p-4">
            <p class="text-xs uppercase tracking-widest text-indigo-300">{{ __('Your new token') }}</p>
            <code id="api-token-plain" class="mt-2 block break-all font-mono text-sm text-gray-100">{{ session('api_token') }}</code>
            <div class="mt-3 flex items-center gap-3">
                <x-secondary-button
                    type="button"
                    dusk="api-token-copy"
                    @click="navigator.clipboard.writeText(document.getElementById('api-token-plain').textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                >{{ __('Copy') }}</x-secondary-button>
                <p x-show="copied" x-transition class="text-sm text-gray-400">{{ __('Copied.') }}</p>
            </div>
            <p class="mt-3 text-xs text-gray-400">{{ __('This is the only time it will be displayed. Rotate to get a new one.') }}</p>
        </div>
    @endif

    <div class="mt-6 flex items-center gap-4">
        <form method="post" action="{{ route('profile.api-token.store') }}">
            @csrf
            @if ($user->hasApiToken())
                <x-primary-button dusk="api-token-rotate" onclick="return confirm('Rotate the API token? The old token stops working immediately.')">{{ __('Rotate') }}</x-primary-button>
            @else
                <x-primary-button dusk="api-token-generate">{{ __('Generate') }}</x-primary-button>
            @endif
        </form>

        @if ($user->hasApiToken())
            <form method="post" action="{{ route('profile.api-token.destroy') }}">
                @csrf
                @method('delete')
                <x-danger-button dusk="api-token-revoke" onclick="return confirm('Revoke the API token? Your Shortcuts will stop working.')">{{ __('Revoke') }}</x-danger-button>
            </form>

            <p class="text-sm text-gray-400">
                {{ __('Active since :when.', ['when' => $user->api_token_created_at?->format('M j, Y H:i')]) }}
            </p>
        @elseif (session('status') === 'api-token-revoked')
            <p
                x-data="{ show: true }"
                x-show="show"
                x-transition
                x-init="setTimeout(() => show = false, 2000)"
                class="text-sm text-gray-400"
            >{{ __('Revoked.') }}</p>
        @endif
    </div>

    <details class="mt-6 text-sm text-gray-400">
        <summary class="cursor-pointer text-gray-300">{{ __('How to build the Shortcut') }}</summary>
        <div class="mt-3 space-y-3">
            <p>{{ __('Base URL:') }} <code class="font-mono text-gray-200">{{ rtrim(config('app.url'), '/') }}</code></p>
            <table class="w-full text-left text-xs">
                <thead class="text-gray-500">
                    <tr><th class="pb-1 pr-3 font-medium">Shortcut</th><th class="pb-1 pr-3 font-medium">Method</th><th class="pb-1 font-medium">Path</th></tr>
                </thead>
                <tbody class="font-mono text-gray-200">
                    <tr><td class="pr-3 font-sans text-gray-400">Wake PC</td><td class="pr-3">POST</td><td>/api/shortcut/wake</td></tr>
                    <tr><td class="pr-3 font-sans text-gray-400">Sleep PC</td><td class="pr-3">POST</td><td>/api/shortcut/sleep</td></tr>
                    <tr><td class="pr-3 font-sans text-gray-400">Is PC awake?</td><td class="pr-3">GET</td><td>/api/shortcut/status</td></tr>
                </tbody>
            </table>
            <ol class="list-decimal space-y-1 pl-5">
                <li>{{ __('Shortcuts → + → "Get Contents of URL": paste the base URL + path, set the method, and add a header') }} <code class="font-mono text-gray-200">X-Api-Token</code> {{ __('with your token.') }}</li>
                <li>{{ __('Add "Get Dictionary Value" for key') }} <code class="font-mono text-gray-200">message</code> {{ __('from Contents of URL.') }}</li>
                <li>{{ __('Add "Show Notification" (or "Speak Text") with the Dictionary Value.') }}</li>
                <li>{{ __('Name it "Sleep PC" — the name is the Siri phrase; add it to the Home Screen, Action button or Back Tap.') }}</li>
            </ol>
        </div>
    </details>
</section>
