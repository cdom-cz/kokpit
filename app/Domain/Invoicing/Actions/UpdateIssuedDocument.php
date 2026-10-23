<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceAudit;
use App\Domain\Invoicing\Support\InvoiceItemInput;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
use App\Domain\Invoicing\Support\IssuedEditFinisher;
use App\Domain\Invoicing\Support\ItemListWriter;
use App\Domain\Invoicing\Support\TimeLineConversion;
use App\Domain\Shared\Money\Money;
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

            $note = array_key_exists('note', $data) ? $this->note($data['note']) : $locked->note;
            $currency = $locked->currency;

            if (array_key_exists('items', $data)) {
                if (! is_array($data['items'])) {
                    throw new InvalidArgumentException('The items of a document are a list of lines.');
                }

                $this->writer->replace($locked, $data['items'], $currency, false, $this->issuedPrice($currency), $this->creditedLineRule($locked));
            }

            $totals = $this->writer->totals($locked, $currency);

            if (! $totals['total']->isPositive()) {
                throw ValidationException::withMessages(['total_not_positive' => __('invoices.errors.total_not_positive')]);
            }

            if (array_key_exists('internal_note', $data)) {
                $this->internalNote->handle($actor, $locked, is_string($data['internal_note']) ? $data['internal_note'] : null);
            }

            if (! array_key_exists('items', $data) && ! array_key_exists('note', $data)) {
                return $locked->refresh();
            }

            $locked->fill(['note' => $note]);
            $locked->forceFill([
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

            $this->finisher->finish($actor, $locked, $before);

            return $locked->refresh();
        });
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
        /** @var array<array-key, true> $credited */
        $credited = InvoiceItem::query()
            ->whereIn('credited_item_id', InvoiceItem::query()->where('invoice_id', $locked->id)->select('id'))
            ->distinct()
            ->pluck('credited_item_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();

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
