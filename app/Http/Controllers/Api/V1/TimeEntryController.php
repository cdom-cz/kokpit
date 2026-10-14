<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\TimeTracking\Models\TimeEntry;
use App\Http\Resources\Api\V1\TimeEntryResource;

/**
 * Time entries of the Admin over the REST API.
 *
 * Route parameters are plain strings, not implicit model bindings: every miss
 * answers the same Czech 404 message without a model class name, and nothing
 * touches the database while the route is only being described.
 */
final class TimeEntryController
{
    public function show(string $timeEntry): TimeEntryResource
    {
        $entry = TimeEntry::query()->with(['project', 'task'])->whereKey($timeEntry)->first();

        if ($entry === null) {
            abort(404, __('api.errors.not_found'));
        }

        return new TimeEntryResource($entry);
    }
}
