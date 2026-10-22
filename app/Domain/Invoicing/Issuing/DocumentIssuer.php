<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Issuing;

use App\Domain\Documents\Actions\StoreGeneratedDocument;
use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Invoicing\Support\DraftPreview;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\VariableSymbol;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\CardPaymentSwitch;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use OverflowException;

/**
 * The issue pipeline shared by IssueDocument, ConvertProforma and IssueCreditNote (research Pattern 3, ruling
 * R-12-2): everything that turns a locked draft into a numbered, frozen document. The caller holds the transaction
 * and the row lock of the draft; a failure anywhere throws, the caller's transaction rolls back and the number
 * allocated on the way goes back with it (D-10, D-13).
 *
 * The order is deliberate. The reads and the Admin-only rows come first, the number is allocated late so that the
 * counter row stays locked for as short a part of the transaction as possible, and the flip of the state is the
 * last write on the `invoices` row: the steps that follow write only time entries, `invoice_admin` and documents.
 * The flip also sets the lock (IN-11; D-01, D-12): an issued invoice or proforma is left open, so it stays
 * correctable until a successfully sent e-mail locks it, and a credit note is locked by its own issue and never
 * opened again. The number and the kind never change after the flip, open or locked.
 *
 * The lines generated from time in another currency than the document are converted before anything else is written:
 * the CNB lists of the issue date price each of them once (ruling R-17-1) and the totals follow the stored amounts
 * (D-09, D-12, IN-07). Their rate and the CZK total of the document are frozen from the same lists afterwards.
 *
 * The billing of the time comes right after the flip, because the link guard of `time_entries` bills an entry only
 * when its document is already issued (plan 10-06). Every entry the draft holds is locked in id order and must still
 * be exactly what the draft reserved it as; one that is not fails the whole issue with `entries_changed`, and the
 * rollback takes the number, the state and every billed entry back (D-04, D-10, D-15).
 *
 * The PDF is the last step (D-21, D-23): it is rendered from the frozen snapshots of the issued row, stored through the
 * hardened upload path as a document owned by the invoice, and linked from the Admin-only `invoice_admin` row (plan
 * 10-06 R-06-6), never from `invoices`. A failure of the rendering, of the storage or of the link throws like any
 * other step, so the caller's transaction takes the number, the state, the billing and the document row back, and the
 * stored object is removed by the rollback hook of {@see StoreGeneratedDocument}.
 *
 * A document issued with the card switch on gets its pending payment link last, and the queued job that creates the
 * Stripe link is dispatched after the commit (PA-01, ruling R11-07-1); a Stripe outage can never fail the issue.
 */
