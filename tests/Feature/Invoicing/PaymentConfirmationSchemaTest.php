<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The storage of the payment confirmation e-mail (Phase 13, P13-05, P13-11, D-05, A-13-6): one Admin-only row per
 * payment, removed with the payment, every malformed row refused by a named check. Every case starts from a payment
 * without a confirmation row (R-13-02-4), so the file holds before and after the trigger of plan 13-06 is wired.
 * Names, addresses and amounts are fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * An issued invoice with one recorded payment and no confirmation row.
 *
 * @return array{0: Invoice, 1: InvoicePayment}
 */
function payConfirmSchemaPayment(string $amount = '1000.00'): array
{
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
    $payment = app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);

    DB::table('payment_confirmations')->where('invoice_payment_id', $payment->id)->delete();

    return [$invoice, $payment];
}

/**
 * The columns of one valid sent row; overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payConfirmSchemaRow(Invoice $invoice, InvoicePayment $payment, array $overrides = []): array
{
    return [
        'invoice_payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'recipient' => exampleEmail('client-invoice'),
        'language' => 'cs',
        'remaining_minor' => 0,
        'state' => 'sent',
        'attempts' => 1,
        'sent_at' => '2026-10-14 10:05:00+00',
        'created_at' => '2026-10-14 10:00:00+00',
        'updated_at' => '2026-10-14 10:05:00+00',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $row
 */
function payConfirmSchemaInsert(array $row): void
{
    DB::table('payment_confirmations')->insert($row);
}

describe('the tracer (Phase 13)', function (): void {
    it('stores a confirmation of a recorded payment and the Admin reads it back', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        app(PartnerContext::class)->runAsSystem(static function () use ($invoice, $payment): void {
            (new PaymentConfirmation)->forceFill([
                'invoice_payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'recipient' => exampleEmail('client-invoice'),
                'language' => 'cs',
                'remaining_minor' => 0,
                'state' => PaymentConfirmationState::Sent,
                'attempts' => 1,
                'sent_at' => CarbonImmutable::now(),
            ])->save();
        });

        $row = PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->firstOrFail();

        expect($row->state)->toBe(PaymentConfirmationState::Sent)
            ->and($row->attempts)->toBe(1)
            ->and($row->remaining_minor)->toBe(0)
            ->and($row->sent_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and($row->payment->is($payment))->toBeTrue()
            ->and($row->invoice->is($invoice))->toBeTrue()
            ->and(Gate::forUser($this->admin)->allows('view', $row))->toBeTrue();
    });

    it('refuses a second row for the same payment with the unique key', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment));

        RawSql::expectSqlState('23505', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment)));

        expect(DB::table('payment_confirmations')->where('invoice_payment_id', $payment->id)->count())->toBe(1);

        try {
            DB::transaction(static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment)));
        } catch (Throwable $e) {
            expect($e->getMessage())->toContain('payment_confirmations_invoice_payment_unique');
        }
    });

    it('removes the row together with the deleted payment', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment));

        app(DeletePayment::class)->handle($this->admin, $payment);

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeFalse()
            ->and(DB::table('payment_confirmations')->where('invoice_payment_id', $payment->id)->exists())->toBeFalse();
    });

    it('shows a Partner of the same client no row and denies viewing it', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment));

        $row = app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->firstOrFail());
        $partner = Canary::partnerFor($invoice->client_id);

        $this->actingAs($partner);

        expect(PaymentConfirmation::query()->count())->toBe(0)
            ->and(PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->exists())->toBeFalse()
            ->and(Gate::forUser($partner)->denies('view', $row))->toBeTrue();
    });
});

describe('the contract (Phase 13)', function (): void {
    it('refuses a row with an unknown state', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['state' => 'delivered'])));
    });

    it('refuses a language other than cs and en', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['language' => 'de'])));
    });

    it('refuses a negative remaining amount', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['remaining_minor' => -1])));
    });

    it('refuses negative attempts', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['attempts' => -1])));
    });

    it('ties sent_at to the sent state in both directions', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['sent_at' => null])));
        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['state' => 'queued', 'sent_at' => '2026-10-14 10:05:00+00'])));
    });

    it('allows a failure code only on a failed row', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['state' => 'queued', 'sent_at' => null, 'failure_code' => 'smtp'])));
        RawSql::expectAllowed(static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['state' => 'failed', 'sent_at' => null, 'failure_code' => 'smtp'])));
    });

    it('keeps the recipient null exactly when the row is skipped and never blank', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        $skipped = ['state' => 'skipped', 'sent_at' => null, 'attempts' => 0];

        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, [...$skipped, 'recipient' => exampleEmail('client-invoice')])));
        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['state' => 'queued', 'sent_at' => null, 'recipient' => null])));
        RawSql::expectSqlState('23514', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['recipient' => '   '])));
        RawSql::expectAllowed(static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, [...$skipped, 'recipient' => null])));
    });

    it('refuses a row for a payment that does not exist', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        RawSql::expectSqlState('23503', static fn () => payConfirmSchemaInsert(payConfirmSchemaRow($invoice, $payment, ['invoice_payment_id' => '00000000-0000-7000-8000-000000000000'])));
    });

    it('starts a row with only the required columns as queued with zero attempts', function (): void {
        [$invoice, $payment] = payConfirmSchemaPayment();

        payConfirmSchemaInsert([
            'invoice_payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'recipient' => exampleEmail('client-invoice'),
            'language' => 'en',
            'remaining_minor' => 5000,
        ]);

        $row = DB::table('payment_confirmations')->where('invoice_payment_id', $payment->id)->first();

        expect($row)->not->toBeNull()
            ->and($row->state)->toBe('queued')
            ->and($row->attempts)->toBe(0)
            ->and($row->sent_at)->toBeNull()
            ->and($row->failure_code)->toBeNull();
    });
});
