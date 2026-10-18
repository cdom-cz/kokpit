<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Payments\Actions\DeactivatePaymentLink;
use App\Domain\Payments\Actions\RegeneratePaymentLink;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PaymentLinks;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * The link follows what the document owes (PA-04; D-07, D-08, research Pattern 3): every route that changes the
 * remaining amount deactivates the live link in its own transaction and then at Stripe, the Admin regenerates a link
 * for the rest or switches one off by hand, and a sweep repairs a remote deactivation that was lost. The gateway is
 * the in-memory fake; every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Every link row of the document, oldest first, read as a system run.
 *
 * @return Collection<int, PaymentLink>
 */
function lifecycleLinks(Invoice $invoice): Collection
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Collection => PaymentLink::query()->where('invoice_id', $invoice->id)->orderBy('created_at')->orderBy('id')->get());
}

/**
 * The one live link of the document, or null.
 */
function lifecycleLive(Invoice $invoice): ?PaymentLink
{
    return lifecycleLinks($invoice)->first(static fn (PaymentLink $link): bool => $link->isLive());
}

/**
 * A payment of the amount through the manual path, as the Admin.
 */
function lifecyclePay(Invoice $invoice, string $amount): InvoicePayment
{
    return app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);
}

/**
 * An issued card-on invoice of two lines, 5 000,00 and 7 500,00.
 */
function lifecycleTwoLines(): Invoice
{
    return InvoiceFixtures::issued(CardPaymentSetup::client(), [InvoiceFixtures::item('Example work', '1', '5000.00'), InvoiceFixtures::item('Example support', '1', '7500.00')]);
}

/**
 * The first line of the invoice, as system.
 */
function lifecycleFirstLine(Invoice $invoice): InvoiceItem
{
    return app(PartnerContext::class)->runAsSystem(static fn (): InvoiceItem => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->firstOrFail());
}

/**
 * The reasons of all link rows of the document, oldest first.
 *
 * @return list<string|null>
 */
function lifecycleReasons(Invoice $invoice): array
{
    return lifecycleLinks($invoice)->map(static fn (PaymentLink $link): ?string => $link->deactivation_reason?->value)->values()->all();
}

/**
 * Runs the callback with no open transaction, the way a request starts, and restores the test's wrapping one.
 */
function lifecycleOutsideTransaction(Closure $callback): void
{
    $level = DB::transactionLevel();

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        $callback();
    } finally {
        while (DB::transactionLevel() < $level) {
            DB::beginTransaction();
        }
    }
}

describe('the tracer', function (): void {
    it('switches the link off at once after a partial payment, at Stripe too, and regenerates it for the rest', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $first = lifecycleLive($invoice);

        expect($first)->not->toBeNull()
            ->and($first->state)->toBe(PaymentLinkState::Active)
            ->and($first->amount_minor)->toBe(1250000);

        lifecyclePay($invoice, '5000.00');

        $first = lifecycleLinks($invoice)->firstOrFail();

        expect($first->state)->toBe(PaymentLinkState::Deactivated)
            ->and($first->deactivation_reason)->toBe(PaymentLinkDeactivation::AmountChanged)
            ->and($first->deactivated_at)->not->toBeNull()
            ->and($first->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$first->stripe_link_id])
            ->and(lifecycleLive($invoice))->toBeNull();

        $new = app(RegeneratePaymentLink::class)->handle($this->admin, $invoice);

        $links = lifecycleLinks($invoice);
        $live = lifecycleLive($invoice);

        expect($links)->toHaveCount(2)
            ->and($live)->not->toBeNull()
            ->and($live->id)->toBe($new->id)
            ->and($live->state)->toBe(PaymentLinkState::Active)
            ->and($live->amount_minor)->toBe(750000);

        $created = $this->stripe->created();

        expect($created)->toHaveCount(2)
            ->and($created[1]->amountMinor)->toBe(750000)
            ->and($created[1]->idempotencyKey)->toBe($new->id);
    });
});

