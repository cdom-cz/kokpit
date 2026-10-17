<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one printed line was generated (IN-03, D-12): its origin (time, fixed amount or manual), the project and
 * task it was billed from, the hourly rate it was priced with, the exact seconds, whether the price was typed, and
 * the conversion snapshot. One row per line, written while the document is a draft and frozen with it.
 *
 * Admin-only: the source rate and the seconds are rates of the freelancer and measured time, never shown to a
 * Partner. DeniesPartners and the admin-only policy leave a Partner no row, so `$item->source` of a line a Partner
 * may read is null (IN-09, R-08-1). The relation writes nothing to its parent: there is no `$touches`, a frozen
 * document refuses even an `updated_at` write (R-04-6).
 *
 * Nothing is fillable: the Actions set every column with `forceFill`. The model keeps no history of its own; the
 * rows are frozen by the database once the document is issued.
 *
 * @property string $id
 * @property string $invoice_item_id
 * @property string $invoice_id
 * @property InvoiceItemOrigin $origin
 * @property string|null $project_id
 * @property string|null $task_id
 * @property int|null $source_rate_minor
 * @property string|null $source_rate_currency
 * @property int|null $source_seconds
 * @property bool $price_manual
 * @property array<string, mixed>|null $conversion_snapshot
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class InvoiceItemSource extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    protected $table = 'invoice_item_sources';

    /**
     * The hourly rate the line was priced with, or null for a line without one (a fixed amount or a manual line).
     */
    public function sourceRate(): ?Money
    {
        if ($this->source_rate_minor === null || $this->source_rate_currency === null) {
            return null;
        }

        return Money::ofMinor($this->source_rate_minor, $this->source_rate_currency);
    }

    /**
     * @return BelongsTo<InvoiceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The project billed from, archived ones included.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * The task billed from, archived ones included.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => InvoiceItemOrigin::class,
            'source_rate_minor' => 'integer',
            'source_seconds' => 'integer',
            'price_manual' => 'boolean',
            'conversion_snapshot' => 'array',
        ];
    }
}
