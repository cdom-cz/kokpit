<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
 * `estimate_hours` is converted to whole seconds with EstimateHours; an empty
 * value stores null and an invalid one is a field error on `estimate_hours`.
 * The input rules are shared with UpdateProject through ProjectInput.
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

        $hourlyRate = ProjectInput::money($data['hourly_rate'] ?? null, $client, 'hourly_rate');
        $fixedPrice = ProjectInput::money($data['fixed_price'] ?? null, $client, 'fixed_price');
        $estimateSeconds = ProjectInput::estimateSeconds($data['estimate_hours'] ?? null);

        ProjectInput::requireFixedPrice($data['billing_type'], $fixedPrice);

        // The transaction is caught from the outside: a unique violation inside it has
        // already rolled the project insert back (savepoint when nested) before it is translated.
        try {
            return DB::transaction(function () use ($client, $data, $hourlyRate, $fixedPrice, $estimateSeconds): Project {
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
                    'estimate_seconds' => $estimateSeconds,
                    'internal_note' => $data['internal_note'] ?? null,
                ]);

                // Status and priority come from database defaults when not given: load them, so the
                // returned model is complete and a later update does not log them as changed from null.
                return $project->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            ProjectInput::translateKeyViolation($e);
        }
    }
}
