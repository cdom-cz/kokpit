<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One income or expense of the finance module (D-12, D-13).
 *
 * Finance is closed to Partners: DeniesPartners and the admin-only policy leave a Partner no row and no
 * ability. The amount is stored positive and the type gives the direction; the keys and checks of the table
 * (category of the same type, project of the chosen client, money and snapshot consistency) live in the
 * database, not here: the model only reads.
 *
 * `amount` is the virtual Money attribute over `amount_minor` and `amount_currency`. `czk_minor` is the CZK
 * figure and `czk_source` says where it came from (identity, cnb or manual); for a CNB conversion the three
 * `exchange_*` columns keep the rate that was used. They are a snapshot, never looked up again. `czk_source`
 * casts to {@see CzkSource} and `czk()` reads the figure as Money; the write Actions set both.
 *
 * Only the free text is fillable. The type, category, amounts, CZK figure, snapshot, client and project are set
 * with `forceFill` by trusted code (the Actions), so a request can never write them by mass assignment.
 *
 * @property string $id
 * @property TransactionType $type
 * @property CarbonImmutable $transaction_date
 * @property string $category_id
 * @property Money $amount
 * @property int $amount_minor
 * @property string $amount_currency
 * @property int $czk_minor
 * @property CzkSource $czk_source
 * @property numeric-string|null $exchange_rate
 * @property int|null $exchange_unit_amount
 * @property CarbonImmutable|null $exchange_rate_date
 * @property string|null $description
 * @property string|null $counterparty
 * @property string|null $document_number
 * @property string|null $client_id
 * @property string|null $project_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['description', 'counterparty', 'document_number'])]
final class Transaction extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    protected $table = 'transactions';

    /**
     * The CZK figure of the transaction (D-13): the amount itself for CZK, the CNB conversion kept as a
     * snapshot, or the figure the Admin typed.
     */
    public function czk(): Money
    {
        return Money::ofMinor($this->czk_minor, 'CZK');
    }

    /**
     * The category, archived or not: an archived category stays on the transactions that used it.
     *
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    /**
     * The client, archived ones included.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * The project, archived ones included.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'transaction_date' => 'immutable_date',
            'amount' => MoneyCast::class,
            'amount_minor' => 'integer',
            'czk_minor' => 'integer',
            'czk_source' => CzkSource::class,
            'exchange_rate' => 'decimal:10',
            'exchange_unit_amount' => 'integer',
            'exchange_rate_date' => 'immutable_date',
        ];
    }
}
