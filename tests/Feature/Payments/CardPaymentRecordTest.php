<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;
use Tests\Support\StripeEvents;

/*
 * The record of every card payment and refund event that Stripe reports (PA-06, PA-07, PA-08; D-11 to D-14, D-17).
 * Every name and amount is fictional; Stripe references are assembled at runtime and never written down in a test.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    Queue::fake();
});

/**
 * A fresh set of Stripe references for one event, assembled at runtime.
 *
 * @return array{stripe_event_id: string, stripe_session_id: string, stripe_payment_intent_id: string, stripe_link_id: string}
 */
function cardRefs(): array
{
    return [
        'stripe_event_id' => StripeEvents::token('evt'),
        'stripe_session_id' => StripeEvents::token('cs', 'test'),
        'stripe_payment_intent_id' => StripeEvents::token('pi'),
        'stripe_link_id' => StripeEvents::token('plink'),
    ];
}

/**
 * Records the card payment of a whole document as the queue job will: as system, in one transaction, the document
 * locked, the payment and its income through the one write path, then the matched record.
 *
 * @param  array<string, mixed>  $overrides  columns of the record replacing the matched defaults
 * @return array{0: InvoicePayment, 1: CardPayment|null, 2: array<string, mixed>}
 */
function recordMatchedCardPayment(Invoice $invoice, array $overrides = []): array
{
    $attributes = [
        'type' => CardPaymentType::Matched,
        'amount_minor' => $invoice->total_minor,
        'currency' => $invoice->currency,
        'invoice_id' => $invoice->id,
        'received_at' => now(),
        ...cardRefs(),
        ...$overrides,
    ];

    return app(PartnerContext::class)->runAsSystem(static fn (): array => DB::transaction(static function () use ($invoice, $attributes): array {
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        $payment = app(RecordPayment::class)->recordLocked($locked, new PaymentFacts(
            Money::ofMinor($locked->total_minor, $locked->currency),
            IssueContext::today(),
            PaymentMethod::Card,
            IssueContext::today(),
            null,
            null,
        ), null);

        $record = CardPayment::recordOnce([...$attributes, 'invoice_payment_id' => $payment->id]);

        return [$payment, $record, $attributes];
    }));
}

/**
 * Calls recordOnce() as system, in its own transaction.
 *
 * @param  array<string, mixed>  $attributes
 */
function recordOnceAsSystem(array $attributes): ?CardPayment
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?CardPayment => DB::transaction(static fn (): ?CardPayment => CardPayment::recordOnce($attributes)));
}

