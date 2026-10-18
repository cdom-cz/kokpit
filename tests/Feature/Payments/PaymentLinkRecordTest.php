<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\InvoiceFixtures;

/*
 * The record of the Stripe payment link of a document (PA-01, PA-04; D-05 to D-08, research Pitfall 13). Every name
 * and amount is fictional; Stripe references are assembled at runtime and never written down in a test.
 */

beforeEach(function (): void {
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
});

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
        $invoice = CardPaymentSetup::issued($client);

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
        $invoice = CardPaymentSetup::issued($client);
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
        $invoice = CardPaymentSetup::issued($client);

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
