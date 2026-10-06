<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\Projects\ArchiveProjectController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\SourceSnapshotController;
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

// Projects and immutable source snapshots (Phase 07). Owner-only: other
// users' projects answer 404 (ProjectPolicy). No DELETE routes: projects are
// archived, and snapshots never change.
Route::middleware('auth:sanctum')->prefix('projects')->name('projects.')->group(function (): void {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])
        ->middleware('throttle:project-create')
        ->name('store');
    Route::get('{project}', [ProjectController::class, 'show'])->whereUlid('project')->name('show');
    Route::patch('{project}', [ProjectController::class, 'update'])
        ->whereUlid('project')
        ->middleware('throttle:project-update')
        ->name('update');
    Route::post('{project}/archive', ArchiveProjectController::class)
        ->whereUlid('project')
        ->middleware('throttle:project-update')
        ->name('archive');

    Route::get('{project}/source-snapshots', [SourceSnapshotController::class, 'index'])
        ->whereUlid('project')
        ->name('source-snapshots.index');
    Route::post('{project}/source-snapshots', [SourceSnapshotController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:source-upload')
        ->name('source-snapshots.store');
    Route::get('{project}/source-snapshots/{sourceSnapshot}', [SourceSnapshotController::class, 'show'])
        ->whereUlid(['project', 'sourceSnapshot'])
        ->scopeBindings()
        ->name('source-snapshots.show');
});
