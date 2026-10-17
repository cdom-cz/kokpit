<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The billing documents (IN-01, IN-03, IN-08, IN-09): `invoices` holds invoices, proformas and credit notes, and
 * `invoice_items` their lines. Both tables are Partner-readable for issued documents, so they hold only what the
 * document prints and its links to other issued documents; rates, CNB and CZK figures, the internal note, payments,
 * the PDF link and the billing sources live in Admin-only tables (plan 10-06). The column lists are pinned in
 * PartnerSafeColumnsTest.
 *
 * An issued row accepts no write at all (D-13, ruling R-04-6). The generic frozen guard of `invoices` is created
 * with an empty list of mutable columns and without the implied `updated_at`: once `state` is `issued`, every
 * UPDATE (one that changes only `updated_at` included), every DELETE and every TRUNCATE is refused with SQLSTATE
 * KP001, whichever writer sends it, raw SQL included. The two things D-13 keeps writable, the payment state and
 * the internal note, live in Admin-only tables, and so does the link to the stored PDF. The state flip is the last
 * write of the issue pipeline on this row. The items follow their document: the child guard refuses INSERT, UPDATE
 * and DELETE of an item while the document is not a draft (D-03), so a draft stays freely editable.
 *
 * The storno marker is the credit note itself (D-14, ruling R-04-5). `cancels_invoice` is true only on the
 * full-storno credit note, at most one per credited invoice, and the credit note freezes at its own issue. An
 * invoice is cancelled exactly when an issued credit note with `cancels_invoice` names it: nothing is written on
 * the cancelled invoice, so the storno can be neither reverted nor repeated, not even by raw SQL.
 *
 * No generated column on a guarded table: PostgreSQL computes it after the BEFORE trigger, so NEW would hold NULL
 * and the guard would see a change.
 *
 * Keys use NO ACTION (the default): a draft is deleted by its own action after its items, so the guard always
 * finds the parent, and every refusal answers 23503.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('client_id')->constrained('clients');

            $table->string('kind', 16);
            $table->string('state', 16)->default('draft');
            $table->string('number', 32)->nullable();
            $table->string('variable_symbol', 10)->nullable();

            $table->char('currency', 3);
            $table->string('language', 2);
            $table->string('vat_mode', 16)->default('non_payer');

            $table->date('issue_date')->nullable();
            $table->date('taxable_supply_date')->nullable();
            $table->date('due_date')->nullable();

            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);

            $table->string('note', 500)->nullable();

            $table->jsonb('supplier_snapshot')->nullable();
            $table->jsonb('customer_snapshot')->nullable();
            $table->jsonb('bank_snapshot')->nullable();
            $table->jsonb('appearance_snapshot')->nullable();

            // Both links are composite foreign keys added below.
            $table->uuid('proforma_id')->nullable();
            $table->uuid('credited_invoice_id')->nullable();
            $table->boolean('cancels_invoice')->default(false);

            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();

            $table->index(['client_id', 'state'], 'invoices_client_id_state_index');
            $table->index(['state', 'kind', 'due_date'], 'invoices_state_kind_due_date_index');
            $table->index('variable_symbol', 'invoices_variable_symbol_index');
        });

        // Targets of the composite foreign keys: a document, a proforma, a credited invoice and (plan 10-06) a
        // payment can only point at a document of the same client or currency.
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_id_client_unique UNIQUE (id, client_id)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_id_currency_unique UNIQUE (id, currency)');

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_kind_check CHECK (kind IN ('invoice', 'proforma', 'credit_note'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_state_check CHECK (state IN ('draft', 'issued'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_number_state_check CHECK ((state = 'draft') = (number IS NULL))");

        DB::statement('CREATE UNIQUE INDEX invoices_kind_number_unique ON invoices (kind, number) WHERE number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX invoices_one_invoice_per_proforma ON invoices (proforma_id) WHERE proforma_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX invoices_one_storno_per_invoice ON invoices (credited_invoice_id) WHERE cancels_invoice');

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_proforma_client_fk FOREIGN KEY (proforma_id, client_id) REFERENCES invoices (id, client_id)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_credited_client_fk FOREIGN KEY (credited_invoice_id, client_id) REFERENCES invoices (id, client_id)');

        DB::unprepared(Immutability::guardTriggerSql('invoices', 'state', 'draft', [], withUpdatedAt: false));
        DB::unprepared(Immutability::truncateGuardSql('invoices'));

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('invoice_id')->constrained('invoices');

            $table->integer('position');
            $table->string('description', 500);
            $table->decimal('quantity', 14, 4);
            $table->string('unit', 16);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
            $table->boolean('price_converted')->default(false);

            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->bigInteger('vat_minor')->default(0);

            // The credit line points at the line it credits; a self-reference, so the foreign key is added below.
            $table->uuid('credited_item_id')->nullable();

            $table->timestampsTz();

            $table->unique(['invoice_id', 'position'], 'invoice_items_invoice_id_position_unique');
        });

        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_id_invoice_unique UNIQUE (id, invoice_id)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_credited_item_id_foreign FOREIGN KEY (credited_item_id) REFERENCES invoice_items (id)');
        DB::statement('CREATE INDEX invoice_items_credited_item_id_index ON invoice_items (credited_item_id) WHERE credited_item_id IS NOT NULL');

        DB::unprepared(Immutability::childGuardTriggerSql('invoice_items', 'invoice_id', 'invoices', 'state', 'draft'));
        DB::unprepared(Immutability::truncateGuardSql('invoice_items'));
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
