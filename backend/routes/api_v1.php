<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Middleware\RequireSession;
use Illuminate\Support\Facades\Route;

// Routes under /api/v1. Every route here is in the `api` middleware group
// (Sanctum stateful sessions for first-party requests, `throttle:api`).

Route::get('health', HealthController::class)->name('health');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware([RequireSession::class, 'throttle:register'])
        ->name('register');

    Route::post('login', LoginController::class)
        ->middleware([RequireSession::class, 'throttle:login'])
        ->name('login');

    Route::post('logout', LogoutController::class)
        ->middleware(['auth:sanctum', RequireSession::class])
        ->name('logout');

    // Ends the session: the client signs in again with the new password.
    Route::patch('password', PasswordController::class)
        ->middleware(['auth:sanctum', RequireSession::class, 'throttle:password-change'])
        ->name('password');
});

Route::get('me', MeController::class)
    ->middleware('auth:sanctum')
    ->name('me');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])
        ->middleware('throttle:profile-update')
        ->name('profile.update');
});
