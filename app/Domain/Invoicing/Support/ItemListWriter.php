<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Shared\Money\Money;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use OverflowException;

/**
 * Replaces the whole list of lines of a document and sums it up: the one piece of line writing that a draft save
 * ({@see UpdateDraft}) and the correction of an open issued document
 * ({@see UpdateIssuedDocument}) share (R-10.1-04-1, extracted from the draft save
 * without a change of behaviour).
 *
 * Lines are matched by id against the locked document's own lines (R-11-3): a line with an id of the document is
 * updated and keeps its row and its source, a line without one is inserted as a manual line, a stored line missing
 * from the list is deleted with its source, and the positions follow the order of the list. An id that does not belong
 * to the document, or that is sent twice, is the field error `items.<n>.id` and nothing is written (T-10.1-14). Every
 * line total is computed here with the one rounding rule ({@see DraftTotals::lineTotal()}, HALF_UP), never read from
 * the data (T-10-28); the totals are the sum of the stored line totals.
 *
 * What differs between the two callers is passed in: how a line generated from time is priced
 * (`$generatedPrice`, the draft previews with the lists of today, an issued document keeps its frozen conversion) and
 * an optional rule for kept and deleted lines (`$lineRule`) that may refuse before any write.
 *
 * The caller holds the transaction and the row lock of the document; this class writes only `invoice_items` and
 * `invoice_item_sources`, never the document row.
 */
