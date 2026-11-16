<?php

use App\Http\Middleware\DenyFraming;
use App\Http\Middleware\RequireApiToken;
use App\Http\Middleware\RequireLocalNetwork;
use App\Http\Middleware\SecureSessionCookieOnHttps;
use App\Support\ControlPanel\PublicHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // No page may be framed by another site (clickjacking).
        $middleware->append(DenyFraming::class);

        // Secure session/XSRF cookies on https (public host, tailnet), not on the http LAN.
        $middleware->web(prepend: [SecureSessionCookieOnHttps::class]);

        // A signed-in visitor on the connector's public host goes to /login (the
        // "you're signed in" page) instead of the LAN-only dashboard.
        $middleware->redirectUsersTo(fn (Request $request) => PublicHost::is($request) ? '/login' : route('dashboard'));

        $middleware->alias([
            'lan' => RequireLocalNetwork::class,
            'api-token' => RequireApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON errors (incl. 422 validation) to any request that asks for
        // JSON — the dashboard's action buttons are AJAX and expect JSON back.
        // The MCP endpoint and OAuth client registration always answer JSON
        // (a 401 there must carry WWW-Authenticate, not redirect to /login).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp', 'oauth/register') || $request->expectsJson(),
        );
    })->create();