describe('every route that changes what is owed', function (): void {
    it('deactivates the link with the reason paid after a full payment, at Stripe too', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $link = lifecycleLive($invoice);

        lifecyclePay($invoice, '12500.00');

        $link = lifecycleLinks($invoice)->firstOrFail();

        expect($link->state)->toBe(PaymentLinkState::Deactivated)
            ->and($link->deactivation_reason)->toBe(PaymentLinkDeactivation::Paid)
            ->and($link->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$link->stripe_link_id]);
    });

    it('deactivates the link with the reason cancelled after a full storno, at Stripe too', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        $link = lifecycleLinks($invoice)->firstOrFail();

        expect($link->state)->toBe(PaymentLinkState::Deactivated)
            ->and($link->deactivation_reason)->toBe(PaymentLinkDeactivation::Cancelled)
            ->and($link->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$link->stripe_link_id]);
    });

    it('deactivates the link with the reason amount_changed after a partial credit note of one line', function (): void {
        $invoice = lifecycleTwoLines();

        app(IssueCreditNote::class)->partial($this->admin, $invoice, [lifecycleFirstLine($invoice)->id => '1']);

        $link = lifecycleLinks($invoice)->firstOrFail();

        expect($link->state)->toBe(PaymentLinkState::Deactivated)
            ->and($link->deactivation_reason)->toBe(PaymentLinkDeactivation::AmountChanged)
            ->and($link->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$link->stripe_link_id]);

        $new = app(RegeneratePaymentLink::class)->handle($this->admin, $invoice);

        expect($new->amount_minor)->toBe(750000);
    });

    it('deactivates the regenerated link when the earlier payment is deleted and the remaining amount rises', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $payment = lifecyclePay($invoice, '5000.00');
        $regenerated = app(RegeneratePaymentLink::class)->handle($this->admin, $invoice);

        expect($regenerated->amount_minor)->toBe(750000)
            ->and(lifecycleLive($invoice)?->id)->toBe($regenerated->id);

        app(DeletePayment::class)->handle($this->admin, $payment);

        $links = lifecycleLinks($invoice);
        $last = $links->last();

        expect($links)->toHaveCount(2)
            ->and(lifecycleLive($invoice))->toBeNull()
            ->and($last?->id)->toBe($regenerated->id)
            ->and($last->deactivation_reason)->toBe(PaymentLinkDeactivation::AmountChanged)
            ->and($last->remote_deactivated_at)->not->toBeNull()
            ->and($last->stripe_link_id)->not->toBeNull()
            ->and($this->stripe->deactivated())->toContain($last->stripe_link_id);
    });

    it('leaves the link alone when the remaining amount still equals the offered one', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $before = lifecycleLive($invoice);

        // A document without a payment changes nothing, so the check finds the link still honest.
        DB::transaction(function () use ($invoice): void {
            app(PaymentLinks::class)->reconcile(Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail());
        });

        expect(lifecycleLive($invoice)?->id)->toBe($before?->id)
            ->and($this->stripe->deactivated())->toBe([]);
    });

    it('refuses the reconcile check outside a transaction', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        lifecycleOutsideTransaction(function () use ($invoice): void {
            expect(fn () => app(PaymentLinks::class)->reconcile($invoice))->toThrow(LogicException::class);
        });
    });

    it('does not touch a document without a link', function (): void {
        $client = InvoiceFixtures::client(['online_payment_enabled' => false]);
        $invoice = InvoiceFixtures::issued($client);

        lifecyclePay($invoice, '100.00');

        expect(lifecycleLinks($invoice))->toHaveCount(0);
    });
});