describe('the tracer', function (): void {
    it('records a matched card payment once, survives the deletion of its payment and can never be booked again by a retry', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        [$payment, $record, $attributes] = recordMatchedCardPayment($invoice);

        expect($record)->toBeInstanceOf(CardPayment::class)
            ->and($record->type)->toBe(CardPaymentType::Matched)
            ->and($record->resolution)->toBe(CardPaymentResolution::None)
            ->and($record->reason)->toBeNull()
            ->and($record->invoice_id)->toBe($invoice->id)
            ->and($record->invoice_payment_id)->toBe($payment->id)
            ->and($record->stripe_event_id)->toBe($attributes['stripe_event_id'])
            ->and($record->amount()->minor)->toBe($invoice->total_minor)
            ->and($record->status())->toBe(CardPaymentStatus::Booked)
            ->and($record->isUnresolved())->toBeFalse();

        $this->actingAs(Canary::admin());
        app(DeletePayment::class)->handle(Canary::admin(), $payment);

        $kept = CardPayment::query()->findOrFail($record->id);

        expect(InvoicePayment::query()->find($payment->id))->toBeNull()
            ->and($kept->invoice_payment_id)->toBeNull()
            ->and($kept->stripe_event_id)->toBe($attributes['stripe_event_id'])
            ->and($kept->stripe_session_id)->toBe($attributes['stripe_session_id'])
            ->and($kept->stripe_payment_intent_id)->toBe($attributes['stripe_payment_intent_id'])
            ->and($kept->stripe_link_id)->toBe($attributes['stripe_link_id'])
            ->and($kept->status())->toBe(CardPaymentStatus::PaymentDeleted);

        $base = [
            'type' => CardPaymentType::Matched,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'invoice_id' => $invoice->id,
            'received_at' => now(),
        ];

        // The same event again (a redelivery), a new event of the same session (a replay), a new event and session of
        // the same payment intent: each is refused by a reserved reference and stores nothing.
        $sameEvent = recordOnceAsSystem([...$base, ...cardRefs(), 'stripe_event_id' => $attributes['stripe_event_id']]);
        $sameSession = recordOnceAsSystem([...$base, ...cardRefs(), 'stripe_session_id' => $attributes['stripe_session_id']]);
        $sameIntent = recordOnceAsSystem([...$base, ...cardRefs(), 'stripe_payment_intent_id' => $attributes['stripe_payment_intent_id']]);

        expect($sameEvent)->toBeNull()
            ->and($sameSession)->toBeNull()
            ->and($sameIntent)->toBeNull()
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => CardPayment::query()->count()))->toBe(1)
            ->and(InvoicePayment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
    });

    it('shows a Partner of the same client no card payment and refuses the policy', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);
        [, $record] = recordMatchedCardPayment($invoice);

        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(CardPayment::query()->count())->toBe(0)
            ->and(CardPayment::query()->find($record?->id))->toBeNull()
            ->and($partner->can('view', $record))->toBeFalse()
            ->and($partner->can('viewAny', CardPayment::class))->toBeFalse();
    });

    it('refuses to record outside a transaction', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        // RefreshDatabase wraps the test in a transaction; leave it to see the guard.
        $level = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        try {
            expect(fn () => CardPayment::recordOnce([
                'type' => CardPaymentType::Matched,
                'amount_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'invoice_id' => $invoice->id,
                'received_at' => now(),
                ...cardRefs(),
            ]))->toThrow(LogicException::class);
        } finally {
            while (DB::transactionLevel() < $level) {
                DB::beginTransaction();
            }
        }
    });
});

/**
 * A complete row for a raw insert: an unmatched payment of a different amount, with fresh Stripe references.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rawCardRow(string $invoiceId, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'type' => 'unmatched',
        'reason' => 'amount',
        'amount_minor' => 1000,
        'currency' => 'CZK',
        'invoice_id' => $invoiceId,
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
        ...cardRefs(),
        ...$overrides,
    ];
}

/**
 * Stores a row through recordOnce() and returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function storedCardRow(string $invoiceId, array $overrides = []): string
{
    $row = rawCardRow($invoiceId, $overrides);
    unset($row['id'], $row['created_at'], $row['updated_at']);

    $record = recordOnceAsSystem($row);

    return $record?->id ?? throw new LogicException('The record was not stored.');
}

/**
 * The columns of an Admin resolution by note, in one update.
 *
 * @return array<string, mixed>
 */
function notedResolution(): array
{
    return ['resolution' => 'noted', 'resolution_note' => 'Example note', 'resolved_at' => now(), 'resolved_by' => Canary::admin()->getKey()];
}

