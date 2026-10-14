<?php

declare(strict_types=1);

use App\Domain\Identity\ApiAbility;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Support\Facades\Route;

/*
 * The REST API, served under /api/v1 (see withRouting in bootstrap/app.php).
 *
 * Every route needs a bearer token of an active Admin (auth:sanctum, see
 * ApiServiceProvider and config/sanctum.php) carrying the ability of its group, and
 * is rate limited per token (throttle:api-read or throttle:api-write). A route added
 * to a group inherits all three, so there is no route without a credential check, an
 * ability check and a limit.
 */

// Reads: GET routes, ability time:read.
Route::middleware(['auth:sanctum', 'abilities:'.ApiAbility::TimeRead->value, 'throttle:api-read'])->group(function (): void {
    Route::get('time-entries', [TimeEntryController::class, 'index'])
        ->name('api.v1.time-entries.index');
    Route::get('time-entries/{timeEntry}', [TimeEntryController::class, 'show'])
        ->whereUuid('timeEntry')
        ->name('api.v1.time-entries.show');

    // Lookups (D-12): read-only; the reference is checked by the resolver, so a malformed one answers the
    // same Czech 404 as an unknown one instead of a router 404.
    Route::get('clients', [ClientController::class, 'index'])
        ->name('api.v1.clients.index');
    Route::get('clients/{client}', [ClientController::class, 'show'])
        ->name('api.v1.clients.show');
    Route::get('projects', [ProjectController::class, 'index'])
        ->name('api.v1.projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])
        ->name('api.v1.projects.show');
    Route::get('tasks', [TaskController::class, 'index'])
        ->name('api.v1.tasks.index');
    Route::get('tasks/{task}', [TaskController::class, 'show'])
        ->name('api.v1.tasks.show');
});

// Writes: POST, PATCH and DELETE routes, ability time:write.
Route::middleware(['auth:sanctum', 'abilities:'.ApiAbility::TimeWrite->value, 'throttle:api-write'])->group(function (): void {
    // Create routes honour an optional Idempotency-Key (D-06); it runs after the group's credential check,
    // ability check and limit, and only here: PATCH, DELETE and GET never carry it.
    Route::post('time-entries', [TimeEntryController::class, 'store'])
        ->middleware(EnsureIdempotency::class)
        ->name('api.v1.time-entries.store');
    Route::patch('time-entries/{timeEntry}', [TimeEntryController::class, 'update'])
        ->whereUuid('timeEntry')
        ->name('api.v1.time-entries.update');
    Route::delete('time-entries/{timeEntry}', [TimeEntryController::class, 'destroy'])
        ->whereUuid('timeEntry')
        ->name('api.v1.time-entries.destroy');
});
