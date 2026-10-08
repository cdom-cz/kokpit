<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Updates a project and its Admin-only billing row in one transaction (PR-01,
 * PR-02, PR-03).
 *
 * Takes the data shape of CreateProject plus an optional `client_id`, and shares
 * the money, estimate, key and fixed-price rules with it through ProjectInput.
 * A key that is absent from the data leaves the stored value unchanged; a key
 * that is present replaces it, an empty amount or estimate clears it.
 *
 * - The client of a project never changes after creation: a `client_id` that
 *   differs from the stored one is a field error and nothing is written
 *   (research item 3, T-04-13). The currency of the money is the client's.
 * - Status and priority can be set to any value in any order; there is no
 *   transition rule (D-16).
 * - A key taken by another project, also an archived one, is a field error on
 *   `key` raised from the unique index; keeping the own key succeeds (D-14).
 *
 * Every error is keyed by the data key; the Admin form maps it to its state path.
 *
 * @phpstan-type ProjectUpdateData array{
 *     client_id?: string|null,
 *     name?: string,
 *     key?: string,
 *     description?: string|null,
 *     status?: string|null,
 *     priority?: string|null,
 *     start_date?: string|null,
 *     end_date?: string|null,
 *     client_visible?: bool|null,
 *     tags?: list<string>|null,
 *     billing_type?: string,
 *     hourly_rate?: string|null,
 *     fixed_price?: string|null,
 *     estimate_hours?: string|null,
 *     internal_note?: string|null,
 * }
 */
final class UpdateProject
{
    /** Project columns copied from the data as given when the data names them. */
    private const array PLAIN_COLUMNS = ['name', 'description', 'start_date', 'end_date'];

    /**
     * @param  ProjectUpdateData  $data
     */
    public function handle(Project $project, array $data): Project
    {
        $clientId = $data['client_id'] ?? null;

        if ($clientId !== null && $clientId !== $project->client_id) {
            throw ValidationException::withMessages(['client_id' => __('kokpit.projects.errors.client_immutable')]);
        }

        $client = $project->client()->withTrashed()->firstOrFail();
        $billing = $project->billing;

        $hourlyRate = array_key_exists('hourly_rate', $data)
            ? ProjectInput::money($data['hourly_rate'], $client, 'hourly_rate')
            : $billing?->hourly_rate;
        $fixedPrice = array_key_exists('fixed_price', $data)
            ? ProjectInput::money($data['fixed_price'], $client, 'fixed_price')
            : $billing?->fixed_price;
        $estimateSeconds = array_key_exists('estimate_hours', $data)
            ? ProjectInput::estimateSeconds($data['estimate_hours'])
            : $billing?->estimate_seconds;
        $billingType = $data['billing_type'] ?? $billing?->billing_type->value ?? 'hourly';

        ProjectInput::requireFixedPrice($billingType, $fixedPrice);

        // The transaction is caught from the outside: a unique violation inside it has
        // already rolled the project update back (savepoint when nested) before it is translated.
        try {
            return DB::transaction(function () use ($project, $billing, $data, $hourlyRate, $fixedPrice, $estimateSeconds, $billingType): Project {
                $project->update($this->projectAttributes($data));

                if (array_key_exists('tags', $data) && $data['tags'] !== null) {
                    $project->syncTagsWithType($data['tags'], TagType::Project->value);
                }

                $this->saveBilling($project, $billing, $data, $billingType, $hourlyRate, $fixedPrice, $estimateSeconds);

                return $project;
            });
        } catch (UniqueConstraintViolationException $e) {
            ProjectInput::translateKeyViolation($e);
        }
    }

    /**
     * The project columns the data names. Status and priority are NOT NULL, so
     * a null value leaves them unchanged.
     *
     * @param  ProjectUpdateData  $data
     * @return array<string, mixed>
     */
    private function projectAttributes(array $data): array
    {
        $attributes = [];

        foreach (self::PLAIN_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $data[$column];
            }
        }

        if (isset($data['key'])) {
            $attributes['key'] = Str::upper($data['key']);
        }

        foreach (['status', 'priority'] as $column) {
            if (($data[$column] ?? null) !== null) {
                $attributes[$column] = $data[$column];
            }
        }

        if (isset($data['client_visible'])) {
            $attributes['client_visible'] = $data['client_visible'];
        }

        return $attributes;
    }

    /**
     * @param  ProjectUpdateData  $data
     */
    private function saveBilling(Project $project, ?ProjectBilling $billing, array $data, string $billingType, ?Money $hourlyRate, ?Money $fixedPrice, ?int $estimateSeconds): void
    {
        $attributes = [
            'billing_type' => $billingType,
            'hourly_rate' => $hourlyRate,
            'fixed_price' => $fixedPrice,
            'estimate_seconds' => $estimateSeconds,
        ];

        if (array_key_exists('internal_note', $data)) {
            $attributes['internal_note'] = $data['internal_note'];
        }

        if ($billing === null) {
            $project->billing()->create($attributes);

            return;
        }

        $billing->update($attributes);
    }
}
