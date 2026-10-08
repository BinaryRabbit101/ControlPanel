<?php

namespace App\Support\ControlPanel;

/**
 * The HUB map is its own static site (websites\HubSite, port 120). Visitors
 * on the tailnet get its tailnet address; everyone else the LAN one.
 */
class HubSite
{
    public static function url(?string $host): string
    {
        $key = str_contains(strtolower((string) $host), 'ts.net') ? 'tailnet' : 'lan';

        return (string) config("control_panel.hub_url.{$key}");
    }
}
