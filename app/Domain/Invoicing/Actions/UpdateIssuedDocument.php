<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceAudit;
use App\Domain\Invoicing\Support\InvoiceItemInput;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
use App\Domain\Invoicing\Support\IssuedEditFinisher;
use App\Domain\Invoicing\Support\ItemListWriter;
use App\Domain\Invoicing\Support\SnapshotInput;
use App\Domain\Invoicing\Support\TimeLineConversion;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * Corrects an open issued invoice or proforma in one transaction under the invoice row lock: the single write path of
 * such a document (IN-13, IN-14; D-04, D-05, D-09, D-10; R-10.1-04-3). The Admin only. The page hands a plain array and
 * never writes a row itself.
 *
 * Data keys, each optional (an absent key keeps the stored value):
 * - `items`: the whole list of lines, the contract of {@see UpdateDraft} (`id?`, `description`, `quantity`, `unit`,
 *   `unit_price`, `confirm_zero_price?`, see {@see InvoiceItemInput});
 * - `note`: the printed note, at most 500 characters, blank clears it;
 * - `issue_date`, `due_date`: a Prague `Y-m-d`, never null for an issued document, the due date not before the issue
 *   date; a new issue date must fit the series ({@see IssuedDocumentRules::assertIssueDate()}) and keeps the frozen
 *   exchange rate and conversions (D-06, A5, A6, R-10.1-07-1);
 * - `taxable_supply_date`: blank in the non-payer mode, where any day is refused (A14);
 * - `language`: `cs` or `en`;
 * - `customer`, `supplier`: the lines of the customer and supplier snapshots, checked by {@see SnapshotInput} (A9); the
 *   VAT payer flag of the supplier is kept as stored;
 * - `reload_bank` (a strict boolean): the bank snapshot is taken again from the settings for the currency of the
 *   document, `bank_missing` under `bank` when there is none; a bank account is never taken from the data (D-06);
 * - `currency`: a currency of the editor's list ({@see IssuedDocumentRules::currencyChoices()}). A value that differs
 *   from the stored one changes the currency of the document (D-06, R-10.1-08-2), before any write, in this order:
 *   refused as `currency_has_payments` when a payment exists and as `currency_has_credit_notes` when a credit note,
 *   draft or issued, names the document (owner ruling of D-06, 2026-10-23: money received is not re-denominated, the
 *   database holds the same line for every other writer), as `bank_missing` when the settings hold no account for the
 *   new currency and as `cnb_missing` when a needed list of the issue date is missing, all under `currency`. Then the
 *   lines are written in the new currency, the time lines are converted again at the issue date with one rounding and
 *   a fresh conversion snapshot (a rate in the new currency is restored exactly, a price typed by hand and a fixed
 *   amount keep their figure) through {@see TimeLineConversion::freezeAt()}, the bank snapshot follows the currency,
 *   and the CNB rate, unit, list date and CZK total on the Admin-only row are taken again for the issue date, all four
 *   empty for CZK. The stored issue date is the one used, also when the same save moves it (assumption A6);
 * - `internal_note`: saved through {@see UpdateInternalNote}. It prints nowhere, so a save that carries neither
 *   `items` nor `note` does not replace the PDF.
 *
 * Any other key is ignored: the number, the kind, the state, the lock, the totals, the snapshots and the card switch
 * are never read from the data and never written here. Every amount is computed on the server with the one HALF_UP
 * rule ({@see ItemListWriter}, {@see DraftTotals}), and an id that is not a line of this very document is the field
 * error `items.<n>.id` (T-10.1-14, T-10.1-15).
 *
 * The order, all inside the transaction:
 *
 * 1. authorise `update`, lock the invoice row, and ask {@see IssuedDocumentRules::assertEditable()} on the locked
 *    row: a draft, a credit note, a locked or cancelled invoice and a converted proforma are refused with the field
 *    error `document` before anything is written. Payments and credit notes do not refuse (D-04). The values before
 *    the write are read for the audit ({@see InvoiceAudit::snapshot()});
 * 2. write the lines. A line generated from time keeps its frozen price: in the currency of the document the stored
 *    unit price times the new quantity, in another currency the quotes of its conversion snapshot through
 *    {@see TimeLineConversion}, never today's lists. A line a credit note credits keeps its quantity, unit and unit
 *    price, cannot be deleted, and only its text changes (assumption A4);
 * 3. sum the totals; a total that is not positive is refused as `total_not_positive`;
 * 4. save the row (silenced for the model history when the document was ever sent, because the explicit row replaces
 *    it), then {@see IssuedEditFinisher::finish()}: the CZK total, the PDF replacement, the audit row (D-13).
 *
 * The document stays open and nothing is sent; the Admin sends it by hand, and that send locks it again.
 */