describe('the shape the database insists on', function (): void {
    it('refuses an inconsistent row with a check violation', function (string $label, array $overrides): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        RawSql::expectSqlState('23514', fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, $overrides)));
    })->with([
        'a matched record with a reason' => ['matched with reason', ['type' => 'matched', 'reason' => 'amount']],
        'a matched record without an invoice' => ['matched without invoice', ['type' => 'matched', 'reason' => null, 'invoice_id' => null]],
        'a duplicate without already_paid' => ['duplicate without reason', ['type' => 'duplicate', 'reason' => null]],
        'a duplicate with another reason' => ['duplicate other reason', ['type' => 'duplicate', 'reason' => 'amount']],
        'an unmatched record with already_paid' => ['unmatched already paid', ['type' => 'unmatched', 'reason' => 'already_paid']],
        'an unmatched record without a reason' => ['unmatched without reason', ['reason' => null]],
        'a refund with a reason' => ['refund with reason', ['type' => 'refund', 'reason' => 'amount', 'stripe_refund_id' => StripeEvents::token('re')]],
        'a refund without a refund id' => ['refund without id', ['type' => 'refund', 'reason' => null]],
        'a payment with a refund id' => ['payment with refund id', ['stripe_refund_id' => StripeEvents::token('re')]],
        'an amount of zero' => ['zero amount', ['amount_minor' => 0]],
        'a lower case currency' => ['bad currency', ['currency' => 'czk']],
        'only the offered amount' => ['offered amount only', ['offered_amount_minor' => 500]],
        'only the offered currency' => ['offered currency only', ['offered_currency' => 'CZK']],
        'an offered amount of zero' => ['offered zero', ['offered_amount_minor' => 0, 'offered_currency' => 'CZK']],
        'a resolution that is not known' => ['unknown resolution', ['resolution' => 'ignored']],
        'a type that is not known' => ['unknown type', ['type' => 'other']],
    ]);

    it('refuses a second record of the same event, session or payment intent, and of the same refund', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $refs = cardRefs();
        DB::table('card_payments')->insert(rawCardRow($invoice->id, $refs));

        RawSql::expectSqlState('23505', fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, ['stripe_event_id' => $refs['stripe_event_id']])));
        RawSql::expectSqlState('23505', fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, ['stripe_session_id' => $refs['stripe_session_id']])));
        RawSql::expectSqlState('23505', fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, ['stripe_payment_intent_id' => $refs['stripe_payment_intent_id']])));

        $refund = StripeEvents::token('re');
        DB::table('card_payments')->insert(rawCardRow($invoice->id, ['type' => 'refund', 'reason' => null, 'stripe_refund_id' => $refund, 'stripe_session_id' => null, 'stripe_payment_intent_id' => null]));

        RawSql::expectSqlState('23505', fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, ['type' => 'refund', 'reason' => null, 'stripe_refund_id' => $refund, 'stripe_session_id' => null, 'stripe_payment_intent_id' => null])));
    });

    it('accepts a refund that shares the payment intent of the payment it returns', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $refs = cardRefs();
        DB::table('card_payments')->insert(rawCardRow($invoice->id, $refs));

        RawSql::expectAllowed(fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, [
            'type' => 'refund',
            'reason' => null,
            'stripe_refund_id' => StripeEvents::token('re'),
            'stripe_session_id' => null,
            'stripe_payment_intent_id' => $refs['stripe_payment_intent_id'],
        ])));
    });

    it('lets two payments without a session or a payment intent coexist', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        DB::table('card_payments')->insert(rawCardRow($invoice->id, ['stripe_session_id' => null, 'stripe_payment_intent_id' => null]));

        RawSql::expectAllowed(fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, ['stripe_session_id' => null, 'stripe_payment_intent_id' => null])));
    });
});

describe('a record stays as Stripe reported it', function (): void {
    it('refuses to change what Stripe reported or what the record points at', function (string $column, mixed $value): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $other = CardPaymentSetup::issued(CardPaymentSetup::client());
        $id = storedCardRow($invoice->id);

        $value = $value instanceof Closure ? $value($other) : $value;

        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $id)->update([$column => $value]));
    })->with([
        'type' => ['type', 'duplicate'],
        'reason' => ['reason', 'currency'],
        'amount' => ['amount_minor', 2000],
        'currency' => ['currency', 'EUR'],
        'offered amount' => ['offered_amount_minor', 300],
        'offered currency' => ['offered_currency', 'EUR'],
        'event id' => ['stripe_event_id', fn () => StripeEvents::token('evt')],
        'session id' => ['stripe_session_id', fn () => StripeEvents::token('cs')],
        'payment intent' => ['stripe_payment_intent_id', fn () => StripeEvents::token('pi')],
        'refund id' => ['stripe_refund_id', fn () => StripeEvents::token('re')],
        'link id' => ['stripe_link_id', fn () => StripeEvents::token('plink')],
        'document' => ['invoice_id', fn (Invoice $other): string => $other->id],
        'receipt time' => ['received_at', fn () => now()->addDay()],
        'creation time' => ['created_at', fn () => now()->subDay()],
        'key' => ['id', fn () => (string) Str::uuid7()],
    ]);

    it('refuses to delete or truncate a record', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $id = storedCardRow($invoice->id);

        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $id)->delete());
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE card_payments'));

        expect(app(PartnerContext::class)->runAsSystem(static fn (): int => CardPayment::query()->whereKey($id)->count()))->toBe(1);
    });

    it('refuses to point a matched record at another payment, but lets the database clear it', function (): void {
        $first = CardPaymentSetup::issued(CardPaymentSetup::client());
        $second = CardPaymentSetup::issued(CardPaymentSetup::client());
        [, $record] = recordMatchedCardPayment($first);
        [$otherPayment] = recordMatchedCardPayment($second);

        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $record?->id)->update(['invoice_payment_id' => $otherPayment->id]));
        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $record?->id)->update(['invoice_payment_id' => null]));
    });

    it('accepts a duplicate resolved as returned, the expense being linked later', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $id = storedCardRow($invoice->id, ['type' => 'duplicate', 'reason' => 'already_paid']);

        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $id)->update([
            'resolution' => 'returned',
            'resolved_at' => now(),
            'resolved_by' => Canary::admin()->getKey(),
        ]));
    });
});

