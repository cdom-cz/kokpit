<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;

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
