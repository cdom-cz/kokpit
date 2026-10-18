<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The record of the Stripe payment link of a document (PA-01, PA-04; D-05 to D-08, research Pitfall 13). Every name
 * and amount is fictional; Stripe references are assembled at runtime and never written down in a test.
 */

beforeEach(function (): void {
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    Queue::fake();
});

/**
 * A card-on document as it stood before the link job existed: issuing now opens its pending link, so the row is moved
 * to its final state `deactivated` (reason manual), which leaves the document with no live link for a test that opens
 * or inserts its own. The queue is faked, so no Stripe call happens.
 */
function issuedWithoutLink(Client $client, InvoiceKind $kind = InvoiceKind::Invoice, string $amount = '12500.00'): Invoice
{
    $invoice = CardPaymentSetup::issued($client, $kind, $amount);

    DB::table('payment_links')->where('invoice_id', $invoice->id)->update([
        'state' => 'deactivated',
        'deactivation_reason' => 'manual',
        'deactivated_at' => now(),
    ]);

    return $invoice;
}

/**
 * Opens a pending link for the document as system, the way the link job will: in one transaction, the invoice locked.
 */
function openLinkFor(Invoice $invoice, ?int $amountMinor = null): PaymentLink
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => DB::transaction(static function () use ($invoice, $amountMinor): PaymentLink {
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        return PaymentLink::open($locked, $amountMinor ?? $locked->total_minor, Canary::admin());
    }));
}

describe('the tracer', function (): void {
    it('opens a pending link for the full amount of a card-on invoice and shows it in the Admin history', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = issuedWithoutLink($client);

        $link = openLinkFor($invoice);

        expect($link->state)->toBe(PaymentLinkState::Pending)
            ->and($link->currency)->toBe($invoice->currency)
            ->and($link->amount_minor)->toBe($invoice->total_minor)
            ->and($link->amount()->minor)->toBe($invoice->total_minor)
            ->and($link->created_by)->not->toBeNull()
            ->and($link->deactivation_reason)->toBeNull()
            ->and($link->isLive())->toBeTrue();

        $latest = app(PartnerContext::class)->runAsSystem(static fn (): ?PaymentLink => PaymentLink::latestFor($invoice->id));
        expect($latest?->getKey())->toBe($link->getKey());

        $rows = Activity::query()->where('subject_type', 'payment_link')->where('subject_id', $link->id)->get();
        $json = (string) json_encode($rows->map(static fn (Activity $row): mixed => $row->attribute_changes)->all());

        expect($rows)->toHaveCount(1)
            ->and($json)->toContain('amount_minor')
            ->and($json)->toContain('state')
            ->and($json)->not->toContain('stripe_link_id')
            ->and($json)->not->toContain('stripe_url');
    });

    it('shows a Partner of the same client no link row and refuses the policy', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = issuedWithoutLink($client);
        $link = openLinkFor($invoice);

        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(PaymentLink::query()->count())->toBe(0)
            ->and(PaymentLink::query()->find($link->id))->toBeNull()
            ->and($partner->can('view', $link))->toBeFalse()
            ->and($partner->can('viewAny', PaymentLink::class))->toBeFalse();
    });

    it('refuses to open a link outside a transaction', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = issuedWithoutLink($client);

        // RefreshDatabase wraps the test in a transaction; leave it to see the guard.
        $level = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        try {
            expect(fn () => PaymentLink::open($invoice, $invoice->total_minor, null))->toThrow(LogicException::class);
        } finally {
            while (DB::transactionLevel() < $level) {
                DB::beginTransaction();
            }
        }
    });

    it('carries a label for every state and every deactivation reason', function (): void {
        foreach (PaymentLinkState::cases() as $state) {
            expect($state->getLabel())->not->toBe('enums.payment_link_state.'.$state->value);
        }
        foreach (PaymentLinkDeactivation::cases() as $reason) {
            expect($reason->getLabel())->not->toBe('enums.payment_link_deactivation.'.$reason->value);
        }

        expect(PaymentLinkState::Failed->getLabel())->toBe('Vytvoření selhalo')
            ->and(PaymentLinkDeactivation::Replaced->getLabel())->toBe('Nahrazen novým odkazem.');
    });
});

/**
 * Inserts a link row with plain SQL and returns its id, so a test can put the table into any state the guards allow.
 *
 * @param  array<string, mixed>  $overrides
 */
