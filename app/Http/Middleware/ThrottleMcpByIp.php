<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * A per-IP cap on /mcp that runs BEFORE auth:api, so floods without a token
 * are cut off too. Deliberately not Laravel's ThrottleRequests: that class is
 * in the middleware priority list and would be sorted after authentication.
 */
class ThrottleMcpByIp
{
    public const PER_MINUTE = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'mcp-ip|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32000, 'message' => 'Too many requests. Try again in a minute.'],
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
