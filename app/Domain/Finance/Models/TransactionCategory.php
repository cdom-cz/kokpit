<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * A category of the finance module: a root with a type, or one child of a root (two levels, D-15).
 *
 * Finance is closed to Partners: DeniesPartners and the admin-only policy leave a Partner no row and no
 * ability. The tree rules live in the database (composite keys, unique names, the freeze of the system row), not
 * here: the model only reads and orders.
 *
 * The one system income category (system_key `invoice_payments`) is booked into by Phase 10. It can be renamed
 * and reordered but never archived, moved or deleted; the guard trigger of the table refuses it.
 *
 * Only the name is fillable. The type, parent, depth and the system columns are set with `forceFill` by trusted
 * code, so a request can never move a category or change its type by mass assignment.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property TransactionType $type
 * @property int $depth
 * @property string $name
 * @property bool $is_system
 * @property string|null $system_key
 * @property CarbonImmutable|null $archived_at
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
final class TransactionCategory extends KokpitModel implements PartnerIsolated, Sortable
{
    use DeniesPartners, SortableTrait;

    /** The system key of the income category Phase 10 books invoice payments into. */
    public const string SYSTEM_INVOICE_PAYMENTS = 'invoice_payments';

    protected $table = 'transaction_categories';

    /**
     * The order column and the creation behaviour (spatie/eloquent-sortable): a new category goes to the end
     * of its siblings.
     *
     * @var array{order_column_name: string, sort_when_creating: bool}
     */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => true,
    ];

    /**
     * The siblings the order is kept within: the same type and the same parent (roots of one type together).
     *
     * @return Builder<TransactionCategory>
     */
    public function buildSortQuery(): Builder
    {
        $query = self::query()->where('type', $this->type->value);

        return $this->parent_id === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $this->parent_id);
    }

    /**
     * The system category invoice payments are booked into.
     */
    public static function invoicePayments(): self
    {
        return self::query()->where('system_key', self::SYSTEM_INVOICE_PAYMENTS)->sole();
    }

    /**
     * The categories a picker may offer for a transaction of this type: not archived, and not a child of an
     * archived parent (D-15). Every category picker uses this scope instead of repeating the rule. The Partner
     * scope still applies on top of it.
     *
     * @param  Builder<TransactionCategory>  $query
     */
    public function scopeSelectable(Builder $query, TransactionType $type): void
    {
        $table = $this->getTable();

        $query
            ->where("{$table}.type", $type->value)
            ->whereNull("{$table}.archived_at")
            ->where(static fn (Builder $inner) => $inner
                ->whereNull("{$table}.parent_id")
                ->orWhereExists(static fn ($sub) => $sub->selectRaw('1')
                    ->from("{$table} as parent")
                    ->whereColumn('parent.id', "{$table}.parent_id")
                    ->whereNull('parent.archived_at')));
    }

    /**
     * An archived category keeps its history but is not offered for new transactions.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<TransactionCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'depth' => 'integer',
            'is_system' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'position' => 'integer',
        ];
    }
}
