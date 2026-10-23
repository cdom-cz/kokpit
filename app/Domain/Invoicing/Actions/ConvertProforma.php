<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Support\PaymentLinks;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Converts a paid proforma into an invoice (IN-06, FI-03; D-15, D-18, research Pattern 7): one transaction that
 * either completes in full or leaves every row as it was. The Admin only.
 *
 * The order inside the transaction is deliberate (ruling R-22-1). The proforma row is locked first, the lock order
 * shared with {@see RecordPayment} and the deletion of a payment, so a payment deleted while the conversion runs
 * serialises on this lock. The proforma is checked, then the invoice draft is created with copies of its lines (the
 * unit prices are marked as typed by hand, so the issue never converts them again and the amounts stay exactly those
 * of the proforma) and issued through the shared {@see DocumentIssuer}. No time entry is linked to it yet, so the
 * billing step of the issue bills nothing. Only then the entries of the proforma are repointed to the invoice, which
 * keeps both the rule of the link guard (a billed entry links to an issued document) and the strict billing step. At
 * the end each payment of the proforma is carried over with its income ({@see RecordPayment::carry()}).
 *
 * The invoice inherits the payment method of the proforma and the id of its chosen bank account (Phase 14; D-01, D-05,
 * owner answer OA-2, rulings R-14-09-1 and R-14-14-4): an online proforma becomes an online invoice that prints no bank
 * details, a transfer proforma a transfer invoice, and a converted transfer invoice gets no card switch (Phase 11 D-05).
 * While the inherited account is configured the invoice prints it from the settings. When it is no longer configured
 * (or, for a legacy proforma, none is stored and no account of the currency is left) the invoice prints the bank
 * snapshot of the proforma itself, handed to the issue through {@see IssueContext}, so a paid transfer proforma stays
 * convertible and never prints an account the proforma did not name (WR-02; OA-7). The issue then runs unchanged and opens the card link of an online invoice for its
 * total; after the last carried payment {@see PaymentLinks::reconcile()} deactivates that link when nothing is left to
 * pay (`paid`, or `amount_changed` for a partly carried amount), still in the transaction (ruling R-14-09-2, research
 * Pitfall 7), so a fully paid converted invoice never offers a live card link.
 *
 * The invoice is dated today and due today plus the payment terms of the client, its CZK figures follow the CNB list
 * of its own issue date (ruling R-22-2). Its printed note is copied from the proforma, the internal note is not
 * (ruling R-22-3). The entries stay billed with their first instant and snapshot, and no payment of the proforma is
 * moved or edited: they remain its history.
 */
