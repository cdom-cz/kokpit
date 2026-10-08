<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\EstimateHours;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The input rules that CreateProject and UpdateProject share, so a project is
 * validated the same way whichever Action writes it (D-15, PR-03). Every error
 * is a ValidationException keyed by the data key; the Admin form maps the key to
 * its state path. No Filament dependency.
 */
final class ProjectInput
{
    /**
     * Parses an amount typed in major units in the client's currency. Nothing is
     * rounded: more decimals than the currency allows, a grouping character, a
     * negative sign or an amount beyond the integer range is a field error. An
     * empty text is null (no amount).
     */
    public static function money(?string $text, Client $client, string $field): ?Money
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        try {
            $money = Money::fromMajor($text, $client->currency);
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages([$field => __('kokpit.projects.errors.amount_invalid')]);
        }

        if ($money->minor < 0) {
            throw ValidationException::withMessages([$field => __('kokpit.projects.errors.amount_invalid')]);
        }

        return $money;
    }

    /**
     * Converts the estimate typed in hours to whole seconds. An empty text is
     * null; an invalid one is a field error on `estimate_hours`.
     */
    public static function estimateSeconds(?string $hours): ?int
    {
        if ($hours === null || trim($hours) === '') {
            return null;
        }

        try {
            return EstimateHours::toSeconds($hours);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['estimate_hours' => __('kokpit.projects.errors.estimate_invalid')]);
        }
    }

    /**
     * A fixed-price project must carry a price; the CHECK constraint is the
     * database guarantee, this is the field error the form shows.
     */
    public static function requireFixedPrice(string $billingType, ?Money $fixedPrice): void
    {
        if ($billingType === 'fixed_price' && $fixedPrice === null) {
            throw ValidationException::withMessages(['fixed_price' => __('kokpit.projects.errors.fixed_price_required')]);
        }
    }

    /**
     * Translates the unique index violation of the project key into a field
     * error on `key` (D-14); any other unique violation is rethrown unchanged.
     * Call it outside the transaction, so the failed insert is already rolled back.
     */
    public static function translateKeyViolation(UniqueConstraintViolationException $e): never
    {
        if (str_contains($e->getMessage(), 'projects_key_unique')) {
            throw ValidationException::withMessages(['key' => __('kokpit.projects.errors.key_taken')]);
        }

        throw $e;
    }
}
