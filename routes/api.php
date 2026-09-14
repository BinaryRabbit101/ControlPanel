<?php

use App\Http\Controllers\Api\ShortcutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shortcut API
|--------------------------------------------------------------------------
| Stateless, token-authed verbs for the iOS Shortcut that wakes/sleeps the
| Windows PC. No session, no CSRF — the account's API token (Profile → "API
| token", header X-Api-Token or Authorization: Bearer) is the whole auth
| story. Still LAN/tailnet-gated by the same middleware as the dashboard.
*/
Route::middleware(['lan', 'api-token'])->prefix('shortcut')->group(function () {
    Route::post('/wake', [ShortcutController::class, 'wake'])->name('api.shortcut.wake');
    Route::post('/sleep', [ShortcutController::class, 'sleep'])->name('api.shortcut.sleep');
    Route::get('/status', [ShortcutController::class, 'status'])->name('api.shortcut.status');
});
