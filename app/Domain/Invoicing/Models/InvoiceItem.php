<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One printed line of a billing document (IN-01). A line is Partner-readable because it is printed on the
 * document the client receives (R-05-2): its description, quantity, unit price and total. The rates, source
 * rates and CNB data behind a converted price never live in this table.
 *
 * A Partner reads a line exactly when its document is visible to them: the constraint delegates to the
 * Partner-scoped {@see Invoice} query, so the rules (own client, issued, client not archived) live in one place
 * (IN-09, U-3), the way a task follows its project. A draft never exists for a Partner, so neither do its lines.
 *
 * The lines of an issued document accept no write (the database child guard, plan 10-04). Only the printed text
 * is fillable; the document, position, quantity, amounts, VAT and the credit link are set with `forceFill` by
 * the Actions. The quantity is kept as the exact decimal text of the column; {@see InvoiceItem::quantity()}
 * reads it as an {@see InvoiceQuantity}. Money is read in the currency of the parent document.
 *
 * @property string $id
 * @property string $invoice_id
 * @property int $position
 * @property string $description
 * @property numeric-string $quantity
 * @property string $unit
 * @property int $unit_price_minor
 * @property int $line_total_minor
 * @property bool $price_converted
 * @property numeric-string|null $vat_rate
 * @property int $vat_minor
 * @property string|null $credited_item_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['description', 'unit'])]
final class InvoiceItem extends KokpitModel implements PartnerIsolated
{
    use IsolatesPartners;

    /**
     * The lines of the Partner's visible documents. The scoped Invoice query applies the Partner constraint
     * itself.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query->whereIn($this->qualifyColumn('invoice_id'), Invoice::query()->select('invoices.id'));
    }

    /**
     * The exact quantity of the line.
     */
    public function quantity(): InvoiceQuantity
    {
        return InvoiceQuantity::fromDecimal($this->quantity);
    }

    /**
     * The printed unit price, in the currency of the document.
     */
    public function unitPrice(): Money
    {
        return Money::ofMinor($this->unit_price_minor, $this->currency());
    }

    /**
     * The line total, in the currency of the document.
     */
    public function lineTotal(): Money
    {
        return Money::ofMinor($this->line_total_minor, $this->currency());
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * How the line was generated: origin, source rate, seconds and conversion snapshot (D-12). Admin-only, so null
     * for a Partner.
     *
     * @return HasOne<InvoiceItemSource, $this>
     */
    public function source(): HasOne
    {
        return $this->hasOne(InvoiceItemSource::class);
    }

    /**
     * The line of the credited invoice that a credit line takes back.
     *
     * @return BelongsTo<InvoiceItem, $this>
     */
    public function creditedItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credited_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'price_converted' => 'boolean',
            'vat_rate' => 'decimal:2',
            'vat_minor' => 'integer',
        ];
    }

    private function currency(): string
    {
        $invoice = $this->getRelationValue('invoice');

        if (! $invoice instanceof Invoice) {
            throw new LogicException('The document of an invoice line is not readable, so its currency is unknown.');
        }

        return $invoice->currency;
    }
}
