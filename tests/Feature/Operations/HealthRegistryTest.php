<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use Tests\Support\Probes\HealthProbeIndicator;

/*
 * D-13: the registry holds one indicator per HealthSlot case. The test iterates the
 * enum, so a slot added later without an indicator fails here.
 */

it('resolves an indicator for every health slot, and the indicator reports that slot', function (): void {
    $registry = app(HealthIndicatorRegistry::class);

    foreach (HealthSlot::cases() as $slot) {
        expect($registry->indicators())->toHaveKey($slot->value)
            ->and($registry->indicators()[$slot->value]->slot())->toBe($slot);
    }

    expect(array_keys($registry->indicators()))->toBe(array_map(
        static fn (HealthSlot $slot): string => $slot->value,
        HealthSlot::cases(),
    ));
});

it('is one registry for the whole application', function (): void {
    expect(app(HealthIndicatorRegistry::class))->toBe(app(HealthIndicatorRegistry::class));
});

it('has the six slots of the System page', function (): void {
    expect(array_map(static fn (HealthSlot $slot): string => $slot->value, HealthSlot::cases()))->toBe([
        'failed_jobs',
        'oldest_pending_job',
        'scheduler_heartbeat',
        'last_rate_date',
        'unprocessed_webhooks',
        'unsent_invoice_emails',
    ]);
});

it('reports every slot as not available yet until the owning plan or phase replaces its placeholder', function (): void {
    $results = app(HealthIndicatorRegistry::class)->results();

    expect($results)->toHaveCount(count(HealthSlot::cases()));

    foreach (HealthSlot::cases() as $slot) {
        expect($results[$slot->value]->status)->toBe(HealthStatus::NotAvailable)
            ->and($results[$slot->value]->detail)->toBe(__('kokpit.system.not_available_yet'));
    }
});

it('finds the one slot left out of a hand-built indicator list', function (): void {
    $list = [];

    foreach (HealthSlot::cases() as $slot) {
        if ($slot !== HealthSlot::LastRateDate) {
            $list[] = new HealthProbeIndicator($slot);
        }
    }

    expect(HealthIndicatorRegistry::missingSlots($list))->toBe([HealthSlot::LastRateDate])
        ->and(HealthIndicatorRegistry::missingSlots([]))->toBe(HealthSlot::cases());
});

it('finds no missing slot in the bound registry', function (): void {
    expect(HealthIndicatorRegistry::missingSlots(app(HealthIndicatorRegistry::class)->indicators()))->toBe([]);
});

it('refuses to replace a slot with an indicator of another slot and keeps the old indicator', function (): void {
    $registry = app(HealthIndicatorRegistry::class);
    $before = $registry->indicators()[HealthSlot::FailedJobs->value];

    expect(fn () => $registry->replace(HealthSlot::FailedJobs, new HealthProbeIndicator(HealthSlot::LastRateDate)))
        ->toThrow(InvalidArgumentException::class);

    expect($registry->indicators()[HealthSlot::FailedJobs->value])->toBe($before);
});

it('swaps the indicator of one slot and leaves the others alone', function (): void {
    $registry = app(HealthIndicatorRegistry::class);
    $probe = new HealthProbeIndicator(HealthSlot::LastRateDate, new HealthResult(HealthStatus::Ok, 'fresh'));

    $registry->replace(HealthSlot::LastRateDate, $probe);
    $results = $registry->results();

    expect($results[HealthSlot::LastRateDate->value]->status)->toBe(HealthStatus::Ok)
        ->and($results[HealthSlot::LastRateDate->value]->value)->toBe('fresh')
        ->and($results[HealthSlot::FailedJobs->value]->status)->toBe(HealthStatus::NotAvailable);
});

it('refuses to register a second indicator for a slot', function (): void {
    $registry = app(HealthIndicatorRegistry::class);

    expect(fn () => $registry->register(new HealthProbeIndicator(HealthSlot::FailedJobs)))
        ->toThrow(LogicException::class);
});

it('turns a throwing indicator into Error with the exception class only and keeps the other results', function (): void {
    $secret = 'connection '.implode('.', ['db', 'internal']).' refused';
    $registry = app(HealthIndicatorRegistry::class);
    $registry->replace(HealthSlot::SchedulerHeartbeat, new HealthProbeIndicator(HealthSlot::SchedulerHeartbeat, new RuntimeException($secret)));
    $registry->replace(HealthSlot::FailedJobs, new HealthProbeIndicator(HealthSlot::FailedJobs, new TypeError($secret)));

    $results = $registry->results();

    expect($results)->toHaveCount(count(HealthSlot::cases()))
        ->and($results[HealthSlot::SchedulerHeartbeat->value]->status)->toBe(HealthStatus::Error)
        ->and($results[HealthSlot::SchedulerHeartbeat->value]->detail)->toBe(RuntimeException::class)
        ->and($results[HealthSlot::FailedJobs->value]->status)->toBe(HealthStatus::Error)
        ->and($results[HealthSlot::FailedJobs->value]->detail)->toBe(TypeError::class)
        ->and($results[HealthSlot::OldestPendingJob->value]->status)->toBe(HealthStatus::NotAvailable)
        ->and($results[HealthSlot::LastRateDate->value]->status)->toBe(HealthStatus::NotAvailable);

    foreach ($results as $result) {
        expect((string) $result->value.(string) $result->detail)->not->toContain($secret);
    }
});

it('reports Error, never Ok, for a slot that has no indicator', function (): void {
    $results = (new HealthIndicatorRegistry)->results();

    expect($results)->toHaveCount(count(HealthSlot::cases()));

    foreach (HealthSlot::cases() as $slot) {
        expect($results[$slot->value]->status)->toBe(HealthStatus::Error)
            ->and($results[$slot->value]->detail)->toBe(__('kokpit.system.no_indicator'));
    }
});
