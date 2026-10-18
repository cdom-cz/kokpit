<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Jobs\PruneProcessedWebhookCalls;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\StripeEvents;

/*
 * D-17, PA-08: processed Stripe events are pruned after 90 days by a daily job that runs as system. A failed or
 * waiting event is never pruned, and a card payment record does not depend on the event row. Every id is assembled at
 * runtime.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Stores one Stripe event row created `$daysAgo` days before the frozen clock.
 *
 * @param  array{processed?: bool, failed?: bool, name?: string}  $state
 */
function pruneEventRow(int $daysAgo, array $state = []): WebhookCall
{
    $moment = now()->subDays($daysAgo);
    $eventId = StripeEvents::token('evt');

    return app(PartnerContext::class)->runAsSystem(static function () use ($moment, $state, $eventId): WebhookCall {
        $call = (new WebhookCall)->forceFill([
            'name' => $state['name'] ?? 'stripe',
            'url' => 'https://example.com/webhooks/stripe',
            'payload' => ['marker' => 'x'],
            'event_id' => $eventId,
            'event_name' => 'checkout.session.completed',
            'processed_at' => ($state['processed'] ?? false) ? $moment : null,
            'failed_at' => ($state['failed'] ?? false) ? $moment : null,
            'failure_class' => ($state['failed'] ?? false) ? 'unexpected' : null,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
        $call->save();

        return $call;
    });
}

/** @return list<string> the ids of every stored event, whoever may see it */
function pruneEventIds(): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => WebhookCall::query()->orderBy('id')->pluck('id')->all(),
    );
}

it('deletes processed events older than 90 days and nothing else, and keeps the card payment record', function (): void {
    $oldProcessed = pruneEventRow(91, ['processed' => true]);
    $oldWithPayment = pruneEventRow(91, ['processed' => true]);
    $youngProcessed = pruneEventRow(89, ['processed' => true]);
    $oldWaiting = pruneEventRow(120);
    $oldFailed = pruneEventRow(120, ['failed' => true]);
    $oldOther = pruneEventRow(120, ['processed' => true, 'name' => 'other']);

    $record = app(PartnerContext::class)->runAsSystem(static fn (): ?CardPayment => DB::transaction(static fn (): ?CardPayment => CardPayment::recordOnce([
        'type' => CardPaymentType::Unmatched,
        'reason' => CardPaymentReason::Undetermined,
        'amount_minor' => 12500,
        'currency' => 'CZK',
        'received_at' => now()->subDays(91),
        'stripe_event_id' => $oldWithPayment->event_id,
        'stripe_session_id' => StripeEvents::token('cs', 'test'),
        'stripe_payment_intent_id' => StripeEvents::token('pi'),
    ])));

    expect($record)->toBeInstanceOf(CardPayment::class);

    PruneProcessedWebhookCalls::dispatchSync();

    expect(pruneEventIds())->toEqualCanonicalizing([
        $youngProcessed->id, $oldWaiting->id, $oldFailed->id, $oldOther->id,
    ]);

    $kept = app(PartnerContext::class)->runAsSystem(static fn (): ?CardPayment => CardPayment::query()->find($record->id));

    expect($kept)->not->toBeNull()
        ->and($kept->stripe_event_id)->toBe($oldWithPayment->event_id)
        ->and($oldProcessed->id)->not->toBe($oldWithPayment->id);
});

it('finds nothing more to delete on a second run', function (): void {
    pruneEventRow(91, ['processed' => true]);
    $kept = pruneEventRow(10, ['processed' => true]);

    PruneProcessedWebhookCalls::dispatchSync();
    $afterFirst = pruneEventIds();
    PruneProcessedWebhookCalls::dispatchSync();

    expect($afterFirst)->toBe([$kept->id])
        ->and(pruneEventIds())->toBe($afterFirst);
});

it('reads the retention from the webhook client configuration', function (): void {
    $row = pruneEventRow(40, ['processed' => true]);

    PruneProcessedWebhookCalls::dispatchSync();
    expect(pruneEventIds())->toBe([$row->id]);

    config(['webhook-client.delete_after_days' => 30]);
    PruneProcessedWebhookCalls::dispatchSync();

    expect(pruneEventIds())->toBe([]);
});

it('is scheduled daily on one server', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => $event->description === 'kokpit-webhook-prune',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->onOneServer)->toBeTrue()
        ->and($events[0]->expression)->toBe('0 0 * * *');
});
