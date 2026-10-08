<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * The project write path: estimate conversion, UpdateProject, the selectable
 * scope and ProjectBilling::clientHoldsMoney() (PR-01, PR-02, PR-03, D-11,
 * D-14, D-15, D-16). Every name, key and amount is fictional.
 */

/**
 * Runs a callable as a system run, as the Admin context would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Creates a project with hourly billing through the Action.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeProject(Client $client, array $overrides = []): Project
{
    return asSystem(static fn (): Project => app(CreateProject::class)->handle($client, [
        'name' => 'Example project '.Str::lower(Str::random(6)),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '850,50',
        ...$overrides,
    ]));
}

/**
 * Runs UpdateProject on a fresh copy of the project.
 *
 * @param  array<string, mixed>  $data
 */
function updateProject(Project $project, array $data): Project
{
    return asSystem(static fn (): Project => app(UpdateProject::class)->handle(Project::query()->findOrFail($project->id), $data));
}

/**
 * The validation errors of a callable, keyed by field.
 *
 * @return array<string, list<string>>
 */
function writeErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * The stored billing row of a project.
 */
function billingOf(Project $project): ProjectBilling
{
    return asSystem(static fn (): ProjectBilling => ProjectBilling::query()->where('project_id', $project->id)->sole());
}

beforeEach(function (): void {
    $this->actingAs(Canary::admin());
    $this->client = asSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
});

it('stores the estimate typed in hours as exact seconds on creation and null when empty', function (): void {
    $withEstimate = makeProject($this->client, ['estimate_hours' => '1,5']);
    $withoutEstimate = makeProject($this->client, ['estimate_hours' => '']);
    $absent = makeProject($this->client);

    expect(billingOf($withEstimate)->estimate_seconds)->toBe(5400)
        ->and(billingOf($withoutEstimate)->estimate_seconds)->toBeNull()
        ->and(billingOf($absent)->estimate_seconds)->toBeNull();
});

it('reports an invalid estimate as a field error on creation and stores nothing', function (string $hours): void {
    $errors = writeErrors(fn () => makeProject($this->client, ['estimate_hours' => $hours]));

    expect($errors)->toHaveKey('estimate_hours')
        ->and($errors['estimate_hours'][0])->toBe(__('kokpit.projects.errors.estimate_invalid'))
        ->and(asSystem(static fn (): int => Project::query()->withTrashed()->count()))->toBe(0);
})->with(['three decimals' => ['1,333'], 'negative' => ['-1'], 'text' => ['abc'], 'grouping' => ['1 000'], 'beyond the column' => ['700000']]);

it('updates the project and its billing in one transaction', function (): void {
    $project = makeProject($this->client, ['internal_note' => 'First note']);

    $updated = updateProject($project, [
        'name' => 'Renamed example project',
        'description' => 'A fictional description',
        'start_date' => '2026-01-05',
        'end_date' => '2026-03-31',
        'client_visible' => true,
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
        'estimate_hours' => '12,25',
        'internal_note' => 'Second note',
    ]);

    $billing = billingOf($project);

    expect($updated->name)->toBe('Renamed example project')
        ->and($updated->client_visible)->toBeTrue()
        ->and($updated->key)->toBe($project->key)
        ->and($updated->start_date?->toDateString())->toBe('2026-01-05')
        ->and($billing->hourly_rate_minor)->toBe(90000)
        ->and($billing->estimate_seconds)->toBe(44100)
        ->and($billing->internal_note)->toBe('Second note')
        ->and(asSystem(static fn (): int => ProjectBilling::query()->count()))->toBe(1);
});

it('leaves what the data does not mention unchanged and clears what it sets empty', function (): void {
    $project = makeProject($this->client, ['estimate_hours' => '2', 'description' => 'Keep me']);

    updateProject($project, ['name' => 'Only the name changes']);

    $billing = billingOf($project);
    expect(Project::query()->findOrFail($project->id)->description)->toBe('Keep me')
        ->and($billing->estimate_seconds)->toBe(7200)
        ->and($billing->hourly_rate_minor)->toBe(85050);

    updateProject($project, ['estimate_hours' => '', 'hourly_rate' => '', 'description' => null]);

    $billing = billingOf($project);
    expect(Project::query()->findOrFail($project->id)->description)->toBeNull()
        ->and($billing->estimate_seconds)->toBeNull()
        ->and($billing->hourly_rate)->toBeNull();
});

it('syncs project tags on update and keeps them when the data has no tags', function (): void {
    $project = makeProject($this->client, ['tags' => ['web', 'internal']]);

    updateProject($project, ['name' => 'Same tags']);
    expect(Project::query()->findOrFail($project->id)->tags->pluck('name')->sort()->values()->all())->toBe(['internal', 'web']);

    updateProject($project, ['tags' => ['design']]);
    expect(Project::query()->findOrFail($project->id)->tags->pluck('name')->all())->toBe(['design']);

    updateProject($project, ['tags' => []]);
    expect(Project::query()->findOrFail($project->id)->tags)->toHaveCount(0);
});

