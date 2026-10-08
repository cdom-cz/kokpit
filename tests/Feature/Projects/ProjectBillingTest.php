<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * Project creation and the Admin-only billing row (PR-01, PR-02, PR-03, D-05,
 * D-15). Every name, key and amount is fictional.
 */

/**
 * The data of a minimal valid creation, with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function projectData(array $overrides = []): array
{
    return [
        'name' => 'Example project '.Str::lower(Str::random(6)),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '850,50',
        ...$overrides,
    ];
}

/**
 * Runs the Action as the Admin context would (a system run reads archived
 * clients and writes both tables).
 *
 * @param  array<string, mixed>  $data
 */
function createProjectFor(Client $client, array $data): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle($client, $data));
}

/**
 * The messages of the validation error of a callable, keyed by field.
 *
 * @return array<string, list<string>>
 */
function projectCreationErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

beforeEach(function (): void {
    $this->client = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
});

it('stores a project and its hourly billing row with the money in the client currency', function (): void {
    $this->actingAs(Canary::admin());

    $project = createProjectFor($this->client, projectData(['hourly_rate' => '850,50', 'internal_note' => 'Only for the owner']));

    $billing = ProjectBilling::query()->where('project_id', $project->id)->sole();

    expect(Project::query()->count())->toBe(1)
        ->and(ProjectBilling::query()->count())->toBe(1)
        ->and($billing->billing_type)->toBe(BillingType::Hourly)
        ->and($billing->hourly_rate_minor)->toBe(85050)
        ->and($billing->hourly_rate_currency)->toBe('CZK')
        ->and($billing->hourly_rate?->equals(Money::ofMinor(85050, 'CZK')))->toBeTrue()
        ->and($billing->fixed_price)->toBeNull()
        ->and($billing->internal_note)->toBe('Only for the owner')
        ->and($project->client_id)->toBe($this->client->id);

    // The Partner-readable row carries no money at all.
    $columns = array_keys((array) DB::table('projects')->where('id', $project->id)->sole());
    expect(array_filter($columns, static fn (string $column): bool => preg_match('/rate|price|estimate|billing|note/i', $column) === 1))->toBe([]);
});

it('stores a fixed-price project with its price and no hourly rate', function (): void {
    $this->actingAs(Canary::admin());

    $project = createProjectFor($this->client, projectData([
        'billing_type' => 'fixed_price',
        'hourly_rate' => null,
        'fixed_price' => '120000',
    ]));

    $billing = ProjectBilling::query()->where('project_id', $project->id)->sole();

    expect($billing->billing_type)->toBe(BillingType::FixedPrice)
        ->and($billing->fixed_price_minor)->toBe(120000 * 100)
        ->and($billing->fixed_price_currency)->toBe('CZK')
        ->and($billing->hourly_rate)->toBeNull()
        ->and($billing->estimate_seconds)->toBeNull();
});

it('stores the key upper case and syncs project tags', function (): void {
    $this->actingAs(Canary::admin());

    $project = createProjectFor($this->client, projectData(['key' => 'abcd', 'tags' => ['web', 'internal']]));

    expect($project->key)->toBe('ABCD')
        ->and($project->tags->pluck('name')->sort()->values()->all())->toBe(['internal', 'web'])
        ->and($project->tags->pluck('type')->unique()->all())->toBe(['project']);
});

it('shows a Partner no billing row and a null billing relation on the own visible project', function (): void {
    $project = createProjectFor($this->client, projectData(['client_visible' => true, 'internal_note' => Canary::canary('note')]));

    $this->actingAs(Canary::partnerFor($this->client->id));

    $visible = Project::query()->whereKey($project->id)->sole();

    expect(ProjectBilling::query()->count())->toBe(0)
        ->and($visible->billing)->toBeNull()
        ->and($visible->load('billing')->billing)->toBeNull();

    // The Admin reads the same row.
    $this->actingAs(Canary::admin());

    expect(ProjectBilling::query()->count())->toBe(1)
        ->and(Project::query()->whereKey($project->id)->sole()->billing)->not->toBeNull();
});
