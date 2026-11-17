<?php

namespace App\Support\ControlPanel;

use Illuminate\Http\Request;

/**
 * Did this request come in through a Cloudflare Tunnel? No tunnel should reach
 * ControlPanel (the Claude connector lives in Butler), so this is a backstop:
 * Cloudflare always adds Cf-Ray / Cf-Connecting-Ip on the way in and a client
 * can't strip them, so either header means "public" whatever IP or host the
 * request claims. Loopback without Cloudflare headers (Tailscale Serve) is not
 * public.
 */
final class PublicHost
{
    public static function is(Request $request): bool
    {
        return $request->headers->has('Cf-Ray') || $request->headers->has('Cf-Connecting-Ip');
    }
}
