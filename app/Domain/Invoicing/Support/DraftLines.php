<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Shared\Money\Money;
use Illuminate\Validation\ValidationException;
use OverflowException;

/**
 * The writes that add generated lines to a draft and keep its totals right (IN-02, D-06, D-07): the one place that
 * appends a line with its source, shared by the draft made from time, the time added later and the fixed amount, so
 * they cannot drift apart. Lines are only ever appended after the last position; an existing line is never read,
 * rewritten or reordered here (D-06). The caller holds the transaction and the row lock of the draft.
 *
 * Totals are the plain sum of the stored line totals, the rule of {@see DraftTotals}.
 */
final class DraftLines
{
    /**
     * Appends the lines {@see TimeItemGrouping} built, each with its time source, after the last position.
     *
     * @param  list<array{description: string, quantity: InvoiceQuantity, unit: string, unit_price: Money, line_total: Money, price_converted: bool, source: array{origin: string, project_id: ?string, task_id: ?string, source_rate_minor: int, source_rate_currency: string, source_seconds: int, needs_conversion: bool}}>  $lines
     *
     * @throws ValidationException `items` when the lines do not fit the cap
     */
    public function appendTime(Invoice $draft, array $lines): void
    {
        $this->assertRoom($draft, count($lines));

        $position = $this->lastPosition($draft);

        foreach ($lines as $line) {
            $item = $this->writeItem($draft, ++$position, $line['description'], $line['unit'], $line['quantity'], $line['unit_price'], $line['line_total'], $line['price_converted']);

            $source = new InvoiceItemSource;
            $source->forceFill([
                'invoice_item_id' => $item->id,
                'invoice_id' => $draft->id,
                'origin' => InvoiceItemOrigin::Time,
                'project_id' => $line['source']['project_id'],
                'task_id' => $line['source']['task_id'],
                'source_rate_minor' => $line['source']['source_rate_minor'],
                'source_rate_currency' => $line['source']['source_rate_currency'],
                'source_seconds' => $line['source']['source_seconds'],
                'price_manual' => false,
            ])->save();
        }
    }

    /**
     * Appends one fixed-amount line: quantity one, unit `paušál`, the typed amount as a manual price (ruling R-14-3).
     *
     * @throws ValidationException `items` when the cap is reached or the amount does not fit
     */
    public function appendFixedAmount(Invoice $draft, string $projectId, string $description, Money $amount, string $unit): InvoiceItem
    {
        $this->assertRoom($draft, 1);

        $quantity = InvoiceQuantity::fromDecimal('1');

        try {
            $total = DraftTotals::lineTotal($amount, $quantity);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['amount' => [__('invoices.errors.item_price')]]);
        }

        $item = $this->writeItem($draft, $this->lastPosition($draft) + 1, $description, $unit, $quantity, $amount, $total, false);

        $source = new InvoiceItemSource;
        $source->forceFill([
            'invoice_item_id' => $item->id,
            'invoice_id' => $draft->id,
            'origin' => InvoiceItemOrigin::FixedAmount,
            'project_id' => $projectId,
            'price_manual' => true,
        ])->save();

        return $item;
    }

    /**
     * Writes the subtotal, VAT and total of the draft from its stored lines.
     *
     * @throws ValidationException `items` when the sum does not fit
     */
    public function refreshTotals(Invoice $draft): void
    {
        $totals = $this->totals($draft);

        $draft->forceFill([
            'subtotal_minor' => $totals['subtotal']->minor,
            'vat_minor' => $totals['vat']->minor,
            'total_minor' => $totals['total']->minor,
        ])->save();
    }

    /**
     * Whether the draft's time lines are grouped per task: true when any stored time line names a task. A draft
     * with no time line yet is grouped per project, the default of the picker.
     */
    public function groupedPerTask(Invoice $draft): bool
    {
        return InvoiceItemSource::query()
            ->where('invoice_id', $draft->id)
            ->where('origin', InvoiceItemOrigin::Time->value)
            ->whereNotNull('task_id')
            ->exists();
    }

    /**
     * @throws ValidationException `items`
     */
    private function assertRoom(Invoice $draft, int $adding): void
    {
        $max = (int) config('kokpit.invoicing.max_items');

        if (InvoiceItem::query()->where('invoice_id', $draft->id)->count() + $adding > $max) {
            throw ValidationException::withMessages(['items' => [__('invoices.errors.items_cap', ['max' => $max])]]);
        }
    }

    private function lastPosition(Invoice $draft): int
    {
        return (int) InvoiceItem::query()->where('invoice_id', $draft->id)->max('position');
    }

    private function writeItem(Invoice $draft, int $position, string $description, string $unit, InvoiceQuantity $quantity, Money $unitPrice, Money $lineTotal, bool $converted): InvoiceItem
    {
        $item = new InvoiceItem;
        $item->fill(['description' => $description, 'unit' => $unit]);
        $item->forceFill([
            'invoice_id' => $draft->id,
            'position' => $position,
            'quantity' => $quantity->toDecimal(),
            'unit_price_minor' => $unitPrice->minor,
            'line_total_minor' => $lineTotal->minor,
            'price_converted' => $converted,
            'vat_rate' => null,
            'vat_minor' => 0,
        ]);
        $item->save();

        return $item;
    }

    /**
     * @return array{subtotal: Money, vat: Money, total: Money}
     *
     * @throws ValidationException
     */
    private function totals(Invoice $draft): array
    {
        /** @var list<int> $minors */
        $minors = InvoiceItem::query()->where('invoice_id', $draft->id)->pluck('line_total_minor')->map(static fn (mixed $minor): int => (int) $minor)->all();

        try {
            return DraftTotals::for(array_map(static fn (int $minor): Money => Money::ofMinor($minor, $draft->currency), $minors), $draft->currency);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => [__('invoices.errors.item_price')]]);
        }
    }
}