/**
 * A CZK payment booked through the card path whose record no longer points at it, so another record may take it: the
 * payment and the document it belongs to.
 *
 * @return array{0: Invoice, 1: InvoicePayment}
 */
function freedCzkPayment(): array
{
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
    [$payment, $record] = recordMatchedCardPayment($invoice);

    DB::table('card_payments')->where('id', $record?->id)->update(['invoice_payment_id' => null]);

    return [$invoice, $payment];
}

describe('a card payment record and a payment in another currency than it was paid in', function (): void {
    it('refuses to insert a EUR record that points at a CZK payment', function (): void {
        [$invoice, $payment] = freedCzkPayment();

        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->insert(rawCardRow($invoice->id, [
            'reason' => 'currency',
            'currency' => 'EUR',
            'invoice_payment_id' => $payment->id,
        ])));
    });

    it('refuses to fill the payment of a stored EUR record with a CZK payment', function (): void {
        [$invoice, $payment] = freedCzkPayment();
        $id = storedCardRow($invoice->id, ['reason' => 'currency', 'currency' => 'EUR']);

        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $id)->update(['invoice_payment_id' => $payment->id]));
    });

    it('lets a record take a payment in its own currency and lets the database clear the link again', function (): void {
        [$invoice, $payment] = freedCzkPayment();
        $id = storedCardRow($invoice->id, ['reason' => 'amount', 'currency' => 'CZK']);

        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $id)->update(['invoice_payment_id' => $payment->id]));
        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $id)->update(['invoice_payment_id' => null]));
    });

    it('lets an unrelated update of a record that points at a payment pass', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        [, $record] = recordMatchedCardPayment($invoice);

        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $record?->id)->update(['updated_at' => now()]));
    });
});

