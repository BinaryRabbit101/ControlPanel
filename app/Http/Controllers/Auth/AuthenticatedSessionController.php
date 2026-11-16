<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\ControlPanel\PublicHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view. Already signed in: the dashboard on the LAN; on
     * the Household connector's public host (where the dashboard is a 403) a
     * plain "you're signed in, go back to Claude" page.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return PublicHost::is($request)
                ? view('auth.signed-in')
                : redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request. Back to where the sign-in
     * was asked for (Claude's authorize page) if anywhere; otherwise the
     * dashboard on the LAN, or the "you're signed in" page on the public host.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(PublicHost::is($request) ? '/login' : route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session. The LAN goes to `/` as always; the
     * public host goes back to the sign-in form (its `/` is the 403 dashboard).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $public = PublicHost::is($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return $public
            ? redirect('/login')->with('status', "You're signed out.")
            : redirect('/');
    }
}
