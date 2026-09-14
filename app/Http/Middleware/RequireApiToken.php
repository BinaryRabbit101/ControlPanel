<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-style auth for the Shortcut endpoints. An iOS Shortcut has no
 * session cookie or CSRF token, so the account's API token (Profile → "API
 * token") is the whole auth story — sent as `X-Api-Token` or
 * `Authorization: Bearer` (the estate standard; no per-site aliases).
 * Whitespace is trimmed because a value pasted into a Shortcuts text field
 * can carry a trailing newline. The matching user becomes the request's user,
 * so every action is attributed to whoever minted the token.
 *
 * The 401 body carries `message` (like every success body) so a Shortcut's
 * "Get Dictionary Value → message" step shows a reason instead of nothing.
 */
class RequireApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = trim((string) ($request->header('X-Api-Token') ?? $request->bearerToken() ?? ''));
        $user = User::findByApiToken($given);

        if ($user === null) {
            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => $given === ''
                    ? 'No API token was sent. Add an X-Api-Token header with the token from Profile.'
                    : 'That API token is not valid. Copy the current one from Profile → API token.',
            ], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
