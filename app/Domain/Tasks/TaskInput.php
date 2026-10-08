<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Text\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

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

    /**
     * The description as stored: cleaned by RichText, null when no text is left (D-10).
     *
     * @throws ValidationException a field error on `description` for text over the length limit
     */
    public static function description(?string $html): ?string
    {
        try {
            return RichText::clean($html);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['description' => __('kokpit.tasks.errors.description_too_long')]);
        }
    }

    /**
     * A real calendar day as `Y-m-d`, null for an empty value.
     *
     * @throws ValidationException a field error on `$field` for anything that is not a calendar day
     */
    public static function day(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            try {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            } catch (Throwable) {
                $day = null;
            }

            if ($day !== null && $day->format('Y-m-d') === $value) {
                return $value;
            }
        }

        throw ValidationException::withMessages([$field => __('kokpit.tasks.errors.date_invalid')]);
    }

    /**
     * The due date may equal the start date but not precede it. Both are `Y-m-d`
     * strings or null; a missing bound never fails.
     *
     * @throws ValidationException a field error on `due_date`
     */
    public static function assertDatesInOrder(?string $start, ?string $due): void
    {
        if ($start !== null && $due !== null && $due < $start) {
            throw ValidationException::withMessages(['due_date' => __('kokpit.tasks.errors.dates_order')]);
        }
    }

    /**
     * The tag names of the input as a clean list: trimmed, without empty names
     * and duplicates. Null means the input names no tags at all (leave them be).
     *
     * @return list<string>|null
     */
    public static function tags(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $names = [];

        foreach ($value as $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return array_values(array_unique($names));
    }
}
