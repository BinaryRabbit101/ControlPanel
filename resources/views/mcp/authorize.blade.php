{{--
    Passport's consent screen for the Household connector (Passport::authorizationView
    in AppServiceProvider). Published from laravel/mcp and restyled to the estate
    sign-in look (HUB STANDARDS §1): same mark, palette and card as the login page,
    plain words, Approve / Cancel. Buttons carry dusk="approve" / dusk="cancel".
--}}
@php
    $clientName = trim((string) ($client->name ?? ''));
    $who = $clientName === '' || str_contains(strtolower($clientName), 'claude') ? 'Claude' : $clientName;
    $redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? null);
    $redirectHost = is_string($redirectUri) ? parse_url($redirectUri, PHP_URL_HOST) : null;
@endphp
<x-guest-layout tagline="Connect Claude to the household.">
    <h1 class="text-lg font-semibold leading-snug" style="color: var(--mist)">
        {{ $who }} wants to use ControlPanel for you
    </h1>

    <p class="mt-2 text-sm" style="color: var(--muted)">If you approve, {{ $who }} can:</p>

    <ul class="mt-3 space-y-2 text-sm" style="color: var(--mist)">
        <li class="flex gap-2"><span aria-hidden="true" style="color: var(--accent)">•</span><span>Wake, sleep and check Gemini and Franklin</span></li>
        <li class="flex gap-2"><span aria-hidden="true" style="color: var(--accent)">•</span><span>Start, resume and end Claude sessions on Gemini</span></li>
        <li class="flex gap-2"><span aria-hidden="true" style="color: var(--accent)">•</span><span>Read and add to the shopping list</span></li>
        <li class="flex gap-2"><span aria-hidden="true" style="color: var(--accent)">•</span><span>Set reminders and send phone notifications</span></li>
    </ul>

    <p class="mt-4 text-sm break-words" style="color: var(--muted)">
        Signed in as <span style="color: var(--mist)">{{ $user->email }}</span>
    </p>

    @if ($redirectHost)
        <p class="mt-1 text-xs break-all" style="color: var(--muted)">Afterwards you go back to {{ $redirectHost }}.</p>
    @endif

    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="sm:flex-1">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" dusk="cancel"
                    class="w-full min-h-[44px] px-4 py-2 rounded-md text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2"
                    style="background-color: var(--raised); color: var(--mist); border: 1px solid var(--line)">
                Cancel
            </button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="sm:flex-1" data-approve-form>
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" dusk="approve"
                    class="w-full min-h-[44px] px-4 py-2 rounded-md text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-60"
                    style="background-color: var(--accent); color: var(--ink); border: 1px solid transparent">
                Approve
            </button>
        </form>
    </div>

    <script>
        // One tap is enough: stop a second tap from sending the approval twice.
        document.querySelector('[data-approve-form]').addEventListener('submit', function (event) {
            var button = event.target.querySelector('button[type="submit"]');
            setTimeout(function () { button.disabled = true; button.textContent = 'Approving…'; }, 0);
        });
    </script>
</x-guest-layout>