it('refuses a different client and changes nothing', function (): void {
    $project = makeProject($this->client);
    $other = asSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));

    $errors = writeErrors(fn () => updateProject($project, ['name' => 'Moved project', 'client_id' => $other->id]));

    expect($errors)->toHaveKey('client_id')
        ->and($errors['client_id'][0])->toBe(__('kokpit.projects.errors.client_immutable'))
        ->and(Project::query()->findOrFail($project->id)->client_id)->toBe($this->client->id)
        ->and(Project::query()->findOrFail($project->id)->name)->toBe($project->name);
});

it('accepts the stored client id or none', function (): void {
    $project = makeProject($this->client);

    updateProject($project, ['name' => 'Same client given', 'client_id' => $this->client->id]);
    updateProject($project, ['name' => 'No client given']);

    expect(Project::query()->findOrFail($project->id)->name)->toBe('No client given');
});

it('switches status and priority between any values in any order', function (): void {
    $project = makeProject($this->client);

    foreach ([[ProjectStatus::Done, ProjectPriority::Urgent], [ProjectStatus::Planned, ProjectPriority::Low], [ProjectStatus::Done, ProjectPriority::Urgent], [ProjectStatus::Planned, ProjectPriority::Normal]] as [$status, $priority]) {
        updateProject($project, ['status' => $status->value, 'priority' => $priority->value]);

        $stored = Project::query()->findOrFail($project->id);
        expect($stored->status)->toBe($status)->and($stored->priority)->toBe($priority);
    }

    foreach (ProjectStatus::cases() as $from) {
        foreach (ProjectStatus::cases() as $to) {
            updateProject($project, ['status' => $from->value]);
            updateProject($project, ['status' => $to->value]);
        }
    }

    expect(Project::query()->findOrFail($project->id)->status)->toBe(ProjectStatus::cases()[array_key_last(ProjectStatus::cases())]);
});

it('requires a fixed price for a fixed-price project on update', function (): void {
    $project = makeProject($this->client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '120000']);

    $errors = writeErrors(fn () => updateProject($project, ['fixed_price' => '']));

    expect($errors)->toHaveKey('fixed_price')
        ->and($errors['fixed_price'][0])->toBe(__('kokpit.projects.errors.fixed_price_required'))
        ->and(billingOf($project)->fixed_price_minor)->toBe(120000 * 100);

    // Switching the type to a fixed price without ever having one is the same error.
    $hourly = makeProject($this->client);
    expect(writeErrors(fn () => updateProject($hourly, ['billing_type' => 'fixed_price'])))->toHaveKey('fixed_price');
});

it('switches to hourly billing with the price cleared', function (): void {
    $project = makeProject($this->client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '120000']);

    updateProject($project, ['billing_type' => 'hourly', 'fixed_price' => '', 'hourly_rate' => '700']);

    $billing = billingOf($project);
    expect($billing->billing_type)->toBe(BillingType::Hourly)
        ->and($billing->fixed_price)->toBeNull()
        ->and($billing->hourly_rate_minor)->toBe(70000);
});

it('stores a rate or price of zero and lets the project rate stay empty', function (): void {
    $project = makeProject($this->client, ['hourly_rate' => null]);
    expect(billingOf($project)->hourly_rate)->toBeNull();

    updateProject($project, ['hourly_rate' => '0']);
    expect(billingOf($project)->hourly_rate_minor)->toBe(0);

    updateProject($project, ['billing_type' => 'fixed_price', 'fixed_price' => '0']);
    expect(billingOf($project)->fixed_price_minor)->toBe(0);
});

it('turns a malformed amount into a field error on that field and changes nothing', function (string $field, string $amount): void {
    $project = makeProject($this->client, ['billing_type' => 'fixed_price', 'fixed_price' => '1000', 'hourly_rate' => '500']);

    $errors = writeErrors(fn () => updateProject($project, ['name' => 'Must not be stored', $field => $amount]));

    expect($errors)->toHaveKey($field)
        ->and($errors[$field][0])->toBe(__('kokpit.projects.errors.amount_invalid'))
        ->and(Project::query()->findOrFail($project->id)->name)->toBe($project->name)
        ->and(billingOf($project)->hourly_rate_minor)->toBe(50000)
        ->and(billingOf($project)->fixed_price_minor)->toBe(100000);
})->with([
    'rate with three decimals' => ['hourly_rate', '850,505'],
    'rate with a grouping space' => ['hourly_rate', '1 000'],
    'rate with a grouping comma' => ['hourly_rate', '1,000,50'],
    'negative rate' => ['hourly_rate', '-5'],
    'text rate' => ['hourly_rate', 'abc'],
    'price with three decimals' => ['fixed_price', '10,001'],
    'negative price' => ['fixed_price', '-0,01'],
    'price beyond the integer range' => ['fixed_price', '99999999999999999999'],
]);

