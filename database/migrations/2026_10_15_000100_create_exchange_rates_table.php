<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The CNB exchange rates (EX-01, D-02, D-05).
 *
 * One row per currency and published CNB day, with the unit amount as published (1, 100 or 1000), so the whole
 * daily list is stored and a rate always reads together with its unit. The rate is numeric(20,10) and never a
 * float.
 *
 * Lists are immutable once published: the writers (download, backfill, gap heal) insert with ON CONFLICT DO
 * NOTHING on the unique key below, and a conflict changes nothing. The database refuses a second row for the
 * same currency and day, a currency that is not three upper-case letters, a unit amount below 1 and a rate of
 * zero or below, whatever path writes the row.
 *
 * A weekend or holiday is never stored as a copy of the previous list; it is resolved by lookup (the newest row
 * with rate_date <= the day, per currency). That keeps MAX(rate_date) meaning "the newest downloaded list" for
 * the health slot, and a later correction of a gap never has to chase copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->date('rate_date')->index('exchange_rates_rate_date_index');
            $table->char('currency', 3);
            $table->integer('unit_amount');
            $table->decimal('rate', 20, 10);
            // Whole seconds: the moment the list was downloaded, not the day it is valid for.
            $table->timestampTz('fetched_at', 0);

            $table->timestampsTz();

            $table->unique(['currency', 'rate_date'], 'exchange_rates_currency_date_unique');
        });

        DB::statement("ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_unit_amount_check CHECK (unit_amount >= 1)');
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_check CHECK (rate > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
