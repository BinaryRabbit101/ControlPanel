<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Profile → "API token": mint/rotate/revoke the signed-in user's token. The
 * plaintext rides back in the session flash exactly once, for the page that
 * renders it, and is never stored (see User::mintApiToken).
 */
class ApiTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $plain = $request->user()->mintApiToken();

        return Redirect::route('profile.edit')
            ->with('status', 'api-token-minted')
            ->with('api_token', $plain);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->revokeApiToken();

        return Redirect::route('profile.edit')->with('status', 'api-token-revoked');
    }
}
