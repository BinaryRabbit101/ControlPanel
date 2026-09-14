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
 * `Authorization: Bearer`. The matching user becomes the request's user, so
 * every action is attributed to whoever minted the token.
 */
class RequireApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) ($request->header('X-Api-Token') ?? $request->bearerToken() ?? '');
        $user = User::findByApiToken($given);

        if ($user === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
