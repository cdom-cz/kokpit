<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RegeneratePaymentLink;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
function lifecyclePay(Invoice $invoice, string $amount): void
{
    app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);
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
