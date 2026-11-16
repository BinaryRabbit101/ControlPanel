<?php

namespace App\Http\Middleware;

use App\Support\ControlPanel\PublicHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth on top of the LAN-only UFW rule: reject any request whose
 * client IP is not within the configured local CIDRs, and anything that came
 * through the Household connector's public tunnel (see PublicHost) whatever
 * IP or host it claims. 127.0.0.1 stays allowed: Tailscale Serve proxies the
 * tailnet address from loopback.
 */
class RequireLocalNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('control_panel.network.allowed_cidrs', []);

        if (PublicHost::is($request)) {
            abort(403, 'The control panel is only reachable from the local network.');
        }

        if (empty($allowed) || ! IpUtils::checkIp($request->ip(), $allowed)) {
            abort(403, 'The control panel is only reachable from the local network.');
        }

        return $next($request);
    }
}
