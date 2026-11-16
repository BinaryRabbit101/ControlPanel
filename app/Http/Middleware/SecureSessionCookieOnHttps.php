<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the session and XSRF cookies Secure when the request is https (the
 * Household connector's public host, or the tailnet address), and leaves
 * them as configured on the plain-http LAN address, where a Secure cookie
 * would never come back. Runs before StartSession.
 */
class SecureSessionCookieOnHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        // Per request: https (public host, tailnet) → Secure; the http LAN
        // address must not get a Secure cookie it would never send back.
        config(['session.secure' => $request->isSecure()]);

        return $next($request);
    }
}
