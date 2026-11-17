<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sign-in: 10/min per IP and 20/hour per email, on top of
        // LoginRequest's own lockout.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login|'.$request->ip()),
            Limit::perHour(20)->by('login-email|'.mb_strtolower(trim((string) $request->input('email')))),
        ]);
    }
}
