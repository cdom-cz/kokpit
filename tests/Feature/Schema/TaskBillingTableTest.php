<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * Database-level invariants of task_billing (TA-06, D-12 to D-14) and the
 * activity allowlist of the billing changes. The constraint cases use raw SQL,
 * so no model, cast or form rule stands between the value and the constraint.
 * Values are fictional.
 */

/**
 * A fresh task row (factory) and its id.
 */
function taskBillingSchemaTaskId(): string
{
    return app(PartnerContext::class)->runAsSystem(static fn (): string => Task::factory()->create()->id);
}

/**
 * Inserts one task_billing row with valid defaults; overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function taskBillingSchemaInsert(array $overrides = []): string
{
    $row = [
        'id' => (string) Str::uuid7(),
        'task_id' => taskBillingSchemaTaskId(),
        'billing_type' => 'inherit',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];

    $columns = array_keys($row);

    DB::insert(
        sprintf('INSERT INTO task_billing (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
        array_values($row),
    );

    return (string) $row['id'];
}

describe('constraints', function (): void {
    it('refuses a second billing row for one task with SQLSTATE 23505', function (): void {
        $taskId = taskBillingSchemaTaskId();
        taskBillingSchemaInsert(['task_id' => $taskId]);

        RawSql::expectSqlState('23505', fn () => taskBillingSchemaInsert(['task_id' => $taskId]));
    });

    it('refuses an unknown billing type with SQLSTATE 23514', function (): void {
        RawSql::expectSqlState('23514', fn () => taskBillingSchemaInsert(['billing_type' => 'retainer']));
    });

    it('refuses a rate or price with only one half of the minor and currency pair with SQLSTATE 23514', function (string $columns): void {
        RawSql::expectSqlState('23514', fn () => taskBillingSchemaInsert(json_decode($columns, true, flags: JSON_THROW_ON_ERROR)));
    })->with([
        'rate without currency' => ['{"hourly_rate_minor": 100}'],
        'rate currency without amount' => ['{"hourly_rate_currency": "CZK"}'],
        'price without currency' => ['{"billing_type": "hourly", "fixed_price_minor": 100}'],
        'price currency without amount' => ['{"fixed_price_currency": "CZK"}'],
    ]);

    it('refuses a negative rate, price or estimate with SQLSTATE 23514', function (string $columns): void {
        RawSql::expectSqlState('23514', fn () => taskBillingSchemaInsert(json_decode($columns, true, flags: JSON_THROW_ON_ERROR)));
    })->with([
        'negative rate' => ['{"hourly_rate_minor": -1, "hourly_rate_currency": "CZK"}'],
        'negative price' => ['{"fixed_price_minor": -1, "fixed_price_currency": "CZK"}'],
        'negative estimate' => ['{"estimate_seconds": -1}'],
    ]);

    it('refuses a currency that is not three upper case letters with SQLSTATE 23514', function (string $columns): void {
        RawSql::expectSqlState('23514', fn () => taskBillingSchemaInsert(json_decode($columns, true, flags: JSON_THROW_ON_ERROR)));
    })->with([
        'lower case rate currency' => ['{"hourly_rate_minor": 100, "hourly_rate_currency": "czk"}'],
        'too short rate currency' => ['{"hourly_rate_minor": 100, "hourly_rate_currency": "CZ "}'],
        'lower case price currency' => ['{"billing_type": "fixed_price", "fixed_price_minor": 100, "fixed_price_currency": "eur"}'],
        'digits in price currency' => ['{"billing_type": "fixed_price", "fixed_price_minor": 100, "fixed_price_currency": "E1R"}'],
    ]);

    it('refuses different currencies of the rate and the price with SQLSTATE 23514', function (): void {
        RawSql::expectSqlState('23514', fn () => taskBillingSchemaInsert([
            'hourly_rate_minor' => 100,
            'hourly_rate_currency' => 'CZK',
            'fixed_price_minor' => 100,
            'fixed_price_currency' => 'EUR',
        ]));
    });

    it('refuses a fixed-price task without a fixed price with SQLSTATE 23514', function (): void {
        RawSql::expectSqlState('23514', fn () => taskBillingSchemaInsert(['billing_type' => 'fixed_price']));
    });

    it('refuses billing for an unknown task with SQLSTATE 23503', function (): void {
        RawSql::expectSqlState('23503', fn () => taskBillingSchemaInsert(['task_id' => (string) Str::uuid7()]));
    });

    it('refuses a hard delete of a task that has a billing row with SQLSTATE 23001', function (): void {
        $taskId = taskBillingSchemaTaskId();
        taskBillingSchemaInsert(['task_id' => $taskId]);

        RawSql::expectSqlState('23001', fn () => DB::delete('DELETE FROM tasks WHERE id = ?', [$taskId]));
    });

    it('allows a task that only inherits, with no values at all', function (): void {
        RawSql::expectAllowed(fn () => taskBillingSchemaInsert());
    });

    it('allows a non-billable task without any value', function (): void {
        RawSql::expectAllowed(fn () => taskBillingSchemaInsert(['billing_type' => 'non_billable']));
    });

    it('allows a rate, a price and an estimate of zero', function (): void {
        RawSql::expectAllowed(fn () => taskBillingSchemaInsert(['hourly_rate_minor' => 0, 'hourly_rate_currency' => 'CZK']));
        RawSql::expectAllowed(fn () => taskBillingSchemaInsert([
            'billing_type' => 'fixed_price',
            'fixed_price_minor' => 0,
            'fixed_price_currency' => 'CZK',
        ]));
        RawSql::expectAllowed(fn () => taskBillingSchemaInsert(['estimate_seconds' => 0]));
    });

    it('allows a rate and a price in the same currency', function (): void {
        RawSql::expectAllowed(fn () => taskBillingSchemaInsert([
            'billing_type' => 'fixed_price',
            'hourly_rate_minor' => 100,
            'hourly_rate_currency' => 'EUR',
            'fixed_price_minor' => 500,
            'fixed_price_currency' => 'EUR',
        ]));
    });
});

describe('activity allowlist', function (): void {
    it('logs a rate change with the old and new minor amounts and never the internal note', function (): void {
        $this->actingAs($admin = Canary::admin());
        $context = app(PartnerContext::class);
        $client = $context->runAsSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(0, 'CZK')]));
        $note = Canary::canary('note');
        $project = $context->runAsSystem(static fn () => app(CreateProject::class)->handle($client, [
            'name' => 'Example project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '800',
        ]));
        $task = $context->runAsSystem(static fn (): Task => app(CreateTask::class)->handle($admin, $project, ['title' => 'Example task']));

        $context->runAsSystem(static fn () => app(UpdateTask::class)->handle($admin, $task, ['hourly_rate' => '900', 'internal_note' => $note]));
        $context->runAsSystem(static fn () => app(UpdateTask::class)->handle($admin, $task, ['hourly_rate' => '950', 'internal_note' => $note.' changed']));

        $updated = Activity::query()->where('log_name', 'task_billing')->where('event', 'updated')->get();

        expect(Activity::query()->where('log_name', 'task_billing')->where('event', 'created')->count())->toBe(1)
            ->and($updated)->toHaveCount(1)
            ->and($updated->first()->attribute_changes->get('attributes'))->toBe(['hourly_rate_minor' => 95000])
            ->and($updated->first()->attribute_changes->get('old'))->toBe(['hourly_rate_minor' => 90000]);

        $payloads = Activity::query()->get()->map(static fn (Activity $activity): string => json_encode($activity->getAttributes(), JSON_THROW_ON_ERROR))->implode("\n");

        expect($payloads)->not->toContain($note)->not->toContain('internal_note');
    });

    it('writes no activity row for a change of the internal note alone', function (): void {
        $this->actingAs($admin = Canary::admin());
        $context = app(PartnerContext::class);
        $project = $context->runAsSystem(static fn () => app(CreateProject::class)->handle(
            Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(0, 'CZK')]),
            ['name' => 'Example project', 'key' => Canary::projectKey(), 'billing_type' => 'hourly'],
        ));
        $task = $context->runAsSystem(static fn (): Task => app(CreateTask::class)->handle($admin, $project, ['title' => 'Example task']));
        $context->runAsSystem(static fn () => app(UpdateTask::class)->handle($admin, $task, ['hourly_rate' => '900']));
        $before = Activity::query()->where('log_name', 'task_billing')->count();

        $context->runAsSystem(static fn () => app(UpdateTask::class)->handle($admin, $task, ['internal_note' => Canary::canary('note')]));

        expect(Activity::query()->where('log_name', 'task_billing')->count())->toBe($before);
    });

    it('shows a Partner no billing activity row', function (): void {
        $this->actingAs($admin = Canary::admin());
        $context = app(PartnerContext::class);
        $client = $context->runAsSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(0, 'CZK')]));
        $project = $context->runAsSystem(static fn () => app(CreateProject::class)->handle($client, [
            'name' => 'Example project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'client_visible' => true,
        ]));
        $task = $context->runAsSystem(static fn (): Task => app(CreateTask::class)->handle($admin, $project, ['title' => 'Example task']));
        $context->runAsSystem(static fn () => app(UpdateTask::class)->handle($admin, $task, ['hourly_rate' => '900']));

        expect(Activity::query()->where('log_name', 'task_billing')->count())->toBeGreaterThan(0);

        $this->actingAs(Canary::partnerFor($client->id));

        expect(Activity::query()->where('log_name', 'task_billing')->count())->toBe(0);
    });
});
