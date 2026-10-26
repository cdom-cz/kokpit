<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Payments\Actions\DeactivatePaymentLink;
use App\Domain\Payments\Actions\RegeneratePaymentLink;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Enums\PayPageOutcome;
use App\Domain\Payments\Jobs\DeactivatePaymentLinks;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Queries\PayPageState;
use App\Domain\Payments\Support\PaymentLinks;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;
use Tests\Support\StripeTestConfig;

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

/**
 * Runs the sweep (or the targeted job) in this process through its middleware, as a worker does.
 */
function lifecycleSweep(?string $linkId = null): void
{
    app(Dispatcher::class)->dispatchNow(new DeactivatePaymentLinks($linkId));
}

describe('the sweep', function (): void {
    it('repairs a remote deactivation lost to a Stripe outage, once', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $this->stripe->failNextDeactivate('connection');

        lifecyclePay($invoice, '12500.00');

        $link = lifecycleLinks($invoice)->firstOrFail();

        expect($link->state)->toBe(PaymentLinkState::Deactivated)
            ->and($link->deactivation_reason)->toBe(PaymentLinkDeactivation::Paid)
            ->and($link->remote_deactivated_at)->toBeNull()
            ->and($this->stripe->deactivated())->toBe([]);

        lifecycleSweep();

        $link = lifecycleLinks($invoice)->firstOrFail();

        expect($link->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$link->stripe_link_id]);

        lifecycleSweep();

        expect($this->stripe->deactivated())->toBe([$link->stripe_link_id]);
    });

    it('logs the failure class and the row id only and leaves the row for the next sweep', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $this->stripe->failNextDeactivate('rate_limited');
        lifecyclePay($invoice, '12500.00');
        $this->stripe->failNextDeactivate('connection');

        Log::spy();

        lifecycleSweep();

        $link = lifecycleLinks($invoice)->firstOrFail();

        expect($link->remote_deactivated_at)->toBeNull();

        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => array_keys($context) === ['payment_link_id', 'failure_class']
            && $context['payment_link_id'] === $link->id
            && $context['failure_class'] === 'connection'
            && ! str_contains($message, (string) $link->stripe_link_id));
    });

    it('ignores a pending row, an active row and a deactivated row without a Stripe id', function (): void {
        $active = CardPaymentSetup::issued(CardPaymentSetup::client());

        Queue::fake();
        $pending = CardPaymentSetup::issued(CardPaymentSetup::client());
        $withoutId = CardPaymentSetup::issued(CardPaymentSetup::client());
        lifecyclePay($withoutId, '100.00');

        expect(lifecycleLinks($pending)->firstOrFail()->state)->toBe(PaymentLinkState::Pending)
            ->and(lifecycleLinks($withoutId)->firstOrFail()->state)->toBe(PaymentLinkState::Deactivated)
            ->and(lifecycleLinks($withoutId)->firstOrFail()->stripe_link_id)->toBeNull();

        lifecycleSweep();

        expect($this->stripe->deactivated())->toBe([])
            ->and(lifecycleLinks($active)->firstOrFail()->state)->toBe(PaymentLinkState::Active)
            ->and(lifecycleLinks($pending)->firstOrFail()->state)->toBe(PaymentLinkState::Pending)
            ->and(lifecycleLinks($withoutId)->firstOrFail()->remote_deactivated_at)->toBeNull();
    });

    it('takes only the named row when the job is queued for one link, and ignores a value that is no UUID', function (): void {
        $first = CardPaymentSetup::issued(CardPaymentSetup::client());
        $second = CardPaymentSetup::issued(CardPaymentSetup::client());
        $this->stripe->failNextDeactivate('connection');
        lifecyclePay($first, '12500.00');
        $this->stripe->failNextDeactivate('connection');
        lifecyclePay($second, '12500.00');

        lifecycleSweep('not-a-uuid');

        expect($this->stripe->deactivated())->toBe([]);

        lifecycleSweep(lifecycleLinks($second)->firstOrFail()->id);

        expect($this->stripe->deactivated())->toBe([lifecycleLinks($second)->firstOrFail()->stripe_link_id])
            ->and(lifecycleLinks($first)->firstOrFail()->remote_deactivated_at)->toBeNull();
    });

    it('is scheduled every five minutes on one server', function (): void {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => $event->description === 'kokpit-payment-link-sweep',
        ));

        expect($events)->toHaveCount(1)
            ->and($events[0]->onOneServer)->toBeTrue()
            ->and($events[0]->expression)->toBe('*/5 * * * *');
    });
});

/**
 * The row of the document as system, fresh from the database.
 */
function lifecycleRow(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * The Admin-only row of the document as system.
 */
function lifecycleAdminRow(Invoice $invoice): InvoiceAdmin
{
    return app(PartnerContext::class)->runAsSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->firstOrFail());
}

/**
 * The bytes of the stored PDF of the document.
 */
function lifecycleStoredPdf(Invoice $invoice): string
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($invoice): string {
        $documentId = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');
        $media = Document::query()->whereKey($documentId)->firstOrFail()->getFirstMedia(Document::MEDIA_COLLECTION);

        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    });
}

