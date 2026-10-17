<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A transaction can be booked at an exchange rate of its own (D-06): the caller gives the rate for this one
 * transaction and the CZK figure is the amount converted at it. The source is 'custom'. Like a CNB conversion
 * it keeps the rate, the unit amount and the rate date that produced the figure, so what was booked can always
 * be verified; the own rate is never written to the exchange_rates table.
 *
 * Only transactions_czk_source_check and transactions_snapshot_check are replaced, by name. The identity check
 * ((amount_currency = 'CZK') = (czk_source = 'identity')) already refuses 'custom' on a CZK row and accepts it
 * on a foreign row, so it stays as it is.
 *
 * The file name sorts before the Phase 10 migrations (2026_10_17_*), which build on the transactions table.
 *
 * down() turns the own-rate rows into manual rows with an empty snapshot (the CZK figure is kept) before the
 * original checks are restored, so no row violates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_czk_source_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_czk_source_check CHECK (czk_source IN ('identity', 'cnb', 'manual', 'custom'))");

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_snapshot_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_snapshot_check CHECK (CASE WHEN czk_source IN ('cnb', 'custom') THEN exchange_rate IS NOT NULL AND exchange_unit_amount IS NOT NULL AND exchange_rate_date IS NOT NULL AND exchange_rate > 0 AND exchange_unit_amount > 0 ELSE exchange_rate IS NULL AND exchange_unit_amount IS NULL AND exchange_rate_date IS NULL END)");
    }

    public function down(): void
    {
        DB::statement("UPDATE transactions SET czk_source = 'manual', exchange_rate = NULL, exchange_unit_amount = NULL, exchange_rate_date = NULL WHERE czk_source = 'custom'");

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_czk_source_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_czk_source_check CHECK (czk_source IN ('identity', 'cnb', 'manual'))");

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_snapshot_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_snapshot_check CHECK (CASE WHEN czk_source = 'cnb' THEN exchange_rate IS NOT NULL AND exchange_unit_amount IS NOT NULL AND exchange_rate_date IS NOT NULL AND exchange_rate > 0 AND exchange_unit_amount > 0 ELSE exchange_rate IS NULL AND exchange_unit_amount IS NULL AND exchange_rate_date IS NULL END)");
    }
};