function rawLink(Invoice $invoice, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('payment_links')->insert([
        'id' => $id,
        'invoice_id' => $invoice->id,
        'currency' => $invoice->currency,
        'amount_minor' => $invoice->total_minor,
        'state' => 'pending',
        'created_by' => Canary::admin()->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

/**
 * A Stripe-shaped reference assembled at runtime, so no line of a test looks like a real value.
 */
function fakeStripeId(): string
{
    return implode('_', ['plink', Str::random(14)]);
}

function fakeStripeUrl(string $host = 'buy.stripe.com'): string
{
    return 'https://'.$host.'/'.Str::random(12);
}

describe('one live link per document (D-08)', function (): void {
    it('refuses a second pending or active link and accepts a new one after the first is deactivated', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());
        $first = openLinkFor($invoice);

        RawSql::expectSqlState('23505', fn () => openLinkFor($invoice));

        DB::table('payment_links')->where('id', $first->id)->update([
            'state' => 'active',
            'stripe_link_id' => fakeStripeId(),
            'stripe_url' => fakeStripeUrl(),
            'activated_at' => now(),
        ]);

        RawSql::expectSqlState('23505', fn () => openLinkFor($invoice));

        DB::table('payment_links')->where('id', $first->id)->update([
            'state' => 'deactivated',
            'deactivation_reason' => 'replaced',
            'deactivated_at' => now(),
        ]);

        $second = openLinkFor($invoice);

        expect($second->isLive())->toBeTrue()
            ->and(PaymentLink::latestFor($invoice->id)?->getKey())->toBe($second->getKey())
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => PaymentLink::query()->live()->count()))->toBe(1);
    });

    it('puts the live link first and otherwise the newest in latestFor', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());
        $old = rawLink($invoice, ['state' => 'failed', 'failure_class' => 'rejected']);
        $newer = rawLink($invoice, ['state' => 'deactivated', 'deactivation_reason' => 'manual', 'deactivated_at' => now()]);

        $latest = app(PartnerContext::class)->runAsSystem(static fn (): ?PaymentLink => PaymentLink::latestFor($invoice->id));
        expect($latest?->getKey())->toBe($newer)->and($latest?->getKey())->not->toBe($old);

        $live = rawLink($invoice);
        $latest = app(PartnerContext::class)->runAsSystem(static fn (): ?PaymentLink => PaymentLink::latestFor($invoice->id));
        expect($latest?->getKey())->toBe($live);
    });
});

describe('what may be stored (D-05, D-06)', function (): void {
    it('refuses a link for a draft, a credit note and a document whose switch was frozen off', function (): void {
        $client = CardPaymentSetup::client();

        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '1000.00')]);
        RawSql::expectSqlState('KP001', fn () => rawLink($draft));

        $issued = issuedWithoutLink($client);
        $creditNote = InvoiceFixtures::stornoed($issued);
        RawSql::expectSqlState('KP001', fn () => rawLink($creditNote));

        $off = issuedWithoutLink(InvoiceFixtures::client(['online_payment_enabled' => false]));
        expect(DB::table('invoice_admin')->where('invoice_id', $off->id)->value('card_payment_enabled'))->toBeFalse();
        RawSql::expectSqlState('KP001', fn () => rawLink($off));
    });

    it('refuses a link in another currency than the document and a non-positive amount', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());

        RawSql::expectSqlState('23503', fn () => rawLink($invoice, ['currency' => 'EUR']));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['amount_minor' => 0]));
    });
});

describe('the shape of a row', function (): void {
    it('refuses an active row without the Stripe id or URL, and an URL that is not https on a stripe.com host', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());

        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'active', 'activated_at' => now()]));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'active', 'stripe_link_id' => fakeStripeId(), 'activated_at' => now()]));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'active', 'stripe_link_id' => fakeStripeId(), 'stripe_url' => 'http://buy.stripe.com/'.Str::random(8)]));

        foreach (['example.com', 'stripe.com.example.net', 'notstripe.com', 'example.com/stripe.com'] as $host) {
            RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'active', 'stripe_link_id' => fakeStripeId(), 'stripe_url' => 'https://'.$host.'/'.Str::random(8)]));
        }

        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['stripe_url' => 'https://user@example.com/stripe.com/']));

        // A subdomain of stripe.com and the bare domain are both accepted (a record is never deleted, so each goes
        // to its own document).
        foreach (['buy.stripe.com', 'stripe.com'] as $host) {
            $document = issuedWithoutLink(CardPaymentSetup::client());
            RawSql::expectAllowed(fn () => rawLink($document, ['state' => 'active', 'stripe_link_id' => fakeStripeId(), 'stripe_url' => fakeStripeUrl($host), 'activated_at' => now()]));
        }
    });

    it('refuses a deactivated row without a reason, a failed row without a class, a reason or class on another state and an unknown value', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());

        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'deactivated', 'deactivated_at' => now()]));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'failed']));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'pending', 'deactivation_reason' => 'manual']));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'pending', 'failure_class' => 'rejected']));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'deactivated', 'deactivation_reason' => 'other']));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['state' => 'archived']));
        RawSql::expectSqlState('23514', fn () => rawLink($invoice, ['remote_deactivated_at' => now()]));
    });
});