final readonly class DocumentIssuer
{
    public function __construct(
        private DocumentNumbering $numbering,
        private InvoiceSnapshot $snapshot,
        private IssueChecks $checks,
        private ExchangeRateConverter $rates,
        private EntryBilling $billing,
        private DraftPreview $conversion,
        private InvoicePdf $pdf,
        private StoreGeneratedDocument $storage,
    ) {}

    /**
     * Issues the locked draft and returns the issued document.
     *
     * @throws LogicException outside a transaction
     * @throws DomainException when the document is not a draft
     * @throws ValidationException with the blockers, `issue_date_before_latest` found under the sequence lock, or
     *                             `entries_changed` when a time entry of the draft is no longer billable as reserved
     */
    public function issue(Invoice $lockedDraft, IssueContext $context): Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A document is issued inside the caller\'s transaction.');
        }

        if (! $lockedDraft->isDraft()) {
            throw new DomainException(__('invoices.errors.not_draft'));
        }

        $this->checks->assertIssuable($lockedDraft, $context->today, $context->exchangeSnapshot);

        $kind = $lockedDraft->kind;
        $client = $lockedDraft->client ?? throw new LogicException('A document always has its client.');

        $issueDate = $lockedDraft->issue_date?->format('Y-m-d') ?? $context->today;
        $dueDate = $kind === InvoiceKind::CreditNote
            ? null
            : ($lockedDraft->due_date?->format('Y-m-d') ?? self::addDays($issueDate, $client->payment_terms_days));

        $this->convertTimeLines($lockedDraft, $issueDate);

        $admin = $this->writeAdminRow($lockedDraft, $issueDate, $context);

        $supplier = $this->snapshot->supplier();
        $customer = $this->snapshot->customer($client);
        $bank = $kind === InvoiceKind::CreditNote ? null : $this->snapshot->bank($lockedDraft->currency);
        $appearance = $this->snapshot->appearance();

        $number = $this->numbering->next($kind->numberingKind(), self::noonOf($issueDate));

        // The advisory check of the blockers ran before the counter row was locked. From here on no other issuer of
        // the series can commit, so this answer is the final one; a refusal rolls the number back (ruling R-12-1).
        $latest = $this->checks->latestIssueDate($kind);

        if ($latest !== null && $issueDate < $latest) {
            throw ValidationException::withMessages([
                'issue_date_before_latest' => __('invoices.errors.issue_date_before_latest', ['date' => IssueChecks::czechDate($latest)]),
            ]);
        }

        $lockedDraft->forceFill([
            'state' => InvoiceState::Issued,
            'lock_state' => $kind === InvoiceKind::CreditNote ? InvoiceLockState::Locked : InvoiceLockState::Open,
            'number' => $number,
            'variable_symbol' => VariableSymbol::fromNumber($kind->numberingKind(), $number),
            'issue_date' => $issueDate,
            'taxable_supply_date' => null,
            'due_date' => $dueDate,
            'vat_mode' => app(InvoicingSettings::class)->vat_mode,
            'supplier_snapshot' => $supplier,
            'customer_snapshot' => $customer,
            'bank_snapshot' => $bank,
            'appearance_snapshot' => $appearance,
            'issued_at' => TimerClock::now(),
        ])->save();

        $this->billLinkedTime($lockedDraft);

        $issued = $lockedDraft->refresh();

        $this->storePdf($issued, $admin, $context);

        $this->openPaymentLink($issued, $admin, $context);

        return $issued;
    }

    /**
     * For a document issued with card payment on, writes the pending payment link for the total and queues the job
     * that creates it at Stripe (PA-01; D-05, D-06, ruling R11-07-1). The row is written after the flip because the
     * insert guard of `payment_links` needs the document already issued; the dispatch waits for the commit, and a
     * rollback of the issue takes the row and the dispatch back. Stripe is not called here.
     */
    private function openPaymentLink(Invoice $issued, InvoiceAdmin $admin, IssueContext $context): void
    {
        if (! $admin->card_payment_enabled) {
            return;
        }

        $link = PaymentLink::open($issued, $issued->total_minor, $context->actor);

        CreatePaymentLink::dispatch($link->id)->afterCommit();
    }

    /**
     * Renders the PDF of the issued document from its snapshots, stores it as a document owned by the invoice and
     * saves the link on the Admin-only row (D-21, D-23). The `invoices` row takes no write here: the flip was its last
     * write of the pipeline, and the link lives in `invoice_admin`, which stays writable for it under a lock.
     */
    private function storePdf(Invoice $issued, InvoiceAdmin $admin, IssueContext $context): void
    {
        $document = $this->storage->handle($context->actor, $issued, $this->pdf->render($issued), $this->pdf->fileName($issued));

        $admin->forceFill(['pdf_document_id' => $document->getKey()])->save();
    }

    /**
     * Converts the time lines of the draft that are priced in another currency than the document, with the CNB lists
     * valid on the issue date (D-09, ruling R-17-1): the line amount is {@see Money::timesThroughCzk()} of the quantity
     * and the source rate, rounded once from the exact conversion (through {@see DraftPreview::convertLine()}), the printed unit price is the display figure of the same conversion and never
     * feeds the amount, and the line is flagged `price_converted`. Both quotes and the list date are frozen in the
     * conversion snapshot of the line (ruling R-17-2). A price typed by hand is left exact. The document totals are
     * then recomputed as the sum of the stored line amounts, before the state flips.
     *
     * A line whose source already carries a snapshot was frozen before and is not converted again.
     *
     * @throws ValidationException `cnb_missing` when a needed list is missing, `items` when an amount does not fit,
     *                             `total_not_positive` when the converted total is no longer positive
     */
    private function convertTimeLines(Invoice $draft, string $issueDate): void
    {
        /** @var Collection<int, InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->orderBy('position')->get();

        $quotes = [];
        $changed = false;

        foreach ($items as $item) {
            $source = $item->source;

            if (! $source instanceof InvoiceItemSource
                || $source->conversion_snapshot !== null
                || ! DraftPreview::needsConversion($source, $draft->currency)) {
                continue;
            }

            try {
                $converted = $this->conversion->convertLine(
                    $source->sourceRate() ?? throw new LogicException('A converted line has its source rate.'),
                    $item->quantity,
                    $draft->currency,
                    $issueDate,
                    $quotes,
                );
            } catch (ExchangeRateMissing $missing) {
                throw ValidationException::withMessages([
                    'cnb_missing' => __('invoices.errors.cnb_missing', ['currency' => $missing->currency, 'date' => IssueChecks::czechDate($issueDate)]),
                ]);
            } catch (OverflowException) {
                throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
            }

            $item->forceFill([
                'unit_price_minor' => $converted['unit_price']->minor,
                'line_total_minor' => $converted['line_total']->minor,
                'price_converted' => true,
            ])->save();

            $source->forceFill(['conversion_snapshot' => $converted['snapshot']])->save();
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        /** @var list<int> $minors */
        $minors = InvoiceItem::query()->where('invoice_id', $draft->id)->pluck('line_total_minor')->map(static fn (mixed $minor): int => (int) $minor)->all();

        try {
            $totals = DraftTotals::for(array_map(static fn (int $minor): Money => Money::ofMinor($minor, $draft->currency), $minors), $draft->currency);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
        }

        if ($draft->kind === InvoiceKind::CreditNote ? ! $totals['total']->isNegative() : ! $totals['total']->isPositive()) {
            throw ValidationException::withMessages(['total_not_positive' => __('invoices.errors.total_not_positive')]);
        }

        $draft->forceFill([
            'subtotal_minor' => $totals['subtotal']->minor,
            'vat_minor' => $totals['vat']->minor,
            'total_minor' => $totals['total']->minor,
        ])->save();
    }

    /**
     * Bills the time entries the document holds, in the transaction of the issue. They are locked in id order, the
     * same order every claim uses, and judged on the locked rows: still finished, billable, unbilled and linked to
     * this document. The billing snapshots the rate and the amount of each (D-04); the link stays (D-15).
     *
     * @throws ValidationException `entries_changed` when any linked entry no longer fits
     */
    private function billLinkedTime(Invoice $issued): void
    {
        /** @var Collection<int, TimeEntry> $entries */
        $entries = TimeEntry::query()->where('invoice_id', $issued->id)->orderBy('id')->lockForUpdate()->get();

        if ($entries->isEmpty()) {
            return;
        }

        $changed = $entries->contains(static fn (TimeEntry $entry): bool => $entry->ended_at === null
            || ! $entry->billable
            || $entry->billing_state !== BillingState::Unbilled
            || $entry->invoice_id !== $issued->id);

        if ($changed) {
            throw ValidationException::withMessages(['entries_changed' => __('invoices.errors.entries_changed')]);
        }

        $this->billing->bill($entries, TimerClock::now());
    }

    /**
     * The Admin-only row of the document, created while the parent is still a draft because the child guard
     * refuses an INSERT afterwards (the PDF step writes the PDF link into it). For a document not in CZK it
     * also stores the CNB rate, unit and list date valid on the issue date, or the trio the context hands over, and
     * the CZK total computed from them once (D-12, IN-07, EX-03): the rate is never looked up again.
     *
     * The card switch and the pay token are finalised here too (D-01, D-06): the switch becomes the AND of the draft
     * switch, the global setting and a configured Stripe for an invoice or a proforma, and a document with the switch
     * on gets its token. The order matters: both columns are frozen with the parent, so the child guard of
     * `invoice_admin` refuses the write once the state has flipped to issued.
     */
    private function writeAdminRow(Invoice $draft, string $issueDate, IssueContext $context): InvoiceAdmin
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $draft->id)->lockForUpdate()->first();

        if (! $admin instanceof InvoiceAdmin) {
            $admin = new InvoiceAdmin;
            $admin->forceFill(['invoice_id' => $draft->id, 'card_payment_enabled' => false]);
        }

        if ($draft->currency !== 'CZK') {
            $rate = $context->exchangeSnapshot ?? $this->rateOf($draft->currency, $issueDate);

            $admin->forceFill([
                'exchange_rate' => $rate['rate'],
                'exchange_unit_amount' => $rate['unit_amount'],
                'exchange_rate_date' => $rate['rate_date'],
                'czk_total_minor' => $draft->total()->convert($rate['rate'], 'CZK', $rate['unit_amount'])->minor,
            ]);
        }

        $cardPayment = app(CardPaymentSwitch::class)->effective($draft->kind, $admin->card_payment_enabled);

        $admin->forceFill([
            'card_payment_enabled' => $cardPayment,
            'pay_token' => $cardPayment ? PayToken::generate() : null,
        ]);

        $admin->save();

        return $admin;
    }

    /**
     * @return array{rate: string, unit_amount: int, rate_date: string}
     *
     * @throws ValidationException `cnb_missing` when the list vanished since the blockers ran
     */
    private function rateOf(string $currency, string $issueDate): array
    {
        try {
            return $this->rates->rateFor($currency, $issueDate);
        } catch (ExchangeRateMissing) {
            throw ValidationException::withMessages([
                'cnb_missing' => __('invoices.errors.cnb_missing', ['currency' => $currency, 'date' => IssueChecks::czechDate($issueDate)]),
            ]);
        }
    }

    private static function addDays(string $day, int $days): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->addDays($days)->format('Y-m-d') ?? $day;
    }

    /**
     * Midday of a Prague calendar day: the instant the numbering reads its year or month from, away from every
     * midnight and every daylight-saving shift.
     */
    private static function noonOf(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $day.' 12:00', FilamentTimezone::get())
            ?? throw new LogicException('Not a calendar day: '.$day);
    }
}
