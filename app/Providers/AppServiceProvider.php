<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The connector only uses the authorization-code grant. Set before
        // Passport's provider boots and loads its routes, so /oauth/device*
        // never exists on the public host.
        Passport::$deviceCodeGrantEnabled = false;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureHouseholdConnector();
    }

    /**
     * Passport + rate limits for the Household MCP connector (routes/ai.php).
     * These paths face the internet through the tunnel, so sign-in and client
     * registration are throttled per IP.
     */
    private function configureHouseholdConnector(): void
    {
        // The consent screen, restyled to the estate sign-in look.
        Passport::authorizationView('mcp.authorize');

        // A connector token can sleep PCs: a week, renewed by the refresh
        // token, rather than Passport's default year.
        Passport::tokensExpireIn(now()->addDays(7));
        Passport::refreshTokensExpireIn(now()->addDays(90));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login|'.$request->ip()),
            Limit::perHour(20)->by('login-email|'.mb_strtolower(trim((string) $request->input('email')))),
        ]);
        RateLimiter::for('mcp-register', fn (Request $request) => Limit::perMinute(10)->by('mcp-register|'.$request->ip()));
        RateLimiter::for('mcp-discovery', fn (Request $request) => Limit::perMinute(60)->by('mcp-discovery|'.$request->ip()));
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by('mcp|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