final readonly class UpdateIssuedDocument
{
    private const int NOTE_MAX_LENGTH = 500;

    public function __construct(
        private UpdateInternalNote $internalNote,
        private ItemListWriter $writer,
        private IssuedEditFinisher $finisher,
        private InvoiceSnapshot $snapshots,
        private ExchangeRateConverter $rates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException `document` for a document that cannot be edited, `items.<n>.<field>` for a bad line,
     *                             `items` for a credited line that is deleted, `total_not_positive` for a total that is not positive
     */
    public function handle(User $actor, Invoice $issued, array $data): Invoice
    {
        Gate::forUser($actor)->authorize('update', $issued);

        return DB::transaction(function () use ($actor, $issued, $data): Invoice {
            $locked = Invoice::query()->whereKey($issued->getKey())->lockForUpdate()->firstOrFail();

            IssuedDocumentRules::assertEditable($locked);

            $before = InvoiceAudit::snapshot($locked);

            $header = $this->header($locked, $data);
            $change = array_key_exists('currency', $data) ? $this->currencyChange($locked, $data['currency']) : null;

            $note = array_key_exists('note', $data) ? $this->note($data['note']) : $locked->note;
            $currency = $change['currency'] ?? $locked->currency;

            if ($change !== null) {
                // The lines are written in the new currency, so the model carries it from here on; the row is saved once.
                $locked->forceFill(['currency' => $currency]);
            }

            if (array_key_exists('items', $data)) {
                if (! is_array($data['items'])) {
                    throw new InvalidArgumentException('The items of a document are a list of lines.');
                }

                $this->writer->replace($locked, $data['items'], $currency, $change !== null, $change === null ? $this->issuedPrice($currency) : $this->placeholderPrice($currency), $this->creditedLineRule($locked));
            }

            if ($change !== null) {
                $this->convertAgain($locked, $locked->issue_date?->format('Y-m-d') ?? throw new LogicException('An issued document has its issue date.'));
            }

            $totals = $this->writer->totals($locked, $currency);

            if (! $totals['total']->isPositive()) {
                throw ValidationException::withMessages(['total_not_positive' => __('invoices.errors.total_not_positive')]);
            }

            if (array_key_exists('internal_note', $data)) {
                $this->internalNote->handle($actor, $locked, is_string($data['internal_note']) ? $data['internal_note'] : null);
            }

            if ($change !== null) {
                $header = [...$header, 'currency' => $currency, 'bank_snapshot' => $change['bank']];
            }

            if (! array_key_exists('items', $data) && ! array_key_exists('note', $data) && $header === []) {
                return $locked->refresh();
            }

            $locked->fill(['note' => $note]);
            $locked->forceFill([
                ...$header,
                'subtotal_minor' => $totals['subtotal']->minor,
                'vat_minor' => $totals['vat']->minor,
                'total_minor' => $totals['total']->minor,
            ]);

            // After the first send the explicit `invoice_edited` row is the history of the edit, so the model must not
            // add its own `updated` row for the same save (D-13); a never-sent document keeps the model history.
            if (InvoiceAudit::everSent($locked)) {
                $locked->disableLogging()->save();
                $locked->enableLogging();
            } else {
                $locked->save();
            }

            if ($change !== null) {
                $this->storeExchangeFigures($locked, $change['rate'], $totals['total']);
            }

            $this->finisher->finish($actor, $locked, $before);

            return $locked->refresh();
        });
    }

    /**
     * The header columns of the data that change: only the keys that are present and differ from the stored value,
     * checked, as the columns they are written to. The number, the kind, the VAT mode and the appearance are never
     * among them (D-08).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException `due_date`, `language`, `customer.<field>`
     */
    private function header(Invoice $locked, array $data): array
    {
        $values = [];
        $issueDate = $locked->issue_date?->format('Y-m-d');
        $dueDate = $locked->due_date?->format('Y-m-d');

        if (array_key_exists('issue_date', $data)) {
            $day = $this->requiredDay($data['issue_date'], 'issue_date');

            if ($day !== $issueDate) {
                IssuedDocumentRules::assertIssueDate($locked, $day, IssueContext::today());

                $values['issue_date'] = $day;
                $issueDate = $day;
            }
        }

        if (array_key_exists('due_date', $data)) {
            $day = $this->requiredDay($data['due_date'], 'due_date');

            if ($day !== $dueDate) {
                $values['due_date'] = $day;
                $dueDate = $day;
            }
        }

        // The pair is checked whenever one of the two moved, so a later issue date cannot overtake a kept due date.
        if (($values['issue_date'] ?? $values['due_date'] ?? null) !== null && $issueDate !== null && $dueDate !== null && $dueDate < $issueDate) {
            throw ValidationException::withMessages(['due_date' => __('invoices.errors.due_before_issue')]);
        }

        if (array_key_exists('taxable_supply_date', $data)) {
            $values = [...$values, ...$this->taxableSupplyDate($locked, $data['taxable_supply_date'])];
        }

        if (array_key_exists('language', $data)) {
            $language = $this->language($data['language']);

            if ($language !== $locked->language) {
                $values['language'] = $language;
            }
        }

        // Both parties are checked before anything is thrown, so the Admin sees every refused line at once.
        $errors = [];

        $parties = [
            'customer' => [SnapshotInput::customer(...), $locked->customer_snapshot],
            'supplier' => [SnapshotInput::supplier(...), $locked->supplier_snapshot],
        ];

        foreach ($parties as $party => [$check, $stored]) {
            if (! array_key_exists($party, $data)) {
                continue;
            }

            try {
                $values["{$party}_snapshot"] = $check($this->lines($data[$party], $party), $stored ?? []);
            } catch (ValidationException $exception) {
                $errors = [...$errors, ...$exception->errors()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (array_key_exists('reload_bank', $data)) {
            if (! is_bool($data['reload_bank'])) {
                throw new InvalidArgumentException('The bank refill flag of a document is a boolean.');
            }

            // The account is only ever taken from the settings, for the currency of the document, never from the data.
            if ($data['reload_bank']) {
                $values['bank_snapshot'] = $this->snapshots->bank($locked->currency)
                    ?? throw ValidationException::withMessages(['bank' => __('invoices.errors.bank_missing', ['currency' => $locked->currency])]);
            }
        }

        return $values;
    }

    /**
     * The taxable supply date exists on a VAT payer's document only (IN-08, assumption A14): in the non-payer mode it
     * stays empty, and only blank is accepted.
     *
     * @return array<string, string|null>
     *
     * @throws ValidationException `taxable_supply_date`
     */
    private function taxableSupplyDate(Invoice $locked, mixed $value): array
    {
        $blank = $value === null || (is_string($value) && trim($value) === '');

        if ($locked->vat_mode !== VatMode::Payer) {
            if (! $blank) {
                throw ValidationException::withMessages(['taxable_supply_date' => __('invoices.errors.taxable_supply_not_payer')]);
            }

            return [];
        }

        $day = $blank ? null : $this->requiredDay($value, 'taxable_supply_date');

        return $day === $locked->taxable_supply_date?->format('Y-m-d') ? [] : ['taxable_supply_date' => $day];
    }

    /**
     * The lines of a party as an array, whatever else the data holds.
     *
     * @return array<array-key, mixed>
     */
    private function lines(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("The {$key} of a document is a list of lines.");
        }

        return $value;
    }

    /**
     * A Prague calendar day; an issued document has all its dates, so blank is as wrong as nonsense.
     *
     * @throws ValidationException
     */
    private function requiredDay(mixed $value, string $key): string
    {
        return PragueDay::day(is_string($value) ? trim($value) : $value)
            ?? throw ValidationException::withMessages([$key => __('invoices.errors.date_invalid')]);
    }

    /**
     * @throws ValidationException
     */
    private function language(mixed $value): InvoiceLanguage
    {
        $language = $value instanceof InvoiceLanguage ? $value : (is_string($value) ? InvoiceLanguage::tryFrom($value) : null);

        return $language ?? throw ValidationException::withMessages(['language' => __('invoices.errors.language')]);
    }

    /**
     * Checks a change of currency before anything is written and gathers what it stores: the new bank account and, for
     * a foreign currency, the CNB quote of the issue date. Null when the value is the stored currency.
     *
     * @return array{currency: string, bank: array<string, string|null>, rate: array{rate: string, unit_amount: int, rate_date: string}|null}|null
     *
     * @throws ValidationException `currency`
     */
    private function currencyChange(Invoice $locked, mixed $value): ?array
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (! in_array($code, IssuedDocumentRules::currencyChoices($locked), true)) {
            throw ValidationException::withMessages(['currency' => __('invoices.errors.currency')]);
        }

        if ($code === $locked->currency) {
            return null;
        }

        $blocker = IssuedDocumentRules::currencyBlocker($locked);

        if ($blocker !== null) {
            throw ValidationException::withMessages(['currency' => __('invoices.errors.'.$blocker)]);
        }

        $bank = $this->snapshots->bank($code)
            ?? throw ValidationException::withMessages(['currency' => __('invoices.errors.bank_missing', ['currency' => $code])]);

        $day = $locked->issue_date?->format('Y-m-d') ?? throw new LogicException('An issued document has its issue date.');
        $rate = null;

        if ($code !== 'CZK') {
            try {
                $rate = $this->rates->rateFor($code, $day);
            } catch (ExchangeRateMissing) {
                throw ValidationException::withMessages(['currency' => __('invoices.errors.cnb_missing', ['currency' => $code, 'date' => IssueChecks::czechDate($day)])]);
            }
        }

        return ['currency' => $code, 'bank' => $bank, 'rate' => $rate];
    }

    /**
     * Prices the time lines again for the new currency with the lists of the issue date (R-10.1-08-1). The row is not
     * saved here, the Action saves it once with everything else. A list missing for the rate of a line is the same
     * refusal as a missing list of the new currency.
     *
     * @throws ValidationException `currency` for a missing list, `items`, `total_not_positive`
     */
    private function convertAgain(Invoice $locked, string $day): void
    {
        try {
            TimeLineConversion::freezeAt($locked, $day, true, false);
        } catch (ValidationException $exception) {
            $missing = $exception->errors()['cnb_missing'] ?? null;

            if ($missing === null) {
                throw $exception;
            }

            throw ValidationException::withMessages(['currency' => $missing]);
        }
    }

    /**
     * Stores the exchange figures of the new currency on the Admin-only row, computed once from the new total: the
     * CNB rate, unit, list date and the CZK total, or all four empty for CZK (D-12, IN-07).
     *
     * @param  array{rate: string, unit_amount: int, rate_date: string}|null  $rate
     */
    private function storeExchangeFigures(Invoice $locked, ?array $rate, Money $total): void
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->lockForUpdate()->first()
            ?? throw new LogicException('An issued document has its Admin-only row.');

        $admin->forceFill($rate === null
            ? ['exchange_rate' => null, 'exchange_unit_amount' => null, 'exchange_rate_date' => null, 'czk_total_minor' => null]
            : [
                'exchange_rate' => $rate['rate'],
                'exchange_unit_amount' => $rate['unit_amount'],
                'exchange_rate_date' => $rate['rate_date'],
                'czk_total_minor' => $total->convert($rate['rate'], 'CZK', $rate['unit_amount'])->minor,
            ])->save();
    }

    /**
     * The price a generated line has while the change of currency is being written: a rate in the new currency exactly,
     * any other line the stored figure, which {@see TimeLineConversion::freezeAt()} replaces right after.
     *
     * @return Closure(InvoiceItemSource, InvoiceItem, InvoiceQuantity, array<string, array{rate: string, unit_amount: int, rate_date: string|null}> &): array{0: Money, 1: Money, 2: bool}
     */
    private function placeholderPrice(string $currency): Closure
    {
        return static function (InvoiceItemSource $source, InvoiceItem $stored, InvoiceQuantity $quantity, array &$quotes) use ($currency): array {
            $rate = $source->sourceRate() ?? throw new LogicException('A generated line has its source rate.');
            $unitPrice = $rate->currency === $currency ? $rate : Money::ofMinor($stored->unit_price_minor, $currency);

            try {
                return [$unitPrice, DraftTotals::lineTotal($unitPrice, $quantity), false];
            } catch (OverflowException) {
                throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
            }
        };
    }

    /**
     * How a generated line is priced on an issued document: in the currency of the document the stored unit price
     * times the new quantity; in another currency the quotes frozen at issue (research Pitfall 6).
     *
     * @return Closure(InvoiceItemSource, InvoiceItem, InvoiceQuantity, array<string, array{rate: string, unit_amount: int, rate_date: string|null}> &): array{0: Money, 1: Money, 2: bool}
     */
    private function issuedPrice(string $currency): Closure
    {
        return static function (InvoiceItemSource $source, InvoiceItem $stored, InvoiceQuantity $quantity, array &$quotes) use ($currency): array {
            $rate = $source->sourceRate() ?? throw new LogicException('A generated line has its source rate.');

            try {
                if ($rate->currency !== $currency) {
                    $snapshot = $source->conversion_snapshot ?? throw new LogicException('A converted line of an issued document has its conversion snapshot.');
                    $converted = TimeLineConversion::fromSnapshot($rate, $quantity, $snapshot);

                    return [$converted['unit_price'], $converted['line_total'], true];
                }

                $unitPrice = Money::ofMinor($stored->unit_price_minor, $currency);

                return [$unitPrice, DraftTotals::lineTotal($unitPrice, $quantity), false];
            } catch (OverflowException) {
                throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
            }
        };
    }

    /**
     * The rule for the lines a credit note credits, draft or issued (assumption A4): the quantity, the unit and the
     * unit price stay, the line stays, only the text changes. A draft credit note counts as well, because the key
     * from its line to the original would refuse the delete and the issue must meet the line it was drafted against.
     *
     * @return Closure(?InvoiceItem, ?InvoiceItemInput, int): void
     */
    private function creditedLineRule(Invoice $locked): Closure
    {
        $credited = IssuedDocumentRules::creditedLineIds($locked);

        return static function (?InvoiceItem $stored, ?InvoiceItemInput $input, int $index) use ($credited): void {
            if (! $stored instanceof InvoiceItem || ! isset($credited[$stored->id])) {
                return;
            }

            if (! $input instanceof InvoiceItemInput) {
                throw ValidationException::withMessages(['items' => __('invoices.errors.credited_line_delete')]);
            }

            if (! $input->quantity->equals($stored->quantity())
                || $input->unit !== $stored->unit
                || $input->unitPrice->minor !== $stored->unit_price_minor) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => __('invoices.errors.credited_line_frozen')]);
            }
        };
    }

    /**
     * @throws ValidationException
     */
    private function note(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_string($value) ? trim($value) : '';

        if (mb_strlen($text) > self::NOTE_MAX_LENGTH) {
            throw ValidationException::withMessages(['note' => __('invoices.errors.note_length')]);
        }

        return $text === '' ? null : $text;
    }
}
