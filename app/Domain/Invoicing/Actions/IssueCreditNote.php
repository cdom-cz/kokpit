<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\CreditableQuantities;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Payments\Support\PaymentLinks;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Credit notes of an issued invoice (IN-10, IN-01, IN-03; D-13, D-14, D-16, research Pattern 8): a partial credit note
 * that takes back chosen quantities, and the full storno that takes back everything still creditable, cancels the
 * invoice and returns its time to unbilled. Both build a credit-note draft and issue it through the shared
 * {@see DocumentIssuer}, so the number comes from the credit-note series and the document freezes with its PDF like
 * every other. The Admin only.
 *
 * Nothing is ever written on the credited invoice (plan 10-04 R-04-6). The storno marker is the credit note itself:
 * its `cancels_invoice` flag, true only on the storno (ruling R-04-5), and an issued credit note that carries it is
 * what makes the invoice cancelled. A second storno of the same invoice is refused on the locked original through
 * {@see Invoice::isCancelled()}, and the unique index `invoices_one_storno_per_invoice` is the database backstop.
 *
 * The original is locked first, the lock order shared with the payments and the conversion of a proforma. Everything
 * is judged on the locked row: that the invoice is issued and not yet cancelled, and the creditable quantity of each
 * line, so two credit notes issued at the same moment cannot take back the same quantity (T-10-61). A line of the
 * credit note takes back a line of the invoice: the negative quantity, the original unit price and
 * `credited_item_id`, and its source row is a `manual` one with the price marked as typed, so the issue never converts
 * it again (ruling R-23-3). The credit note copies the exchange rate of the invoice for its CZK total, so the two
 * cancel exactly in CZK (ruling R-23-2); its supplier and customer are frozen at its own issue like any document.
 *
 * A line converted from another currency at issue (`price_converted`) is credited from the conversion frozen on its
 * source row: the amount is {@see Money::timesThroughCzk()} of the negative quantity, rounded once, never the printed
 * unit price times the quantity (D-09, ruling R-23-4). Crediting the whole quantity gives exactly minus the original
 * amount, because the rounding is half away from zero. A line of the document's own currency is credited with
 * {@see Money::times()}.
 *
 * The storno releases the time of the invoice. A partial credit note releases it only when, together with the earlier
 * ones, it leaves nothing creditable on any line (Phase 17.1, D-01, D-02): the one that takes back the last quantity
 * returns every entry to unbilled and unlocked, in its own transaction under the same row lock. The release comes
 * after the issue because the link guard of the entries demands the issued credit note (T-10-62): the storno
 * credit note for the storno, `kokpit_invoice_fully_credited()` for the partial case. The invoice is not cancelled by
 * that (D-03): no credit note but the storno carries `cancels_invoice`, and nothing is written on the invoice. Payments
 * and their income stay as they are (D-16); a refund is booked by hand.
 *
 * The problems of the lines are one {@see ValidationException} keyed `lines.<item id>`; a document that cannot be
 * credited is keyed `invoice`, an empty selection `lines`.
 */
