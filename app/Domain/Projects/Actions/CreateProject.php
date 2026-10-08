<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a project and its Admin-only billing row in one transaction (D-05).
 *
 * The Action is the only place that knows the rules of project creation, so the
 * Admin form is a thin adapter around it: the key is stored upper case, the
 * client is set through the relation (never mass assigned), and the money is
 * built with Money::fromMajor in the client's currency.
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
        return DB::transaction(function () use ($client, $data): Project {
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
                'hourly_rate' => $this->money($data['hourly_rate'] ?? null, $client),
                'fixed_price' => $this->money($data['fixed_price'] ?? null, $client),
                'internal_note' => $data['internal_note'] ?? null,
            ]);

            return $project;
        });
    }

    private function money(?string $text, Client $client): ?Money
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return Money::fromMajor($text, $client->currency);
    }
}
