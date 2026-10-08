<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Creates a project and its Admin-only billing row in one transaction (D-05).
 *
 * The Action is the only place that knows the rules of project creation, so the
 * Admin form is a thin adapter around it: the key is stored upper case, the
 * client is set through the relation (never mass assigned), and the money is
 * built with Money::fromMajor in the client's currency.
 *
 * Guards: a project is never created for an archived client (D-11), a malformed
 * amount is a field error on that field and never rounded (D-15), and a taken
 * key is a field error on `key` raised from the database unique index, which
 * also covers a key taken between the form check and the save (D-14).
 * Every error is keyed by the data key; the Admin form maps it to its state path.
 *
 * `estimate_hours` is accepted in the data shape but not converted yet.
 *
 * @phpstan-type ProjectData array{
 *     name: string,
 *     key: string,
 *     description?: string|null,
 *     status?: string|null,
 *     priority?: string|null,
 *     start_date?: string|null,
 *     end_date?: string|null,
 *     client_visible?: bool|null,
 *     tags?: list<string>|null,
 *     billing_type: string,
 *     hourly_rate?: string|null,
 *     fixed_price?: string|null,
 *     estimate_hours?: string|null,
 *     internal_note?: string|null,
 * }
 */
final class CreateProject
{
    /**
     * @param  ProjectData  $data
     */
    public function handle(Client $client, array $data): Project
    {
        if ($client->trashed()) {
            throw ValidationException::withMessages(['client_id' => __('kokpit.projects.errors.client_archived')]);
        }

        $hourlyRate = $this->money($data['hourly_rate'] ?? null, $client, 'hourly_rate');
        $fixedPrice = $this->money($data['fixed_price'] ?? null, $client, 'fixed_price');

        if ($data['billing_type'] === BillingType::FixedPrice->value && $fixedPrice === null) {
            throw ValidationException::withMessages(['fixed_price' => __('kokpit.projects.errors.fixed_price_required')]);
        }

        // The transaction is caught from the outside: a unique violation inside it has
        // already rolled the project insert back (savepoint when nested) before it is translated.
        try {
            return DB::transaction(function () use ($client, $data, $hourlyRate, $fixedPrice): Project {
                $attributes = [
                    'name' => $data['name'],
                    'key' => Str::upper($data['key']),
                    'description' => $data['description'] ?? null,
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                    'client_visible' => $data['client_visible'] ?? false,
                ];

                // Status and priority are NOT NULL with database defaults: omit them when not given.
                foreach (['status', 'priority'] as $column) {
                    if (($data[$column] ?? null) !== null) {
                        $attributes[$column] = $data[$column];
                    }
                }

                $project = $client->projects()->create($attributes);

                $tags = $data['tags'] ?? [];

                if ($tags !== []) {
                    $project->syncTagsWithType($tags, TagType::Project->value);
                }

                $project->billing()->create([
                    'billing_type' => BillingType::from($data['billing_type']),
                    'hourly_rate' => $hourlyRate,
                    'fixed_price' => $fixedPrice,
                    'internal_note' => $data['internal_note'] ?? null,
                ]);

                return $project;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'projects_key_unique')) {
                throw ValidationException::withMessages(['key' => __('kokpit.projects.errors.key_taken')]);
            }

            throw $e;
        }
    }

    /**
     * Parses an amount typed in major units in the client's currency. Nothing is
     * rounded: more decimals than the currency allows, a grouping character, a
     * negative sign or an amount beyond the integer range is a field error.
     */
    private function money(?string $text, Client $client, string $field): ?Money
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
}
