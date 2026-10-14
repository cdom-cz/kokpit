<?php

declare(strict_types=1);

use App\Domain\Identity\ApiAbility;
use App\Http\Controllers\Api\V1\TimeEntryController;
use Illuminate\Support\Facades\Route;

/*
 * The REST API, served under /api/v1 (see withRouting in bootstrap/app.php).
 *
 * Every route needs a bearer token of an active Admin (auth:sanctum, see
 * ApiServiceProvider and config/sanctum.php) carrying the ability of its group.
 * A route added to a group inherits all of that, so there is no route without a
 * credential check and an ability check.
 */

// Reads: GET routes, ability time:read.
Route::middleware(['auth:sanctum', 'abilities:'.ApiAbility::TimeRead->value])->group(function (): void {
    Route::get('time-entries/{timeEntry}', [TimeEntryController::class, 'show'])
        ->whereUuid('timeEntry')
        ->name('api.v1.time-entries.show');
});

// Writes: POST, PATCH and DELETE routes, ability time:write.
Route::middleware(['auth:sanctum', 'abilities:'.ApiAbility::TimeWrite->value])->group(function (): void {
    // Added by the write plans of the phase.
});
