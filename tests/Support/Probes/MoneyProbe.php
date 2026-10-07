<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A test-only model that carries one Money attribute and one decimal rate.
 * Its table follows the column convention of D-10 and is created inside each
 * test (PostgreSQL DDL is transactional, so RefreshDatabase rolls it back).
 *
 * @property string $id
 * @property Money|null $amount
 * @property string|null $rate
 */
final class MoneyProbe extends KokpitModel
{
    protected $table = 'money_probes';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'rate' => 'decimal:10',
        ];
    }

    /**
     * Creates the probe table with the two-column money convention.
     */
    public static function provision(): void
    {
        Schema::create('money_probes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->bigInteger('amount_minor')->nullable();
            $table->char('amount_currency', 3)->nullable();
            $table->decimal('rate', 20, 10)->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE money_probes ADD CONSTRAINT money_probes_amount_currency_check CHECK (amount_currency ~ '^[A-Z]{3}$')");
    }
}