describe('the guard on a stored row (D-07, D-08)', function (): void {
    it('refuses a change of the document, currency, amount, creator or creation time', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = issuedWithoutLink($client);
        $other = issuedWithoutLink($client, amount: '900.00');
        $id = rawLink($invoice);

        foreach ([
            ['invoice_id' => $other->id],
            ['currency' => 'EUR'],
            ['amount_minor' => $invoice->total_minor - 1],
            ['created_by' => null],
            ['created_at' => now()->subDay()],
        ] as $change) {
            RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update($change));
        }
    });

    it('accepts the allowed state moves and refuses the others', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());

        $toActive = rawLink($invoice);
        RawSql::expectAllowed(fn () => DB::table('payment_links')->where('id', $toActive)->update(['state' => 'active', 'stripe_link_id' => fakeStripeId(), 'stripe_url' => fakeStripeUrl(), 'activated_at' => now()]));
        RawSql::expectAllowed(fn () => DB::table('payment_links')->where('id', $toActive)->update(['state' => 'deactivated', 'deactivation_reason' => 'paid', 'deactivated_at' => now()]));

        $toFailed = rawLink($invoice);
        RawSql::expectAllowed(fn () => DB::table('payment_links')->where('id', $toFailed)->update(['state' => 'failed', 'failure_class' => 'rejected']));

        $toDeactivated = rawLink($invoice);
        RawSql::expectAllowed(fn () => DB::table('payment_links')->where('id', $toDeactivated)->update(['state' => 'deactivated', 'deactivation_reason' => 'cancelled', 'deactivated_at' => now()]));

        // Final states never reopen.
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $toActive)->update(['state' => 'active', 'deactivation_reason' => null, 'activated_at' => now()]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $toFailed)->update(['state' => 'pending', 'failure_class' => null]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $toDeactivated)->update(['state' => 'pending', 'deactivation_reason' => null]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $toFailed)->update(['state' => 'deactivated', 'failure_class' => null, 'deactivation_reason' => 'manual', 'deactivated_at' => now()]));

        // An active link never goes back to pending or to failed.
        $active = rawLink(issuedWithoutLink(CardPaymentSetup::client()), ['state' => 'active', 'stripe_link_id' => fakeStripeId(), 'stripe_url' => fakeStripeUrl(), 'activated_at' => now()]);
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $active)->update(['state' => 'pending']));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $active)->update(['state' => 'failed', 'failure_class' => 'rejected']));
    });

    it('refuses to delete a link', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());
        $id = rawLink($invoice);

        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->delete());
        expect(DB::table('payment_links')->where('id', $id)->exists())->toBeTrue();
    });

    it('lets a final row receive its Stripe references and the remote stamp once and nothing else', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());
        $id = rawLink($invoice, ['state' => 'deactivated', 'deactivation_reason' => 'replaced', 'deactivated_at' => now()]);
        $linkId = fakeStripeId();

        RawSql::expectAllowed(fn () => DB::table('payment_links')->where('id', $id)->update(['stripe_link_id' => $linkId, 'stripe_url' => fakeStripeUrl(), 'updated_at' => now()]));
        RawSql::expectAllowed(fn () => DB::table('payment_links')->where('id', $id)->update(['remote_deactivated_at' => now(), 'updated_at' => now()]));

        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['stripe_link_id' => fakeStripeId()]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['stripe_link_id' => null]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['stripe_url' => fakeStripeUrl()]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['remote_deactivated_at' => now()->addDay()]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['remote_deactivated_at' => null]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['deactivation_reason' => 'manual']));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['deactivated_at' => now()->addDay()]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['activated_at' => now()]));

        expect(DB::table('payment_links')->where('id', $id)->value('stripe_link_id'))->toBe($linkId);

        $failed = rawLink($invoice, ['state' => 'failed', 'failure_class' => 'rejected']);
        RawSql::expectAllowed(fn () => DB::table('payment_links')->where('id', $failed)->update(['stripe_link_id' => fakeStripeId(), 'stripe_url' => fakeStripeUrl()]));
        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $failed)->update(['failure_class' => 'timeout']));
    });

    it('keeps the Stripe id of an active link fixed once set', function (): void {
        $invoice = issuedWithoutLink(CardPaymentSetup::client());
        $id = rawLink($invoice, ['state' => 'active', 'stripe_link_id' => fakeStripeId(), 'stripe_url' => fakeStripeUrl(), 'activated_at' => now()]);

        RawSql::expectSqlState('KP001', fn () => DB::table('payment_links')->where('id', $id)->update(['stripe_link_id' => fakeStripeId()]));
    });

    it('refuses TRUNCATE', function (): void {
        // card_payments points at payment_links, so PostgreSQL wants both tables named; the guard of either refuses.
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE payment_links, card_payments'));
    });
});
