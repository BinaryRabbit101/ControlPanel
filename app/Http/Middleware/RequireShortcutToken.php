<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-style auth for the iOS Shortcut endpoints. An iOS Shortcut has no
 * session cookie or CSRF token, so the shared secret in CP_SHORTCUT_TOKEN is
 * the whole auth story — sent as `X-Shortcut-Token` or `Authorization: Bearer`.
 * An empty configured token disables the endpoints outright.
 */
class RequireShortcutToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('control_panel.shortcut.token', '');
        $given = (string) ($request->header('X-Shortcut-Token') ?? $request->bearerToken() ?? '');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
