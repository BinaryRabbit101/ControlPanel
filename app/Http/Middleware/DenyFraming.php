<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No page of ControlPanel may be shown inside another site's frame
 * (clickjacking: the sign-in and consent screens face the internet through
 * the Household connector's tunnel). Nothing in the estate frames it.
 */
class DenyFraming
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');

        $csp = (array) $response->headers->all('content-security-policy');
        if (! collect($csp)->contains(fn ($v) => str_contains((string) $v, 'frame-ancestors'))) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'", false);
        }

        return $response;
    }
}
