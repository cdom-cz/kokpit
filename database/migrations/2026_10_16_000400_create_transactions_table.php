<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The transactions of the finance module (FI-01, D-12, D-13): one row per income or expense. Admin-only data;
 * the model (DeniesPartners) and its policy keep a Partner away.
 *
 * Money is stored positive and the type gives the direction. The amount is the usual column pair (`amount_minor`
 * and `amount_currency`) and must be above zero. `czk_minor` is the CZK figure of the same transaction; it may be
 * zero because a tiny foreign amount can round to zero crown. `czk_source` says where that figure came from:
 *   identity - the amount is already in CZK and `czk_minor` equals `amount_minor`;
 *   cnb      - converted with a CNB rate, and the three snapshot columns (`exchange_rate`, `exchange_unit_amount`,
 *              `exchange_rate_date`) hold the rate that was used;
 *   manual   - the Admin overwrote the figure; no rate belongs to it.
 * The snapshot is written once and never looked up again (Phase 8 EX-03): a later correction of a published rate
 * does not move a booked transaction. The CHECKs keep the source, the currency, the amounts and the snapshot
 * consistent whatever code writes the row. The conversion rule itself and the input validation live in the Actions.
 *
 * The category must be of the same type as the transaction: `(category_id, type)` references the
 * `(id, type)` key of the categories. The project must belong to the chosen client: `(project_id, client_id)`
 * references the `(id, client_id)` key of the projects, and a project without a client is refused by a CHECK
 * because the composite key is skipped when `client_id` is null.
 *
 * All keys use NO ACTION (the default): a category that a transaction uses and a client or project it references
 * cannot be deleted, and every refusal answers 23503. Archiving a category, client or project never touches the
 * row. The link to an invoice is added in Phase 10.
 *
 * `type` belongs to the composite category key and is not an identifier; the schema convention test exempts it
 * with that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->string('type', 16);
            $table->date('transaction_date');
            $table->uuid('category_id');

            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->bigInteger('czk_minor');
            $table->string('czk_source', 16);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->integer('exchange_unit_amount')->nullable();
            $table->date('exchange_rate_date')->nullable();

            $table->text('description')->nullable();
            $table->string('counterparty', 255)->nullable();
            $table->string('document_number', 64)->nullable();

            $table->foreignUuid('client_id')->nullable()->constrained('clients');
            $table->uuid('project_id')->nullable();

            $table->timestampsTz();

            $table->index(['transaction_date', 'id'], 'transactions_transaction_date_id_index');
            $table->index(['client_id', 'transaction_date'], 'transactions_client_id_transaction_date_index');
            $table->index('category_id', 'transactions_category_id_index');
            $table->index('project_id', 'transactions_project_id_index');
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ('income', 'expense'))");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_check CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_czk_check CHECK (czk_minor >= 0)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_currency_check CHECK (amount_currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_czk_source_check CHECK (czk_source IN ('identity', 'cnb', 'manual'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_identity_check CHECK ((amount_currency = 'CZK') = (czk_source = 'identity') AND (czk_source <> 'identity' OR czk_minor = amount_minor))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_snapshot_check CHECK (CASE WHEN czk_source = 'cnb' THEN exchange_rate IS NOT NULL AND exchange_unit_amount IS NOT NULL AND exchange_rate_date IS NOT NULL AND exchange_rate > 0 AND exchange_unit_amount > 0 ELSE exchange_rate IS NULL AND exchange_unit_amount IS NULL AND exchange_rate_date IS NULL END)");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_project_needs_client_check CHECK (project_id IS NULL OR client_id IS NOT NULL)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_description_check CHECK (description IS NULL OR length(description) <= 2000)');

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_category_type_fk FOREIGN KEY (category_id, type) REFERENCES transaction_categories (id, type)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_project_client_fk FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
