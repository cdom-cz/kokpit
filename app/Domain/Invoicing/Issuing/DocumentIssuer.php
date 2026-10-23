<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Issuing;

use App\Domain\Documents\Actions\StoreGeneratedDocument;
use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Invoicing\Support\DocumentPayment;
use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\TimeLineConversion;
use App\Domain\Invoicing\Support\VariableSymbol;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\CardPaymentSwitch;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Settings\InvoicingSettings;
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
 * (D-09, D-12, IN-07), through {@see TimeLineConversion::freezeAt()}, the same code the change of currency of an open
 * issued document runs. Their rate and the CZK total of the document are frozen from the same lists afterwards.
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
 * A document issued with the card switch on (always the case for an `online` document, D-03) gets its pending payment link last, and the queued job that creates the
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

        $this->checks->assertIssuable($lockedDraft, $context->today, $context->exchangeSnapshot, $context->bankSnapshot);

        $kind = $lockedDraft->kind;
        $client = $lockedDraft->client ?? throw new LogicException('A document always has its client.');

        $issueDate = $lockedDraft->issue_date?->format('Y-m-d') ?? $context->today;
        $dueDate = $kind === InvoiceKind::CreditNote
            ? null
            : ($lockedDraft->due_date?->format('Y-m-d') ?? self::addDays($issueDate, $client->payment_terms_days));

        TimeLineConversion::freezeAt($lockedDraft, $issueDate);

        $admin = $this->writeAdminRow($lockedDraft, $issueDate, $context);

        $supplier = $this->snapshot->supplier();
        $customer = $this->snapshot->customer($client);
        $bank = $this->bankSnapshot($lockedDraft, $context->bankSnapshot);
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
     * The bank snapshot of the document: none for a credit note and none for an online document, which prints no bank
     * details (D-01, OA-7); for a transfer document the account the draft chose, copied from the settings (D-05, D-06).
     * The account may have any currency and is not warned about. When the account cannot be resolved and the context
     * hands over a bank snapshot (a converted invoice, WR-02), that snapshot is printed instead of another account.
     *
     * @param  array<string, mixed>|null  $inherited  the bank snapshot of the document this one copies (a converted
     *                                                invoice), printed when the draft's account cannot be resolved
     * @return array<string, mixed>|null
     *
     * @throws ValidationException `bank_missing` when the account vanished after the blockers ran
     */
    private function bankSnapshot(Invoice $draft, ?array $inherited = null): ?array
    {
        if ($draft->kind === InvoiceKind::CreditNote || $draft->payment_method === DocumentPaymentMethod::Online) {
            return null;
        }

        $account = DocumentPayment::account($draft);

        if ($account !== null) {
            return $this->snapshot->bankAccount($account);
        }

        return $inherited ?? throw ValidationException::withMessages(['bank_missing' => __('invoices.errors.bank_missing')]);
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
     * The card switch and the pay token are finalised here too (D-01, D-03, D-06): the switch becomes the AND of the draft
     * switch (forced on for an online document), the global setting and a configured Stripe for an invoice or a
     * proforma, and a document with the switch on gets its token. A transfer draft whose row names no account records
     * the account it prints, so every issued transfer document carries the id of its account (D-05); a converted invoice that prints the snapshot of its proforma keeps the proforma's account id, or none. The order matters: both columns are frozen with the parent, so the child guard of
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

        $online = $draft->payment_method === DocumentPaymentMethod::Online;
        $cardPayment = app(CardPaymentSwitch::class)->effective($draft->kind, $online || $admin->card_payment_enabled);

        if ($draft->kind !== InvoiceKind::CreditNote && ! $online && $admin->bank_account_id === null) {
            $admin->forceFill(['bank_account_id' => DocumentPayment::account($draft)?->id]);
        }

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
