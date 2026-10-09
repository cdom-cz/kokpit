<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\QueryException;
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

it('refuses to create a project for an archived client and stores nothing', function (): void {
    $this->actingAs(Canary::admin());
    $this->client->delete();

    $errors = projectCreationErrors(fn () => createProjectFor($this->client, projectData()));

    expect($errors)->toHaveKey('client_id')
        ->and($errors['client_id'][0])->toBe(__('kokpit.projects.errors.client_archived'))
        ->and(Project::query()->withTrashed()->count())->toBe(0)
        ->and(ProjectBilling::query()->count())->toBe(0);
});

it('turns a malformed or over-precise amount into a field error on that field and stores nothing', function (string $field, string $amount): void {
    $this->actingAs(Canary::admin());

    $data = $field === 'fixed_price'
        ? projectData(['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => $amount])
        : projectData([$field => $amount]);

    $errors = projectCreationErrors(fn () => createProjectFor($this->client, $data));

    expect(array_keys($errors))->toBe([$field])
        ->and($errors[$field][0])->toBe(__('kokpit.projects.errors.amount_invalid'))
        ->and(Project::query()->count())->toBe(0)
        ->and(ProjectBilling::query()->count())->toBe(0);
})->with(function (): Generator {
    foreach (['hourly_rate', 'fixed_price'] as $field) {
        yield "{$field} with three decimals in CZK" => [$field, '1,234'];
        yield "{$field} with a grouping space" => [$field, '1 000'];
        yield "{$field} with a negative sign" => [$field, '-5'];
        yield "{$field} with a thousands comma and point" => [$field, '1,000.50'];
        yield "{$field} beyond the integer range" => [$field, str_repeat('9', 30)];
    }
});

it('rounds nothing: an amount with the allowed decimals is stored exactly', function (): void {
    $this->actingAs(Canary::admin());

    $project = createProjectFor($this->client, projectData(['hourly_rate' => '0,05']));

    expect(ProjectBilling::query()->where('project_id', $project->id)->sole()->hourly_rate_minor)->toBe(5);
});

it('requires a fixed price for a fixed-price project as a field error', function (): void {
    $this->actingAs(Canary::admin());

    $errors = projectCreationErrors(fn () => createProjectFor($this->client, projectData([
        'billing_type' => 'fixed_price',
        'hourly_rate' => null,
        'fixed_price' => '',
    ])));

    expect(array_keys($errors))->toBe(['fixed_price'])
        ->and(Project::query()->count())->toBe(0);
});

it('rejects the key of an archived project and creates no second row', function (): void {
    $this->actingAs(Canary::admin());
    $key = Canary::projectKey();
    $archived = app(PartnerContext::class)->runAsSystem(fn (): Project => Project::factory()->create(['client_id' => $this->client->id, 'key' => $key]));
    app(PartnerContext::class)->runAsSystem(fn () => $archived->delete());

    $errors = projectCreationErrors(fn () => createProjectFor($this->client, projectData(['key' => strtolower($key)])));

    expect(array_keys($errors))->toBe(['key'])
        ->and($errors['key'][0])->toBe(__('kokpit.projects.errors.key_taken'))
        ->and(Project::query()->withTrashed()->where('key', $key)->count())->toBe(1)
        ->and(ProjectBilling::query()->count())->toBe(0);
});

it('ends a key taken between the form check and the save as a key field error, with exactly one project left', function (): void {
    $this->actingAs(Canary::admin());
    $key = Canary::projectKey();

    // The form check passed (the key was free); another request wins the race and inserts it first.
    expect(Project::query()->withTrashed()->where('key', $key)->exists())->toBeFalse();
    app(PartnerContext::class)->runAsSystem(fn () => Project::factory()->create(['client_id' => $this->client->id, 'key' => $key]));

    $errors = projectCreationErrors(fn () => createProjectFor($this->client, projectData(['key' => $key])));

    expect(array_keys($errors))->toBe(['key'])
        ->and($errors['key'][0])->toBe(__('kokpit.projects.errors.key_taken'))
        ->and(Project::query()->withTrashed()->where('key', $key)->count())->toBe(1);

    // The failed attempt leaves the connection usable.
    $other = createProjectFor($this->client, projectData());
    expect($other->exists)->toBeTrue();
});

it('lets any other database error through instead of reporting it as a taken key', function (): void {
    $this->actingAs(Canary::admin());

    // A project name above the column length is a database error that is not the key index.
    expect(fn () => createProjectFor($this->client, projectData(['name' => str_repeat('x', 300)])))
        ->toThrow(QueryException::class);
});

it('leaves no project row behind when the billing insert fails', function (): void {
    $this->actingAs(Canary::admin());

    ProjectBilling::creating(static function (): never {
        throw new RuntimeException('billing insert failed');
    });

    expect(fn () => createProjectFor($this->client, projectData()))->toThrow(RuntimeException::class, 'billing insert failed')
        ->and(Project::query()->withTrashed()->count())->toBe(0);
});