final readonly class IssueCreditNote
{
    public function __construct(
        private DocumentIssuer $issuer,
        private EntryBilling $billing,
        private InvoiceBalances $balances,
        private PaymentLinks $paymentLinks,
    ) {}

    /**
     * A credit note of chosen quantities. The time stays billed until the credit notes take back every line; the one
     * that takes back the last quantity returns all entries of the invoice to unbilled (Phase 17.1, D-01).
     *
     * @param  array<array-key, mixed>  $lines  item id of the invoice to the quantity to credit, a decimal text
     *
     * @throws ValidationException
     */
    public function partial(User $actor, Invoice $invoice, array $lines): Invoice
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return DB::transaction(function () use ($actor, $invoice, $lines): Invoice {
            $locked = $this->lock($invoice);

            $this->assertCreditable($locked);

            $quantities = $this->requested($lines, CreditableQuantities::for($locked));

            $credit = $this->issueCredit($actor, $locked, $quantities, false);

            // D-01, D-02: judged on the locked row after the issue, so this credit note counts; the invoice is not cancelled (D-03).
            if ($this->creditedInFull($locked)) {
                $this->releaseEntries($locked);
            }

            // D-08: the credit note lowers what is owed, so a live payment link that offers the old amount is deactivated here.
            $this->paymentLinks->reconcile($locked);

            return $credit;
        });
    }

    /**
     * The full storno: a credit note of everything still creditable that cancels the invoice, and its entries go
     * back to unbilled and unlocked (D-14, ruling R-23-1).
     *
     * @throws ValidationException
     */
    public function storno(User $actor, Invoice $invoice): Invoice
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return DB::transaction(function () use ($actor, $invoice): Invoice {
            $locked = $this->lock($invoice);

            $this->assertCreditable($locked);

            $quantities = array_filter(CreditableQuantities::for($locked), static fn (InvoiceQuantity $left): bool => $left->isPositive());

            if ($quantities === []) {
                throw ValidationException::withMessages(['lines' => __('invoices.errors.credit_nothing')]);
            }

            $credit = $this->issueCredit($actor, $locked, $quantities, true);

            $this->releaseEntries($locked);

            // D-08: the storno cancels the invoice, so its live payment link is deactivated in the same transaction.
            $this->paymentLinks->reconcile($locked);

            return $credit;
        });
    }

    /**
     * How much of each line can still be credited, for the dialog that offers the quantities. Read without the row
     * lock, so it only informs: {@see self::partial()} and {@see self::storno()} judge the same figures again on the
     * locked row.
     *
     * @return array<string, InvoiceQuantity> item id to the remaining creditable quantity
     */
    public function creditable(Invoice $invoice): array
    {
        return DB::transaction(static fn (): array => CreditableQuantities::for($invoice));
    }

    /**
     * The amounts a credit note of these quantities would carry, line by line and negative, computed exactly as the
     * issue computes them (a converted line through its frozen conversion, ruling R-23-4), for the live total of the
     * dialog. Nothing is written or checked against what is creditable: a quantity that is no positive decimal, or a
     * key that is no line of this invoice, simply has no amount.
     *
     * @param  array<array-key, mixed>  $lines  item id to the quantity typed, a decimal text
     * @return array<string, Money> item id to the negative amount
     */
    public function preview(Invoice $invoice, array $lines): array
    {
        $quantities = [];

        foreach ($lines as $itemId => $value) {
            $quantity = self::quantityOf($value);

            if ($quantity instanceof InvoiceQuantity && Str::isUuid((string) $itemId)) {
                $quantities[(string) $itemId] = $quantity;
            }
        }

        if ($quantities === []) {
            return [];
        }

        $amounts = [];

        foreach ($this->creditLines($invoice, $quantities) as $line) {
            $amounts[$line['item']->id] = $line['amount'];
        }

        return $amounts;
    }

    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Only an issued invoice that no storno credit note names can be credited: not a proforma, not a credit note, not
     * a draft, not an invoice that is already cancelled.
     *
     * @throws ValidationException keyed `invoice`
     */
    private function assertCreditable(Invoice $invoice): void
    {
        if ($invoice->kind !== InvoiceKind::Invoice || $invoice->state !== InvoiceState::Issued || $invoice->isCancelled()) {
            throw ValidationException::withMessages(['invoice' => __('invoices.errors.credit_not_allowed')]);
        }
    }

    /**
     * The chosen quantities, checked against what each line still allows. Every problem of every line is collected
     * and thrown together: a quantity that is not a positive decimal with at most four decimals
     * (`invoices.errors.item_quantity`), and a quantity above what is left, or a line that is not a line of this
     * invoice, whose creditable quantity is none (`credit_quantity`).
     *
     * @param  array<array-key, mixed>  $lines
     * @param  array<string, InvoiceQuantity>  $creditable
     * @return array<string, InvoiceQuantity> item id to the quantity to credit
     *
     * @throws ValidationException
     */
    private function requested(array $lines, array $creditable): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('invoices.errors.credit_nothing')]);
        }

        $quantities = [];
        $errors = [];

        foreach ($lines as $itemId => $value) {
            $itemId = (string) $itemId;
            $quantity = self::quantityOf($value);

            if (! $quantity instanceof InvoiceQuantity) {
                $errors["lines.{$itemId}"] = [__('invoices.errors.item_quantity')];

                continue;
            }

            $left = $creditable[$itemId] ?? null;

            if (! $left instanceof InvoiceQuantity || $quantity->compareTo($left) > 0) {
                $errors["lines.{$itemId}"] = [__('invoices.errors.credit_quantity')];

                continue;
            }

            $quantities[$itemId] = $quantity;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $quantities;
    }

    private static function quantityOf(mixed $value): ?InvoiceQuantity
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $quantity = InvoiceQuantity::fromDecimal(trim($value));
        } catch (InvalidArgumentException|OverflowException) {
            return null;
        }

        return $quantity->isPositive() ? $quantity : null;
    }

    /**
     * Builds the credit-note draft of the quantities, issues it with the exchange rate of the invoice and returns the
     * issued credit note.
     *
     * @param  array<string, InvoiceQuantity>  $quantities  item id to the positive quantity to take back
     */
    private function issueCredit(User $actor, Invoice $invoice, array $quantities, bool $storno): Invoice
    {
        $lines = $this->creditLines($invoice, $quantities);
        $amounts = array_map(static fn (array $line): Money => $line['amount'], $lines);

        // A partial credit note may not take back more than the invoice is worth together with the earlier ones. The
        // storno takes back the remaining quantities by construction, which the rounding of the lines can only move
        // by a minor unit, so it is never refused on its total.
        if (! $storno) {
            $credited = $this->balances->for([$invoice->id])[$invoice->id]['credited'] ?? 0;
            $new = array_reduce($amounts, static fn (int $sum, Money $amount): int => $sum - $amount->minor, 0);

            if ($credited + $new > $invoice->total_minor) {
                throw ValidationException::withMessages(['lines' => __('invoices.errors.credit_total')]);
            }
        }

        $totals = DraftTotals::for($amounts, $invoice->currency);

        $draft = new Invoice;
        $draft->forceFill([
            'client_id' => $invoice->client_id,
            'kind' => InvoiceKind::CreditNote,
            'currency' => $invoice->currency,
            'language' => $invoice->language,
            'vat_mode' => app(InvoicingSettings::class)->vat_mode,
            'subtotal_minor' => $totals['subtotal']->minor,
            'vat_minor' => $totals['vat']->minor,
            'total_minor' => $totals['total']->minor,
            'credited_invoice_id' => $invoice->id,
            'cancels_invoice' => $storno,
        ])->save();

        $this->writeLines($draft, $lines);

        return $this->issuer->issue($draft->refresh(), new IssueContext($actor, IssueContext::today(), $this->exchangeSnapshot($invoice)));
    }

    /**
     * The lines of the credit note in the order of the invoice, each with its amount.
     *
     * @param  array<string, InvoiceQuantity>  $quantities
     * @return list<array{item: InvoiceItem, quantity: InvoiceQuantity, amount: Money, source: InvoiceItemSource|null}>
     */
    private function creditLines(Invoice $invoice, array $quantities): array
    {
        /** @var Collection<int, InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $invoice->id)->whereIn('id', array_keys($quantities))->with('source')->orderBy('position')->get();

        $lines = [];

        foreach ($items as $item) {
            $quantity = $quantities[$item->id];
            $source = $item->source;
            $converted = $this->convertedAmount($item, $source, $quantity, $invoice->currency);

            $lines[] = [
                'item' => $item,
                'quantity' => $quantity,
                'amount' => $converted ?? Money::ofMinor($item->unit_price_minor, $invoice->currency)->times($quantity->negated()->toDecimal()),
                // The source row travels with the line only when the amount rests on its conversion.
                'source' => $converted === null ? null : $source,
            ];
        }

        return $lines;
    }

    /**
     * The amount of a credit line of a line that was converted at issue, from the conversion frozen on its source row
     * (ruling R-23-4); null for a line that was not converted, which is credited at its unit price.
     */
    private function convertedAmount(InvoiceItem $item, ?InvoiceItemSource $source, InvoiceQuantity $quantity, string $currency): ?Money
    {
        if (! $item->price_converted || ! $source instanceof InvoiceItemSource) {
            return null;
        }

        $rate = $source->sourceRate();
        $snapshot = $source->conversion_snapshot;

        if ($rate === null || ! is_array($snapshot)) {
            return null;
        }

        foreach (['source_rate', 'source_unit', 'target_currency', 'target_rate', 'target_unit'] as $key) {
            if (! isset($snapshot[$key]) || ! is_scalar($snapshot[$key])) {
                return null;
            }
        }

        if ((string) $snapshot['target_currency'] !== $currency) {
            return null;
        }

        return $rate->timesThroughCzk(
            $quantity->negated()->toDecimal(),
            (string) $snapshot['source_rate'],
            (int) $snapshot['source_unit'],
            $currency,
            (string) $snapshot['target_rate'],
            (int) $snapshot['target_unit'],
        );
    }

    /**
     * @param  list<array{item: InvoiceItem, quantity: InvoiceQuantity, amount: Money, source: InvoiceItemSource|null}>  $lines
     */
    private function writeLines(Invoice $draft, array $lines): void
    {
        foreach ($lines as $position => $line) {
            $original = $line['item'];
            $source = $line['source'];

            $copy = new InvoiceItem;
            $copy->fill(['description' => $original->description, 'unit' => $original->unit]);
            $copy->forceFill([
                'invoice_id' => $draft->id,
                'position' => $position + 1,
                'quantity' => $line['quantity']->negated()->toDecimal(),
                'unit_price_minor' => $original->unit_price_minor,
                'line_total_minor' => $line['amount']->minor,
                'price_converted' => $source instanceof InvoiceItemSource,
                'vat_rate' => $original->vat_rate,
                // The VAT payer mode is blocked at issue (IN-08), so no line of a credit note carries VAT yet.
                'vat_minor' => 0,
                'credited_item_id' => $original->id,
            ])->save();

            $sourceCopy = new InvoiceItemSource;
            $sourceCopy->forceFill([
                'invoice_item_id' => $copy->id,
                'invoice_id' => $draft->id,
                'origin' => InvoiceItemOrigin::Manual,
                'source_rate_minor' => $source?->source_rate_minor,
                'source_rate_currency' => $source?->source_rate_currency,
                'price_manual' => true,
                'conversion_snapshot' => $source?->conversion_snapshot,
            ])->save();
        }
    }

    /**
     * The CNB figures of the credited invoice, for the credit note to copy; null for an invoice in CZK.
     *
     * @return array{rate: string, unit_amount: int, rate_date: string}|null
     */
    private function exchangeSnapshot(Invoice $invoice): ?array
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $invoice->id)->first();

        if (! $admin instanceof InvoiceAdmin || $admin->exchange_rate === null || $admin->exchange_unit_amount === null || $admin->exchange_rate_date === null) {
            return null;
        }

        return [
            'rate' => $admin->exchange_rate,
            'unit_amount' => $admin->exchange_unit_amount,
            'rate_date' => $admin->exchange_rate_date->format('Y-m-d'),
        ];
    }

    /**
     * True when no line of the invoice has a creditable quantity left. It runs only after a partial credit note was
     * issued in this transaction under the row lock taken by {@see self::lock()}, so the new credit note counts and at
     * least one line had something to credit. Nothing is written on the invoice (D-03).
     */
    private function creditedInFull(Invoice $locked): bool
    {
        foreach (CreditableQuantities::for($locked) as $left) {
            if ($left->isPositive()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns every entry of the cancelled or fully credited invoice to unbilled and unlocked. They are locked in id
     * order, the order every claim uses; the link guard lets them go because the credit note is issued by now (D-14,
     * Phase 17.1 D-01).
     */
    private function releaseEntries(Invoice $invoice): void
    {
        /** @var Collection<int, TimeEntry> $entries */
        $entries = TimeEntry::query()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();

        if ($entries->isNotEmpty()) {
            $this->billing->release($entries);
        }
    }
}
