<?php

use App\Http\Controllers\ControlPanel\ActionController;
use App\Http\Controllers\ControlPanel\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'lan'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/actions/sessions', [ActionController::class, 'sessions'])->name('actions.sessions');
    Route::post('/actions/{action}', [ActionController::class, 'run'])->name('actions.run');
    Route::get('/actions/logs/{log}/status', [ActionController::class, 'status'])->name('actions.status');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