describe('regenerating a link', function (): void {
    it('replaces an active link, the old one deactivated first and never two live at once', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $old = lifecycleLive($invoice);

        $new = app(RegeneratePaymentLink::class)->handle($this->admin, $invoice);

        $links = lifecycleLinks($invoice);
        $oldNow = $links->firstWhere('id', $old?->id);

        expect($links)->toHaveCount(2)
            ->and($oldNow?->state)->toBe(PaymentLinkState::Deactivated)
            ->and($oldNow->deactivation_reason)->toBe(PaymentLinkDeactivation::Replaced)
            ->and($oldNow->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$old?->stripe_link_id])
            ->and($links->filter(static fn (PaymentLink $link): bool => $link->isLive())->count())->toBe(1)
            ->and(lifecycleLive($invoice)?->id)->toBe($new->id)
            ->and($new->amount_minor)->toBe(1250000);
    });

    it('creates the first link of a document whose earlier attempt failed', function (): void {
        FakeStripeGateway::install()->failNextCreate('api_error');
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        expect(lifecycleLinks($invoice)->firstOrFail()->state)->toBe(PaymentLinkState::Failed);

        $new = app(RegeneratePaymentLink::class)->handle($this->admin, $invoice);

        expect(lifecycleLive($invoice)?->id)->toBe($new->id)
            ->and(lifecycleLive($invoice)->state)->toBe(PaymentLinkState::Active);
    });

    it('is refused on a paid document, nothing changes', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        lifecyclePay($invoice, '12500.00');

        expect(fn () => app(RegeneratePaymentLink::class)->handle($this->admin, $invoice))
            ->toThrow(fn (ValidationException $e) => expect($e->errors()['payment_link'][0])->toBe(__('payment_links.errors.not_regenerable')));
        expect(lifecycleLinks($invoice))->toHaveCount(1)
            ->and($this->stripe->created())->toHaveCount(1);
    });

    it('is refused on a cancelled document', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        expect(fn () => app(RegeneratePaymentLink::class)->handle($this->admin, $invoice))->toThrow(ValidationException::class);
        expect(lifecycleLinks($invoice))->toHaveCount(1);
    });

    it('is refused on a document issued with the card switch off', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['online_payment_enabled' => false]));

        expect(fn () => app(RegeneratePaymentLink::class)->handle($this->admin, $invoice))->toThrow(ValidationException::class);
        expect(lifecycleLinks($invoice))->toHaveCount(0);
    });

    it('is refused on a credit note', function (): void {
        $invoice = lifecycleTwoLines();
        $credit = app(IssueCreditNote::class)->partial($this->admin, $invoice, [lifecycleFirstLine($invoice)->id => '1']);

        expect($credit->kind)->toBe(InvoiceKind::CreditNote)
            ->and(fn () => app(RegeneratePaymentLink::class)->handle($this->admin, $credit))->toThrow(ValidationException::class);
        expect(lifecycleLinks($credit))->toHaveCount(0);
    });

    it('is refused while the live link is still being prepared', function (): void {
        Queue::fake();
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        expect(lifecycleLive($invoice)?->state)->toBe(PaymentLinkState::Pending)
            ->and(fn () => app(RegeneratePaymentLink::class)->handle($this->admin, $invoice))
            ->toThrow(fn (ValidationException $e) => expect($e->errors()['payment_link'][0])->toBe(__('payment_links.errors.preparing')));
        expect(lifecycleLinks($invoice))->toHaveCount(1);
    });

    it('regenerates a proforma link as well', function (): void {
        $proforma = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);
        lifecyclePay($proforma, '2500.00');

        $new = app(RegeneratePaymentLink::class)->handle($this->admin, $proforma);

        expect($new->amount_minor)->toBe(1000000)
            ->and(lifecycleLive($proforma)?->id)->toBe($new->id)
            ->and(lifecycleReasons($proforma))->toBe(['amount_changed', null]);
    });
});

describe('deactivating a link by hand', function (): void {
    it('switches an active link off with the reason manual, at Stripe too, and the document can be regenerated after', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $link = lifecycleLive($invoice);

        app(DeactivatePaymentLink::class)->handle($this->admin, $invoice);

        $link = lifecycleLinks($invoice)->firstOrFail();

        expect($link->state)->toBe(PaymentLinkState::Deactivated)
            ->and($link->deactivation_reason)->toBe(PaymentLinkDeactivation::Manual)
            ->and($link->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$link->stripe_link_id])
            ->and(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();

        $new = app(RegeneratePaymentLink::class)->handle($this->admin, $invoice);

        expect(lifecycleLive($invoice)?->id)->toBe($new->id);
    });

    it('refuses a document without an active link', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        app(DeactivatePaymentLink::class)->handle($this->admin, $invoice);

        expect(fn () => app(DeactivatePaymentLink::class)->handle($this->admin, $invoice))
            ->toThrow(fn (ValidationException $e) => expect($e->errors()['payment_link'][0])->toBe(__('payment_links.errors.no_active_link')));
        expect(lifecycleLinks($invoice))->toHaveCount(1);
    });
});

describe('a Partner', function (): void {
    it('can neither regenerate nor deactivate a link, and nothing changes', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);
        $before = lifecycleLive($invoice);
        $this->actingAs(Canary::partnerFor($client->id));
        $partner = Canary::partnerFor($client->id);

        expect(fn () => app(RegeneratePaymentLink::class)->handle($partner, $invoice))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeactivatePaymentLink::class)->handle($partner, $invoice))->toThrow(AuthorizationException::class);

        $links = lifecycleLinks($invoice);

        expect($links)->toHaveCount(1)
            ->and(lifecycleLive($invoice)?->id)->toBe($before?->id)
            ->and($this->stripe->deactivated())->toBe([]);
    });
});