describe('a change of the payment method (Phase 14)', function (): void {
    /**
     * A card-on transfer invoice that was switched to online as the Admin, so it has a replaced and a live link.
     */
    function lifecycleOnline(): Invoice
    {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        app(UpdateIssuedDocument::class)->handle(test()->admin, $invoice, ['payment_method' => 'online']);

        return $invoice;
    }

    /**
     * An online invoice converted from a proforma paid in full while online payments were off: online, switch off, no
     * token, no link.
     */
    function lifecycleConvertedOnline(): Invoice
    {
        $admin = test()->admin;
        $draft = InvoiceFixtures::draft(CardPaymentSetup::client(), [InvoiceFixtures::item('Example advance', '1', '3900.00')], InvoiceKind::Proforma, $admin);
        $draft = InvoiceFixtures::online($draft);
        $proforma = app(IssueDocument::class)->handle($admin, $draft->refresh());

        lifecyclePay($proforma, '3900');
        CardPaymentSetup::onlinePayments(false);

        return app(ConvertProforma::class)->handle($admin, $proforma);
    }

    it('keeps the switch of an online invoice converted while card payment was unavailable off on an unrelated save, and turns it on once card payment works again', function (): void {
        $invoice = lifecycleConvertedOnline();

        expect(lifecycleRow($invoice)->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeFalse();

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['note' => 'Unrelated change.']);

        expect(lifecycleRow($invoice)->note)->toBe('Unrelated change.')
            ->and(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(lifecycleAdminRow($invoice)->pay_token)->toBeNull()
            ->and(lifecycleLinks($invoice))->toHaveCount(0);

        CardPaymentSetup::onlinePayments(true);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['note' => 'Second change.']);

        expect(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeTrue()
            ->and(PayToken::isWellFormed((string) lifecycleAdminRow($invoice)->pay_token))->toBeTrue()
            ->and(lifecycleLive($invoice))->toBeNull();
    });

    it('switches an open transfer invoice to online: no bank snapshot, the old link replaced, a new link on the same pay URL, the PDF replaced', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $old = lifecycleLive($invoice);
        $token = lifecycleAdminRow($invoice)->pay_token;
        $before = lifecycleStoredPdf($invoice);

        expect($old)->not->toBeNull()
            ->and($token)->not->toBeNull()
            ->and(lifecycleRow($invoice)->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and(lifecycleRow($invoice)->bank_snapshot)->not->toBeNull();

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'online']);

        $row = lifecycleRow($invoice);
        $links = lifecycleLinks($invoice);
        $live = lifecycleLive($invoice);

        expect($row->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and($row->bank_snapshot)->toBeNull()
            ->and(lifecycleAdminRow($invoice)->pay_token)->toBe($token)
            ->and(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeTrue()
            ->and($links)->toHaveCount(2)
            ->and($links[0]->state)->toBe(PaymentLinkState::Deactivated)
            ->and($links[0]->deactivation_reason)->toBe(PaymentLinkDeactivation::Replaced)
            ->and($links[0]->remote_deactivated_at)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$old->stripe_link_id])
            ->and($live)->not->toBeNull()
            ->and($live->id)->not->toBe($old->id)
            ->and($live->state)->toBe(PaymentLinkState::Active)
            ->and($live->currency)->toBe('CZK')
            ->and($live->amount_minor)->toBe(1_250_000)
            ->and($links->filter(static fn (PaymentLink $link): bool => $link->isLive()))->toHaveCount(1);

        $after = lifecycleStoredPdf($invoice);
        $text = PdfText::flat($after);

        expect($after)->not->toBe($before);

        if ($text !== null) {
            $iban = lifecycleIbanFragment();

            expect($text)->toContain('Zaplatit kartou')
                ->and(str_replace(' ', '', $text))->not->toContain($iban);
        }
    });

    it('gives a transfer invoice issued with the switch off a well-formed token and its first link when it goes online', function (): void {
        $client = CardPaymentSetup::client(['online_payment_enabled' => false]);
        $invoice = CardPaymentSetup::issued($client);

        expect(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(lifecycleAdminRow($invoice)->pay_token)->toBeNull()
            ->and(lifecycleLinks($invoice))->toHaveCount(0);

        app(PartnerContext::class)->runAsSystem(static fn () => $client->forceFill(['online_payment_enabled' => true])->save());

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'online']);

        $token = lifecycleAdminRow($invoice)->pay_token;
        $live = lifecycleLive($invoice);

        expect($token)->not->toBeNull()
            ->and(PayToken::isWellFormed((string) $token))->toBeTrue()
            ->and(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeTrue()
            ->and(lifecycleRow($invoice)->bank_snapshot)->toBeNull()
            ->and(lifecycleLinks($invoice))->toHaveCount(1)
            ->and($live)->not->toBeNull()
            ->and($live->amount_minor)->toBe(1_250_000);
    });

    it('goes back to transfer for a client with online payment on: the snapshot of the stored account, the switch on, the online link replaced and a new one opened', function (): void {
        $invoice = lifecycleOnline();
        $online = lifecycleLive($invoice);
        $token = lifecycleAdminRow($invoice)->pay_token;

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'transfer']);

        $row = lifecycleRow($invoice);
        $expected = app(PartnerContext::class)->runAsSystem(static fn (): array => app(BankAccountSettings::class)->firstForCurrency('CZK')?->toArray() ?? []);
        $links = lifecycleLinks($invoice);

        expect($row->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and($row->bank_snapshot)->toEqual($expected)
            ->and(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeTrue()
            ->and(lifecycleAdminRow($invoice)->pay_token)->toBe($token)
            ->and($links)->toHaveCount(3)
            ->and($links->firstWhere('id', $online->id)?->deactivation_reason)->toBe(PaymentLinkDeactivation::Replaced)
            ->and($links->filter(static fn (PaymentLink $link): bool => $link->isLive()))->toHaveCount(1)
            ->and(lifecycleLive($invoice)?->amount_minor)->toBe(1_250_000);
    });

    it('goes back to transfer for a client with online payment off: the switch off, the link replaced, no new link, no pay URL, the old URL answers "preparing"', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'online']);

        $token = (string) lifecycleAdminRow($invoice)->pay_token;
        app(PartnerContext::class)->runAsSystem(static fn () => $client->forceFill(['online_payment_enabled' => false])->save());

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'transfer']);

        expect(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(lifecycleAdminRow($invoice)->pay_token)->toBe($token)
            ->and(lifecycleLive($invoice))->toBeNull()
            ->and(lifecycleReasons($invoice))->toBe(['replaced', 'replaced'])
            ->and(PayUrl::forInvoice(lifecycleRow($invoice)))->toBeNull()
            ->and(app(PayPageState::class)->resolve($token)['outcome'])->toBe(PayPageOutcome::Preparing)
            ->and(lifecycleRow($invoice)->bank_snapshot)->not->toBeNull();
    });

    it('goes back to transfer while card payment is unavailable: the switch off, the online link replaced, no new link and no pay URL', function (string $cause): void {
        $invoice = lifecycleOnline();
        $token = (string) lifecycleAdminRow($invoice)->pay_token;

        match ($cause) {
            'settings_off' => CardPaymentSetup::onlinePayments(false),
            default => StripeTestConfig::clear(),
        };

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'transfer']);

        expect(lifecycleRow($invoice)->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and(lifecycleRow($invoice)->bank_snapshot)->not->toBeNull()
            ->and(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(lifecycleAdminRow($invoice)->pay_token)->toBe($token)
            ->and(lifecycleLive($invoice))->toBeNull()
            ->and(lifecycleReasons($invoice))->toBe(['replaced', 'replaced'])
            ->and(PayUrl::forInvoice(lifecycleRow($invoice)))->toBeNull();
    })->with(['settings_off', 'stripe_missing']);

    it('goes back to transfer with the switch sent off for a client whose flag is on: the switch off and no new link', function (): void {
        $invoice = lifecycleOnline();

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'transfer', 'card_payment_enabled' => false]);

        expect(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(lifecycleLive($invoice))->toBeNull()
            ->and(lifecycleReasons($invoice))->toBe(['replaced', 'replaced']);
    });

    it('turns the switch of a transfer document off as manual and on again with a new link, although the newest link was manual', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $first = lifecycleLive($invoice);
        $token = lifecycleAdminRow($invoice)->pay_token;

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['card_payment_enabled' => false]);

        expect(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(lifecycleLive($invoice))->toBeNull()
            ->and(lifecycleReasons($invoice))->toBe(['manual'])
            ->and($this->stripe->deactivated())->toBe([$first->stripe_link_id])
            ->and(PayUrl::forInvoice(lifecycleRow($invoice)))->toBeNull()
            ->and(lifecycleRow($invoice)->payment_method)->toBe(DocumentPaymentMethod::Transfer);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['card_payment_enabled' => true]);

        $live = lifecycleLive($invoice);

        expect(lifecycleAdminRow($invoice)->card_payment_enabled)->toBeTrue()
            ->and(lifecycleAdminRow($invoice)->pay_token)->toBe($token)
            ->and(lifecycleLinks($invoice))->toHaveCount(2)
            ->and($live)->not->toBeNull()
            ->and($live->id)->not->toBe($first->id)
            ->and($live->amount_minor)->toBe(1_250_000)
            ->and(lifecycleReasons($invoice))->toBe(['manual', null]);
    });

    it('opens no link when the switch is sent unchanged', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $first = lifecycleLive($invoice);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['card_payment_enabled' => true, 'note' => 'Only a note.']);

        expect(lifecycleLinks($invoice))->toHaveCount(1)
            ->and(lifecycleLive($invoice)?->id)->toBe($first->id);
    });
});

/**
 * The digits of the first configured CZK account IBAN without spaces, to look for in a printed text.
 */
function lifecycleIbanFragment(): string
{
    $account = app(PartnerContext::class)->runAsSystem(static fn (): ?string => app(BankAccountSettings::class)->firstForCurrency('CZK')?->iban);

    return str_replace(' ', '', (string) $account);
}
