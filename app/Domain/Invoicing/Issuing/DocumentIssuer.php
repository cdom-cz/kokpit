<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Issuing;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\VariableSymbol;
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
 * last write on the `invoices` row, because an issued row accepts no write at all (plan 10-04 R-04-6): the steps
 * that follow write only time entries, `invoice_admin` and documents.
 *
 * The billing of the time comes right after the flip, because the link guard of `time_entries` bills an entry only
 * when its document is already issued (plan 10-06). Every entry the draft holds is locked in id order and must still
 * be exactly what the draft reserved it as; one that is not fails the whole issue with `entries_changed`, and the
 * rollback takes the number, the state and every billed entry back (D-04, D-10, D-15).
 */
final readonly class DocumentIssuer
{
    public function __construct(
        private DocumentNumbering $numbering,
        private InvoiceSnapshot $snapshot,
        private IssueChecks $checks,
        private ExchangeRateConverter $rates,
        private EntryBilling $billing,
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

        $this->writeAdminRow($lockedDraft, $issueDate, $context);

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

        return $lockedDraft->refresh();
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
     * refuses an INSERT afterwards (plan 10-20 later writes the PDF link into it). For a document not in CZK it
     * also stores the CNB rate, unit and list date valid on the issue date, or the trio the context hands over, and
     * the CZK total computed from them once (D-12, IN-07, EX-03): the rate is never looked up again.
     */
    private function writeAdminRow(Invoice $draft, string $issueDate, IssueContext $context): InvoiceAdmin
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $draft->id)->lockForUpdate()->first();

        if (! $admin instanceof InvoiceAdmin) {
            $admin = new InvoiceAdmin;
            $admin->forceFill(['invoice_id' => $draft->id]);
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
