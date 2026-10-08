<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use Illuminate\Validation\ValidationException;

/**
 * The input rules CreateTask and UpdateTask share, so the two Actions answer
 * the same field error for the same bad value.
 */
final class TaskInput
{
    /**
     * @throws ValidationException a field error on `status` for a value outside the six statuses
     */
    public static function status(mixed $value): ?ProjectStatus
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? ProjectStatus::tryFrom($value) : null)
            ?? throw ValidationException::withMessages(['status' => __('kokpit.tasks.errors.status_invalid')]);
    }

    /**
     * @throws ValidationException a field error on `priority` for a value outside the four priorities
     */
    public static function priority(mixed $value): ?ProjectPriority
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? ProjectPriority::tryFrom($value) : null)
            ?? throw ValidationException::withMessages(['priority' => __('kokpit.tasks.errors.priority_invalid')]);
    }
}
