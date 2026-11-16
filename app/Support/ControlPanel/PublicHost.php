<?php

namespace App\Support\ControlPanel;

use Illuminate\Http\Request;

/**
 * Did this request come in through the Household connector's Cloudflare
 * Tunnel? Cloudflare always adds Cf-Ray / Cf-Connecting-Ip on the way in and a
 * client can't strip them, so either header means "public" even when the
 * client forged X-Forwarded-Host. The raw Host header and the forwarded host
 * are also compared with the public host (any case, trailing dot ignored).
 * Loopback without Cloudflare headers (Tailscale Serve on :448) is not public.
 */
final class PublicHost
{
    public static function is(Request $request): bool
    {
        if ($request->headers->has('Cf-Ray') || $request->headers->has('Cf-Connecting-Ip')) {
            return true;
        }

        $public = self::normalise((string) config('control_panel.household.public_host', ''));
        if ($public === '') {
            return false;
        }

        $raw = self::normalise((string) preg_replace('/:\d+$/', '', (string) $request->server('HTTP_HOST', '')));

        return $raw === $public || self::normalise($request->getHost()) === $public;
    }

    private static function normalise(string $host): string
    {
        return rtrim(mb_strtolower(trim($host)), '.');
    }
}