final readonly class ConvertProforma
{
    public function __construct(
        private DocumentIssuer $issuer,
        private EntryBilling $billing,
        private RecordPayment $payments,
        private InvoiceBalances $balances,
        private PaymentLinks $links,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Invoice $proforma): Invoice
    {
        Gate::forUser($actor)->authorize('update', $proforma);

        return DB::transaction(function () use ($actor, $proforma): Invoice {
            $locked = Invoice::query()->whereKey($proforma->getKey())->lockForUpdate()->firstOrFail();

            $this->assertConvertible($locked);

            $draft = $this->createDraft($locked);
            $this->copyLines($locked, $draft);

            $snapshot = $locked->payment_method === DocumentPaymentMethod::Online ? null : $locked->bank_snapshot;

            $issued = $this->issuer->issue($draft->refresh(), new IssueContext($actor, IssueContext::today(), null, $snapshot));

            $this->repointEntries($locked, $issued);

            /** @var Collection<int, InvoicePayment> $payments */
            $payments = InvoicePayment::query()->where('invoice_id', $locked->id)->orderBy('paid_on')->orderBy('id')->get();

            foreach ($payments as $payment) {
                $this->payments->carry($actor, $payment, $issued);
            }

            $this->links->reconcile($issued);

            return $issued->refresh();
        });
    }

    /**
     * Only an issued, not cancelled proforma that is paid in full and has no invoice yet can be converted. Judged on
     * the locked row: a second conversion waiting on the lock finds the first one's invoice here (T-10-59), and the
     * unique `proforma_id` of the database stays the backstop.
     *
     * @throws ValidationException keyed `proforma`: `proforma_not_issued`, `proforma_converted` or `proforma_not_paid`
     */
    private function assertConvertible(Invoice $proforma): void
    {
        if ($proforma->kind !== InvoiceKind::Proforma || $proforma->state !== InvoiceState::Issued || $proforma->isCancelled()) {
            throw ValidationException::withMessages(['proforma' => __('invoices.errors.proforma_not_issued')]);
        }

        if (Invoice::query()->where('proforma_id', $proforma->id)->exists()) {
            throw ValidationException::withMessages(['proforma' => __('invoices.errors.proforma_converted')]);
        }

        $paid = $this->balances->for([$proforma->id])[$proforma->id]['paid'] ?? 0;

        if ($paid < $proforma->total_minor) {
            throw ValidationException::withMessages(['proforma' => __('invoices.errors.proforma_not_paid')]);
        }
    }

    /**
     * The draft invoice of the proforma's client, currency and language, with its figures and printed note and the
     * link to the proforma. The issue and due dates stay open: today and today plus the payment terms at issue.
     */
    private function createDraft(Invoice $proforma): Invoice
    {
        $method = $proforma->payment_method ?? DocumentPaymentMethod::Transfer;

        $draft = new Invoice;
        $draft->forceFill([
            'payment_method' => $method,
            'client_id' => $proforma->client_id,
            'kind' => InvoiceKind::Invoice,
            'currency' => $proforma->currency,
            'language' => $proforma->language,
            'vat_mode' => app(InvoicingSettings::class)->vat_mode,
            'subtotal_minor' => $proforma->subtotal_minor,
            'vat_minor' => $proforma->vat_minor,
            'total_minor' => $proforma->total_minor,
            'note' => $proforma->note,
            'proforma_id' => $proforma->id,
        ])->save();

        $admin = new InvoiceAdmin;
        $admin->forceFill([
            'invoice_id' => $draft->id,
            'bank_account_id' => $this->inheritedAccount($proforma),
            'card_payment_enabled' => $method === DocumentPaymentMethod::Online,
        ])->save();

        return $draft;
    }

    /**
     * The id of the bank account the proforma chose, as stored (null when it names none). Not filtered through the
     * settings: an id that is no longer configured is no account for the draft and is never re-picked, the invoice
     * prints the snapshot of the proforma instead (WR-02, ruling R-14-14-4).
     */
    private function inheritedAccount(Invoice $proforma): ?string
    {
        $id = InvoiceAdmin::query()->where('invoice_id', $proforma->id)->value('bank_account_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Copies every line of the proforma as frozen text, with the amounts it was issued with, and its source row with
     * the price marked as typed by hand (ruling R-22-1).
     */
    private function copyLines(Invoice $proforma, Invoice $draft): void
    {
        /** @var Collection<int, InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $proforma->id)->with('source')->orderBy('position')->get();

        foreach ($items as $item) {
            $copy = new InvoiceItem;
            $copy->fill(['description' => $item->description, 'unit' => $item->unit]);
            $copy->forceFill([
                'invoice_id' => $draft->id,
                'position' => $item->position,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'line_total_minor' => $item->line_total_minor,
                'price_converted' => $item->price_converted,
                'vat_rate' => $item->vat_rate,
                'vat_minor' => $item->vat_minor,
            ])->save();

            $source = $item->source;

            if (! $source instanceof InvoiceItemSource) {
                continue;
            }

            $sourceCopy = new InvoiceItemSource;
            $sourceCopy->forceFill([
                'invoice_item_id' => $copy->id,
                'invoice_id' => $draft->id,
                'origin' => $source->origin,
                'project_id' => $source->project_id,
                'task_id' => $source->task_id,
                'source_rate_minor' => $source->source_rate_minor,
                'source_rate_currency' => $source->source_rate_currency,
                'source_seconds' => $source->source_seconds,
                'price_manual' => true,
                'conversion_snapshot' => $source->conversion_snapshot,
            ])->save();
        }
    }

    /**
     * Moves the billed entries of the proforma to the invoice. They are locked in id order, the order every claim
     * uses, and stay billed with their instant and snapshot (D-15).
     */
    private function repointEntries(Invoice $proforma, Invoice $invoice): void
    {
        /** @var Collection<int, TimeEntry> $entries */
        $entries = TimeEntry::query()->where('invoice_id', $proforma->id)->orderBy('id')->lockForUpdate()->get();

        $this->billing->repoint($entries, $invoice->id);
    }
}
