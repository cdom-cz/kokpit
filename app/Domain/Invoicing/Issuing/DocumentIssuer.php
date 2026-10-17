<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Issuing;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Invoicing\Support\VariableSymbol;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
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
 * that follow in later plans write only time entries, `invoice_admin` and documents.
 */
final readonly class DocumentIssuer
{
    public function __construct(
        private DocumentNumbering $numbering,
        private InvoiceSnapshot $snapshot,
    ) {}

    /**
     * Issues the locked draft and returns the issued document.
     *
     * @throws LogicException outside a transaction
     * @throws DomainException when the document is not a draft
     */
    public function issue(Invoice $lockedDraft, IssueContext $context): Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A document is issued inside the caller\'s transaction.');
        }

        if (! $lockedDraft->isDraft()) {
            throw new DomainException(__('invoices.errors.not_draft'));
        }

        $kind = $lockedDraft->kind;
        $client = $lockedDraft->client ?? throw new LogicException('A document always has its client.');

        $issueDate = $lockedDraft->issue_date?->format('Y-m-d') ?? $context->today;
        $dueDate = $kind === InvoiceKind::CreditNote
            ? null
            : ($lockedDraft->due_date?->format('Y-m-d') ?? self::addDays($issueDate, $client->payment_terms_days));

        $this->ensureAdminRow($lockedDraft);

        $supplier = $this->snapshot->supplier();
        $customer = $this->snapshot->customer($client);
        $bank = $kind === InvoiceKind::CreditNote ? null : $this->snapshot->bank($lockedDraft->currency);
        $appearance = $this->snapshot->appearance();

        $number = $this->numbering->next($kind->numberingKind(), self::noonOf($issueDate));

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

        return $lockedDraft->refresh();
    }

    /**
     * The Admin-only row of the document, created while the parent is still a draft because the child guard
     * refuses an INSERT afterwards. Plan 10-20 later writes the PDF link into it.
     */
    private function ensureAdminRow(Invoice $draft): InvoiceAdmin
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $draft->id)->lockForUpdate()->first();

        if (! $admin instanceof InvoiceAdmin) {
            $admin = new InvoiceAdmin;
            $admin->forceFill(['invoice_id' => $draft->id])->save();
        }

        return $admin;
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