it('refuses more decimals than the client currency allows', function (): void {
    $client = asSystem(static fn (): Client => Client::factory()->create(['currency' => 'JPY', 'hourly_rate' => Money::ofMinor(0, 'JPY')]));
    $project = makeProject($client, ['hourly_rate' => '1000']);

    expect(writeErrors(fn () => updateProject($project, ['hourly_rate' => '1000,5'])))->toHaveKey('hourly_rate')
        ->and(billingOf($project)->hourly_rate_minor)->toBe(1000)
        ->and(billingOf($project)->hourly_rate_currency)->toBe('JPY');
});

it('reports an invalid estimate as a field error on update and changes nothing', function (): void {
    $project = makeProject($this->client, ['estimate_hours' => '3']);

    $errors = writeErrors(fn () => updateProject($project, ['name' => 'Must not be stored', 'estimate_hours' => '1,333']));

    expect($errors)->toHaveKey('estimate_hours')
        ->and(billingOf($project)->estimate_seconds)->toBe(10800)
        ->and(Project::query()->findOrFail($project->id)->name)->toBe($project->name);
});

it('refuses a key used by another project, also an archived one, and accepts the own key', function (): void {
    $project = makeProject($this->client, ['key' => 'AAAA']);
    $other = makeProject($this->client, ['key' => 'BBBB']);
    asSystem(static fn () => $other->delete());

    $errors = writeErrors(fn () => updateProject($project, ['name' => 'Must not be stored', 'key' => 'bbbb']));

    expect($errors)->toHaveKey('key')
        ->and($errors['key'][0])->toBe(__('kokpit.projects.errors.key_taken'))
        ->and(Project::query()->findOrFail($project->id)->key)->toBe('AAAA')
        ->and(Project::query()->findOrFail($project->id)->name)->toBe($project->name);

    updateProject($project, ['key' => 'aaaa', 'name' => 'Own key kept']);
    expect(Project::query()->findOrFail($project->id)->name)->toBe('Own key kept')
        ->and(Project::query()->findOrFail($project->id)->key)->toBe('AAAA');

    updateProject($project, ['key' => 'cccc']);
    expect(Project::query()->findOrFail($project->id)->key)->toBe('CCCC');
});

it('lists an active project of an active client as selectable', function (): void {
    $project = makeProject($this->client);

    expect(Project::selectable()->pluck('id')->all())->toBe([$project->id])
        ->and(asSystem(static fn (): array => Project::selectable()->pluck('id')->all()))->toBe([$project->id]);
});

it('hides an archived project and every project of an archived client from the selectable scope', function (): void {
    $active = makeProject($this->client);
    $archived = makeProject($this->client);
    asSystem(static fn () => $archived->delete());

    $archivedClient = asSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
    $ofArchivedClient = makeProject($archivedClient);
    asSystem(static fn () => $archivedClient->delete());

    $expected = [$active->id];

    expect(Project::selectable()->pluck('id')->all())->toBe($expected)
        ->and(asSystem(static fn (): array => Project::selectable()->pluck('id')->all()))->toBe($expected)
        ->and(asSystem(static fn (): array => Project::query()->withTrashed()->pluck('id')->all()))->toContain($ofArchivedClient->id, $archived->id);
});

it('applies the selectable scope to a Partner on top of the Partner scope', function (): void {
    $visible = makeProject($this->client, ['client_visible' => true]);
    makeProject($this->client, ['client_visible' => false]);

    $this->actingAs(Canary::partnerFor($this->client->id));

    expect(Project::selectable()->pluck('id')->all())->toBe([$visible->id]);

    asSystem(fn () => $this->client->delete());

    expect(Project::selectable()->pluck('id')->all())->toBe([]);
});

it('reports whether a client holds money on any project, archived ones included', function (): void {
    expect(ProjectBilling::clientHoldsMoney($this->client->id))->toBeFalse();

    $empty = makeProject($this->client, ['hourly_rate' => null, 'billing_type' => 'hourly']);
    expect(ProjectBilling::clientHoldsMoney($this->client->id))->toBeFalse();

    asSystem(static fn () => $empty->delete());
    $archived = makeProject($this->client, ['hourly_rate' => '1']);
    asSystem(static fn () => $archived->delete());

    expect(ProjectBilling::clientHoldsMoney($this->client->id))->toBeTrue();

    $other = asSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
    expect(ProjectBilling::clientHoldsMoney($other->id))->toBeFalse();
});

it('counts a rate or price of zero as money held, and a fixed price too', function (): void {
    makeProject($this->client, ['hourly_rate' => '0']);
    expect(ProjectBilling::clientHoldsMoney($this->client->id))->toBeTrue();

    $client = asSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
    makeProject($client, ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5']);
    expect(ProjectBilling::clientHoldsMoney($client->id))->toBeTrue();
});