describe('a resolution is final', function (): void {
    it('accepts an unmatched record resolved by a note in one update', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $id = storedCardRow($invoice->id);

        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $id)->update(notedResolution()));
    });

    it('accepts a duplicate assigned to a document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $target = CardPaymentSetup::issued(CardPaymentSetup::client());
        $id = storedCardRow($invoice->id, ['type' => 'duplicate', 'reason' => 'already_paid']);

        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $id)->update([
            'resolution' => 'assigned',
            'assigned_invoice_id' => $target->id,
            'resolved_at' => now(),
            'resolved_by' => Canary::admin()->getKey(),
        ]));
    });

    it('refuses to reopen or change a resolution', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $target = CardPaymentSetup::issued(CardPaymentSetup::client());
        $noted = storedCardRow($invoice->id);
        DB::table('card_payments')->where('id', $noted)->update(notedResolution());

        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $noted)->update(['resolution' => 'none', 'resolved_at' => null, 'resolved_by' => null]));
        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $noted)->update(['resolution' => 'assigned', 'assigned_invoice_id' => $target->id]));
        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $noted)->update(['resolution_note' => 'Another note']));
        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $noted)->update(['resolved_at' => now()->addDay()]));

        $assigned = storedCardRow($invoice->id, ['type' => 'duplicate', 'reason' => 'already_paid']);
        DB::table('card_payments')->where('id', $assigned)->update([
            'resolution' => 'assigned',
            'assigned_invoice_id' => $target->id,
            'resolved_at' => now(),
            'resolved_by' => Canary::admin()->getKey(),
        ]);

        RawSql::expectSqlState('KP001', fn () => DB::table('card_payments')->where('id', $assigned)->update(['assigned_invoice_id' => $invoice->id]));
    });

    it('refuses an inconsistent resolution with a check violation', function (string $type, string $reason, array $update): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $target = CardPaymentSetup::issued(CardPaymentSetup::client());
        $refund = $type === 'refund';
        $matched = $type === 'matched';
        $id = $refund
            ? storedCardRow($invoice->id, ['type' => 'refund', 'reason' => null, 'stripe_refund_id' => StripeEvents::token('re'), 'stripe_session_id' => null, 'stripe_payment_intent_id' => null])
            : storedCardRow($invoice->id, ['type' => $type, 'reason' => $matched ? null : $reason]);

        $update = array_map(static fn (mixed $value): mixed => $value === '@target' ? $target->id : ($value === '@admin' ? Canary::admin()->getKey() : ($value === '@now' ? now() : $value)), $update);

        RawSql::expectSqlState('23514', fn () => DB::table('card_payments')->where('id', $id)->update($update));
    })->with([
        'noted without a note' => ['unmatched', 'amount', ['resolution' => 'noted', 'resolved_at' => '@now', 'resolved_by' => '@admin']],
        'noted with a blank note' => ['unmatched', 'amount', ['resolution' => 'noted', 'resolution_note' => '   ', 'resolved_at' => '@now', 'resolved_by' => '@admin']],
        'resolved without a time' => ['unmatched', 'amount', ['resolution' => 'noted', 'resolution_note' => 'Example note', 'resolved_by' => '@admin']],
        'resolved without a person' => ['unmatched', 'amount', ['resolution' => 'noted', 'resolution_note' => 'Example note', 'resolved_at' => '@now']],
        'a time without a resolution' => ['unmatched', 'amount', ['resolved_at' => '@now', 'resolved_by' => '@admin']],
        'assigned without a document' => ['unmatched', 'amount', ['resolution' => 'assigned', 'resolved_at' => '@now', 'resolved_by' => '@admin']],
        'a document without an assignment' => ['unmatched', 'amount', ['assigned_invoice_id' => '@target']],
        'a matched record with a resolution' => ['matched', '', ['resolution' => 'noted', 'resolution_note' => 'Example note', 'resolved_at' => '@now', 'resolved_by' => '@admin']],
        'a refund assigned' => ['refund', '', ['resolution' => 'assigned', 'assigned_invoice_id' => '@target', 'resolved_at' => '@now', 'resolved_by' => '@admin']],
        'a refund returned' => ['refund', '', ['resolution' => 'returned', 'resolved_at' => '@now', 'resolved_by' => '@admin']],
    ]);

    it('lets a refund be noted', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $id = storedCardRow($invoice->id, ['type' => 'refund', 'reason' => null, 'stripe_refund_id' => StripeEvents::token('re'), 'stripe_session_id' => null, 'stripe_payment_intent_id' => null]);

        RawSql::expectAllowed(fn () => DB::table('card_payments')->where('id', $id)->update(notedResolution()));
    });

    it('writes an allowlisted history when the Admin resolves a record, without a note or a Stripe reference', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $refs = cardRefs();
        $id = storedCardRow($invoice->id, $refs);

        $this->actingAs(Canary::admin());
        $record = CardPayment::query()->findOrFail($id);
        $record->forceFill([
            'resolution' => CardPaymentResolution::Noted,
            'resolution_note' => 'Example note that must stay out of the history',
            'resolved_at' => now(),
            'resolved_by' => Canary::admin()->getKey(),
        ])->save();

        $rows = Activity::query()->where('subject_type', 'card_payment')->where('subject_id', $id)->get();
        $json = (string) json_encode($rows->map(static fn (Activity $row): mixed => $row->attribute_changes)->all());

        expect($rows)->toHaveCount(1)
            ->and($json)->toContain('resolution')
            ->and($json)->not->toContain('resolution_note')
            ->and($json)->not->toContain('must stay out')
            ->and($json)->not->toContain($refs['stripe_event_id'])
            ->and($json)->not->toContain($refs['stripe_payment_intent_id'])
            ->and($record->fresh()?->status())->toBe(CardPaymentStatus::Resolved);
    });

    it('derives the status of a record that waits for the Admin', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $id = storedCardRow($invoice->id);

        $record = app(PartnerContext::class)->runAsSystem(static fn (): CardPayment => CardPayment::query()->findOrFail($id));

        expect($record->status())->toBe(CardPaymentStatus::Unresolved)
            ->and($record->isUnresolved())->toBeTrue()
            ->and($record->offered())->toBeNull();
    });
});
