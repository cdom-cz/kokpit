<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * Database-level invariants of project_billing (PR-03, D-05, D-15) and the
 * behaviour of the project and billing activity allowlists (D-06). The
 * constraint tests use raw SQL so that no model, cast or form rule stands
 * between the value and the constraint. Values are fictional.
 */

/**
 * A fresh project row (factory) and its id.
 */
function billingTestProjectId(): string
{
    return app(PartnerContext::class)->runAsSystem(static fn (): string => Project::factory()->create()->id);
}

/**
 * Inserts one project_billing row with valid defaults; overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertBillingRow(array $overrides = []): string
{
    $row = [
        'id' => (string) Str::uuid7(),
        'project_id' => billingTestProjectId(),
        'billing_type' => 'hourly',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];

    $columns = array_keys($row);

    DB::insert(
        sprintf('INSERT INTO project_billing (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
        array_values($row),
    );

    return (string) $row['id'];
}

describe('constraints', function (): void {
    it('refuses a second billing row for one project with SQLSTATE 23505', function (): void {
        $projectId = billingTestProjectId();
        insertBillingRow(['project_id' => $projectId]);

        RawSql::expectSqlState('23505', fn () => insertBillingRow(['project_id' => $projectId]));
    });

    it('refuses an unknown billing type with SQLSTATE 23514', function (): void {
        RawSql::expectSqlState('23514', fn () => insertBillingRow(['billing_type' => 'retainer']));
    });

    it('refuses a rate or price with only one half of the minor and currency pair with SQLSTATE 23514', function (string $columns): void {
        RawSql::expectSqlState('23514', fn () => insertBillingRow(json_decode($columns, true, flags: JSON_THROW_ON_ERROR)));
    })->with([
        'rate without currency' => ['{"hourly_rate_minor": 100}'],
        'rate currency without amount' => ['{"hourly_rate_currency": "CZK"}'],
        'price without currency' => ['{"billing_type": "hourly", "fixed_price_minor": 100}'],
        'price currency without amount' => ['{"fixed_price_currency": "CZK"}'],
    ]);

    it('refuses a negative rate, price or estimate with SQLSTATE 23514', function (string $columns): void {
        RawSql::expectSqlState('23514', fn () => insertBillingRow(json_decode($columns, true, flags: JSON_THROW_ON_ERROR)));
    })->with([
        'negative rate' => ['{"hourly_rate_minor": -1, "hourly_rate_currency": "CZK"}'],
        'negative price' => ['{"fixed_price_minor": -1, "fixed_price_currency": "CZK"}'],
        'negative estimate' => ['{"estimate_seconds": -1}'],
    ]);

    it('allows a rate, a price and an estimate of zero', function (): void {
        RawSql::expectAllowed(fn () => insertBillingRow(['hourly_rate_minor' => 0, 'hourly_rate_currency' => 'CZK']));
        RawSql::expectAllowed(fn () => insertBillingRow([
            'billing_type' => 'fixed_price',
            'fixed_price_minor' => 0,
            'fixed_price_currency' => 'CZK',
        ]));
        RawSql::expectAllowed(fn () => insertBillingRow(['estimate_seconds' => 0]));
    });

    it('allows a project without a rate, a price and an estimate', function (): void {
        RawSql::expectAllowed(fn () => insertBillingRow());
    });

    it('refuses different currencies of the rate and the price with SQLSTATE 23514', function (): void {
        RawSql::expectSqlState('23514', fn () => insertBillingRow([
            'hourly_rate_minor' => 100,
            'hourly_rate_currency' => 'CZK',
            'fixed_price_minor' => 100,
            'fixed_price_currency' => 'EUR',
        ]));
    });

    it('refuses a currency that is not three upper case letters with SQLSTATE 23514', function (): void {
        RawSql::expectSqlState('23514', fn () => insertBillingRow(['hourly_rate_minor' => 100, 'hourly_rate_currency' => 'czk']));
    });

    it('refuses a fixed-price project without a fixed price with SQLSTATE 23514', function (): void {
        RawSql::expectSqlState('23514', fn () => insertBillingRow(['billing_type' => 'fixed_price']));
    });

    it('refuses billing for an unknown project with SQLSTATE 23503', function (): void {
        RawSql::expectSqlState('23503', fn () => insertBillingRow(['project_id' => (string) Str::uuid7()]));
    });

    it('refuses a hard delete of a project that has a billing row with SQLSTATE 23001', function (): void {
        $projectId = billingTestProjectId();
        insertBillingRow(['project_id' => $projectId]);

        RawSql::expectSqlState('23001', fn () => DB::delete('DELETE FROM projects WHERE id = ?', [$projectId]));
    });
});

describe('activity allowlist', function (): void {
    it('logs the allowlisted project and billing attributes and never the free text', function (): void {
        $this->actingAs(Canary::admin());
        $context = app(PartnerContext::class);
        $client = $context->runAsSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
        $note = Canary::canary('note');
        $description = Canary::canary('description');

        $project = $context->runAsSystem(static fn (): Project => app(CreateProject::class)->handle($client, [
            'name' => 'Example project',
            'key' => Canary::projectKey(),
            'description' => $description,
            'billing_type' => 'hourly',
            'hourly_rate' => '800',
            'internal_note' => $note,
        ]));

        $context->runAsSystem(static fn () => app(UpdateProject::class)->handle($project, [
            'name' => 'Renamed example project',
            'description' => $description.' changed',
            'hourly_rate' => '900',
            'estimate_hours' => '2',
            'internal_note' => $note.' changed',
        ]));

        $projectRows = Activity::query()->where('log_name', 'project')->where('event', 'updated')->get();
        $billingRows = Activity::query()->where('log_name', 'project_billing')->where('event', 'updated')->get();

        expect($projectRows)->toHaveCount(1)
            ->and(array_keys($projectRows->first()->attribute_changes->get('attributes')))->toBe(['name'])
            ->and($billingRows)->toHaveCount(1)
            ->and(array_keys($billingRows->first()->attribute_changes->get('attributes')))->toContain('hourly_rate_minor', 'estimate_seconds')
            ->and($billingRows->first()->attribute_changes->get('attributes')['hourly_rate_minor'])->toBe(90000);

        // The creation rows are logged too, and no payload anywhere carries a note or a description.
        expect(Activity::query()->where('log_name', 'project')->where('event', 'created')->count())->toBe(1)
            ->and(Activity::query()->where('log_name', 'project_billing')->where('event', 'created')->count())->toBe(1);

        $payloads = Activity::query()->get()->map(static fn (Activity $activity): string => json_encode($activity->getAttributes(), JSON_THROW_ON_ERROR))->implode("\n");

        expect($payloads)->not->toContain($note)->not->toContain($description);
    });

    it('writes no activity row for a change of the internal note or the description alone', function (): void {
        $this->actingAs(Canary::admin());
        $context = app(PartnerContext::class);
        $client = $context->runAsSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
        $project = $context->runAsSystem(static fn (): Project => app(CreateProject::class)->handle($client, [
            'name' => 'Example project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '800',
        ]));
        $before = Activity::query()->count();

        $context->runAsSystem(static fn () => app(UpdateProject::class)->handle($project, [
            'description' => Canary::canary('description'),
            'internal_note' => Canary::canary('note'),
        ]));

        expect(Activity::query()->count())->toBe($before);
    });

    it('shows a Partner no activity row', function (): void {
        $this->actingAs(Canary::admin());
        $client = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK']));
        app(PartnerContext::class)->runAsSystem(static fn () => app(CreateProject::class)->handle($client, [
            'name' => 'Example project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '800',
            'client_visible' => true,
        ]));

        $this->actingAs(Canary::partnerFor($client->id));

        expect(Activity::query()->count())->toBe(0);
    });
});
