{{-- Shown on the Household connector's public host after signing in with no
     page to return to (the dashboard is LAN-only and would only say 403). --}}
<x-guest-layout tagline="Connect Claude to the household.">
    <h1 class="text-lg font-semibold" style="color: var(--mist)" dusk="signed-in">You're signed in.</h1>
    <p class="mt-2 text-sm" style="color: var(--muted)">Go back to Claude to finish.</p>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" dusk="sign-out"
                class="w-full min-h-[44px] px-4 py-2 rounded-md text-sm font-semibold"
                style="background-color: var(--raised); color: var(--mist); border: 1px solid var(--line)">
            Sign out
        </button>
    </form>
</x-guest-layout>
