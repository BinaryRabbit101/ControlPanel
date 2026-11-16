<?php

use App\Http\Middleware\ThrottleMcpByIp;
use App\Mcp\Servers\HouseholdServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/*
|--------------------------------------------------------------------------
| Household connector (remote MCP server)
|--------------------------------------------------------------------------
| Claude's custom connector reaches these through the Cloudflare Tunnel at
| https://mcp.thecorgisquad.com, which forwards only /mcp, /oauth/*,
| /.well-known/*, /login, /logout, /build/*, /up and /favicon*. None of these
| carry the `lan` middleware; the dashboard, profile and Shortcut API do.
|
| - /.well-known/oauth-* : discovery metadata (laravel/mcp).
| - /oauth/register      : dynamic client registration, claude.ai / claude.com
|                          redirects only (config/mcp.php), throttled.
| - /oauth/authorize|token : Passport (sign-in with the ControlPanel account,
|                          then the consent screen, resources/views/mcp/authorize).
| - /mcp                 : the server itself, Passport bearer tokens only.
*/

Route::middleware('throttle:mcp-discovery')->group(function () {
    Mcp::oauthRoutes(registration: false);
});

Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:mcp-register')
    ->name('mcp.oauth.register');

// Per-IP cap first (before auth, so token-less floods are cut off too),
// then the bearer token, then the per-account cap.
Mcp::web('/mcp', HouseholdServer::class)
    ->middleware([ThrottleMcpByIp::class, 'auth:api', 'throttle:mcp']);