final readonly class ItemListWriter
{
    /**
     * Where a kept line is parked while the positions are reassigned, so the unique key of (document, position)
     * is never hit by a reorder. Far above the number of lines a document may hold.
     */
    public const int PARKED_POSITION = 1_000_000;

    /**
     * Replaces the lines of the locked document by the list: update by id, insert, delete the missing, order by the
     * list.
     *
     * @param  array<array-key, mixed>  $items  the lines as the editor sends them (see {@see InvoiceItemInput})
     * @param  Closure(InvoiceItemSource, InvoiceItem, InvoiceQuantity, array<string, array{rate: string, unit_amount: int, rate_date: string|null}> &): array{0: Money, 1: Money, 2: bool}  $generatedPrice  the unit price, the amount and the converted flag of a generated line for the new quantity; the last argument collects the quotes found so far
     * @param  (Closure(?InvoiceItem, ?InvoiceItemInput, int): void)|null  $lineRule  called for every kept line (stored line, input, index) and every deleted line (stored line, null, index of its position) before anything is written; may throw a {@see ValidationException}
     *
     * @throws ValidationException
     */
    public function replace(Invoice $locked, array $items, string $currency, bool $currencyChanged, Closure $generatedPrice, ?Closure $lineRule = null): void
    {
        $inputs = InvoiceItemInput::list($items, $currency, $locked->kind, (int) config('kokpit.invoicing.max_items'));

        /** @var Collection<string, InvoiceItem> $existing */
        $existing = InvoiceItem::query()->where('invoice_id', $locked->id)->with('source')->get()->keyBy('id');

        $kept = $this->assertOwnLines($inputs, $existing);

        if ($lineRule instanceof Closure) {
            foreach ($inputs as $index => $input) {
                if ($input->id !== null) {
                    $lineRule($existing->get($input->id), $input, $index);
                }
            }

            foreach ($existing as $item) {
                if (! isset($kept[$item->id])) {
                    $lineRule($item, null, $item->position - 1);
                }
            }
        }

        foreach ($existing as $item) {
            if (! isset($kept[$item->id])) {
                $item->source?->delete();
                $item->delete();
            }
        }

        // Park every kept line whose position changes, so no reorder ever meets the unique (document, position).
        foreach ($inputs as $index => $input) {
            $item = $input->id === null ? null : $existing->get($input->id);

            if ($item instanceof InvoiceItem && $item->position !== $index + 1) {
                $item->forceFill(['position' => self::PARKED_POSITION + $index + 1])->save();
            }
        }

        $quotes = [];

        foreach ($inputs as $index => $input) {
            $this->writeLine($locked, $input, $input->id === null ? null : $existing->get($input->id), $index + 1, $currencyChanged, $generatedPrice, $quotes);
        }
    }

    /**
     * The subtotal, VAT and total from the stored line totals, never from a unit price (D-09).
     *
     * @return array{subtotal: Money, vat: Money, total: Money}
     *
     * @throws ValidationException when the sum does not fit
     */
    public function totals(Invoice $locked, string $currency): array
    {
        /** @var list<int> $minors */
        $minors = InvoiceItem::query()->where('invoice_id', $locked->id)->pluck('line_total_minor')->map(static fn (mixed $minor): int => (int) $minor)->all();

        try {
            return DraftTotals::for(array_map(static fn (int $minor): Money => Money::ofMinor($minor, $currency), $minors), $currency);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
        }
    }

    /**
     * Whether the price of the line follows its hourly rate: generated from time, the price not typed by hand.
     */
    public static function isGenerated(InvoiceItemSource $source): bool
    {
        return $source->origin === InvoiceItemOrigin::Time && ! $source->price_manual && $source->sourceRate() instanceof Money;
    }

    /**
     * Every id of the list must be a line of this document, once. Line totals that do not fit are a field error too.
     *
     * @param  list<InvoiceItemInput>  $inputs
     * @param  Collection<string, InvoiceItem>  $existing
     * @return array<string, true> the ids kept
     *
     * @throws ValidationException
     */
    private function assertOwnLines(array $inputs, Collection $existing): array
    {
        $kept = [];
        $errors = [];

        foreach ($inputs as $index => $input) {
            if ($input->id !== null) {
                if (! $existing->has($input->id) || isset($kept[$input->id])) {
                    $errors["items.{$index}.id"] = [__('invoices.errors.item_unknown')];
                }

                $kept[$input->id] = true;
            }

            try {
                DraftTotals::lineTotal($input->unitPrice, $input->quantity);
            } catch (OverflowException) {
                $errors["items.{$index}.unit_price"] = [__('invoices.errors.item_price')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $kept;
    }

    /**
     * @param  Closure(InvoiceItemSource, InvoiceItem, InvoiceQuantity, array<string, array{rate: string, unit_amount: int, rate_date: string|null}> &): array{0: Money, 1: Money, 2: bool}  $generatedPrice
     * @param  array<string, array{rate: string, unit_amount: int, rate_date: string|null}>  $quotes  the quotes found so far, filled in
     *
     * @throws ValidationException
     */
    private function writeLine(Invoice $locked, InvoiceItemInput $input, ?InvoiceItem $stored, int $position, bool $currencyChanged, Closure $generatedPrice, array &$quotes): void
    {
        $item = $stored ?? new InvoiceItem;
        $source = $stored instanceof InvoiceItem ? $stored->source : null;
        $generated = $stored instanceof InvoiceItem && $source instanceof InvoiceItemSource && self::isGenerated($source);

        // The price a generated line shows is the preview, not a typed price; on a change of currency the editor
        // cannot know the new preview, so the figure it sends for such a line is never read as typed.
        $priceChanged = $stored instanceof InvoiceItem
            && ! ($generated && $currencyChanged)
            && $stored->unit_price_minor !== $input->unitPrice->minor;

        $unitPrice = $input->unitPrice;
        $lineTotal = DraftTotals::lineTotal($unitPrice, $input->quantity);
        $converted = false;

        if ($generated && ! $priceChanged) {
            [$unitPrice, $lineTotal, $converted] = $generatedPrice($source, $stored, $input->quantity, $quotes);
        }

        $item->fill(['description' => $input->description, 'unit' => $input->unit]);
        $item->forceFill([
            'invoice_id' => $locked->id,
            'position' => $position,
            'quantity' => $input->quantity->toDecimal(),
            'unit_price_minor' => $unitPrice->minor,
            'line_total_minor' => $lineTotal->minor,
            'price_converted' => $converted,
            'vat_rate' => null,
            'vat_minor' => 0,
        ]);
        $item->save();

        if (! $source instanceof InvoiceItemSource) {
            $source = new InvoiceItemSource;
            $source->forceFill([
                'invoice_item_id' => $item->id,
                'invoice_id' => $locked->id,
                'origin' => InvoiceItemOrigin::Manual,
                'price_manual' => true,
            ])->save();

            return;
        }

        if ($priceChanged && ! $source->price_manual) {
            $source->forceFill(['price_manual' => true])->save();
        }
    }
}
