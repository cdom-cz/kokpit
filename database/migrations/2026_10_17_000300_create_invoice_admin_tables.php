<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Admin-only half of the invoicing schema (IN-03, IN-04, IN-05, IN-10, FI-03; D-12, D-13, D-17, D-19, D-21,
 * D-23). `invoices` and `invoice_items` are Partner-readable for issued documents (plan 10-04), so everything a
 * Partner must never read lives in these four tables, whose models deny Partners: the internal note, the CNB rate
 * and the CZK total (`invoice_admin`), the origin and the rates of the billed lines (`invoice_item_sources`), the
 * payments and the sent e-mails. A Partner reaches the stored PDF only through the route of plan 10-38, which reads
 * the link as system after authorising the invoice.
 *
 * invoice_admin (one row per document, `invoice_id` unique). Its child guard follows the state of the invoice: once
 * the invoice is issued, only `internal_note`, its marker `internal_note_updated_at` (the activity log records the
 * marker instead of the note text) and `pdf_document_id` may change, and `updated_at` with them; the CNB rate, the
 * unit amount, the rate date and the CZK total, as well as the row itself, are frozen with the parent (KP001, D-13),
 * a timestamp-only write included. The rate trio is all or none, and the CZK total exists exactly with the trio.
 *
 * The PDF link (ruling R-06-6, D-21, D-23). The stored PDF is a rendering of the frozen snapshot, not document
 * data, so it is deleted after an explicit confirmation and regenerated, and the link changes after issue. It
 * lives here and not on `invoices`, so the issued invoice row takes no write. `kokpit_guard_invoice_pdf_link()`
 * lets it change in exactly two ways: it is set from null to a document owned by this very invoice while the
 * invoice is issued (a regeneration links only when the link is null), and it is cleared by the foreign key
 * `ON DELETE SET NULL` when its document is deleted. It is refused with KP001 on a draft, to a document of another
 * owner, repointed from one document to another, and cleared while its document still exists.
 *
 * invoice_item_sources (one row per line, `invoice_item_id` unique): the origin of a line (`time`, `fixed_amount`,
 * `manual`), its project and task, the rate it was priced with, the exact seconds, whether the price was typed, and
 * the conversion snapshot. The child guard has no always-mutable column: with the parent issued, every write is
 * refused, `updated_at` included.
 *
 * invoice_payments (ruling R-06-1, D-17, D-19 as amended by the owner on 2026-10-17). A payment is recorded only
 * for an issued invoice or proforma, in the currency of the document (the composite key `(invoice_id, currency)`),
 * and it is never updated by any writer, so the amount, currency and invoice of a payment stay as recorded. It can
 * be deleted so that a mistaken payment is corrected, but only inside the sanctioned path: the DELETE is refused
 * with KP001 unless the transaction-local setting `kokpit.payment_deletion` names exactly this payment. Only
 * `DeletePayment` (plan 10-41) sets it, with `set_config('kokpit.payment_deletion', <payment id>, true)`, for one
 * payment, and clears it before its transaction ends. A proforma payment that is the source of a carried copy
 * (`carried_from_payment_id`, NO ACTION) cannot be deleted while the copy exists (23503), and deleting the copy
 * never touches the source. Nothing on the invoice row is written by a deletion. The limit is stated once: like
 * every guard here, a database owner who sets the flag deliberately bypasses the rule in the same way as one who
 * disables triggers; the architecture test of plan 10-41 keeps the literal out of every other application file.
 *
 * invoice_emails: one row per sent or queued e-mail of a document. No guard: state and attempts move freely while
 * the job runs, a sent row carries its time and only a failed row a failure code.
 *
 * Keys use NO ACTION so every refusal answers 23503. All four tables follow the document, and a draft is deleted by
 * its own action after its children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_admin', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('invoice_id')->unique('invoice_admin_invoice_id_unique')->constrained('invoices');

            $table->text('internal_note')->nullable();
            $table->timestampTz('internal_note_updated_at')->nullable();

            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->integer('exchange_unit_amount')->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->bigInteger('czk_total_minor')->nullable();

            $table->uuid('pdf_document_id')->nullable()->unique('invoice_admin_pdf_document_id_unique');

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_note_check CHECK (internal_note IS NULL OR length(internal_note) <= 2000)');
        DB::statement('ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_exchange_trio_check CHECK ((exchange_rate IS NULL) = (exchange_unit_amount IS NULL) AND (exchange_rate IS NULL) = (exchange_rate_date IS NULL))');
        DB::statement('ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_czk_total_check CHECK ((exchange_rate IS NULL) = (czk_total_minor IS NULL))');
        DB::statement('ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_exchange_positive_check CHECK ((exchange_rate IS NULL OR exchange_rate > 0) AND (exchange_unit_amount IS NULL OR exchange_unit_amount > 0))');
        DB::statement('ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_pdf_document_fk FOREIGN KEY (pdf_document_id) REFERENCES documents (id) ON DELETE SET NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_invoice_pdf_link() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
              owner_kind     text;
              owner          uuid;
            BEGIN
              IF TG_OP = 'UPDATE' AND NEW.pdf_document_id IS NOT DISTINCT FROM OLD.pdf_document_id THEN
                RETURN NEW;
              END IF;

              IF NEW.pdf_document_id IS NOT NULL THEN
                SELECT state INTO document_state FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                IF document_state IS DISTINCT FROM 'issued' THEN
                  RAISE EXCEPTION 'the PDF of a document is linked only when the document is issued' USING ERRCODE = 'KP001';
                END IF;
                SELECT owner_type, owner_id INTO owner_kind, owner FROM documents WHERE id = NEW.pdf_document_id;
                IF owner_kind IS DISTINCT FROM 'invoice' OR owner IS DISTINCT FROM NEW.invoice_id THEN
                  RAISE EXCEPTION 'the PDF link names only a file owned by its own invoice' USING ERRCODE = 'KP001';
                END IF;
              END IF;

              IF TG_OP = 'UPDATE' AND OLD.pdf_document_id IS NOT NULL THEN
                IF NEW.pdf_document_id IS NOT NULL THEN
                  RAISE EXCEPTION 'the PDF link is never repointed from one file to another' USING ERRCODE = 'KP001';
                END IF;
                IF EXISTS (SELECT 1 FROM documents WHERE id = OLD.pdf_document_id) THEN
                  RAISE EXCEPTION 'the PDF link is cleared only when its file is deleted' USING ERRCODE = 'KP001';
                END IF;
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared(Immutability::childGuardTriggerSql('invoice_admin', 'invoice_id', 'invoices', 'state', 'draft', [
            'internal_note',
            'internal_note_updated_at',
            'pdf_document_id',
            'updated_at',
        ]));
        DB::unprepared('CREATE TRIGGER invoice_admin_pdf_link_guard BEFORE INSERT OR UPDATE ON invoice_admin FOR EACH ROW EXECUTE FUNCTION kokpit_guard_invoice_pdf_link()');
        DB::unprepared(Immutability::truncateGuardSql('invoice_admin'));

        Schema::create('invoice_item_sources', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('invoice_item_id')->unique('invoice_item_sources_invoice_item_id_unique');
            $table->foreignUuid('invoice_id')->constrained('invoices');

            $table->string('origin', 16);
            $table->foreignUuid('project_id')->nullable()->constrained('projects');
            // The task is a composite foreign key added below, so it can only be a task of the named project.
            $table->uuid('task_id')->nullable();

            $table->bigInteger('source_rate_minor')->nullable();
            $table->char('source_rate_currency', 3)->nullable();
            $table->bigInteger('source_seconds')->nullable();
            $table->boolean('price_manual')->default(false);
            $table->jsonb('conversion_snapshot')->nullable();

            $table->timestampsTz();

            $table->index('invoice_id', 'invoice_item_sources_invoice_id_index');
            $table->index('project_id', 'invoice_item_sources_project_id_index');
        });

        DB::statement('ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_item_invoice_fk FOREIGN KEY (invoice_item_id, invoice_id) REFERENCES invoice_items (id, invoice_id)');
        DB::statement('ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_task_project_fk FOREIGN KEY (task_id, project_id) REFERENCES tasks (id, project_id)');
        DB::statement('ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_task_needs_project_check CHECK (task_id IS NULL OR project_id IS NOT NULL)');
        DB::statement("ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_origin_check CHECK (origin IN ('time', 'fixed_amount', 'manual'))");
        DB::statement('ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_rate_pair_check CHECK ((source_rate_minor IS NULL) = (source_rate_currency IS NULL) AND (source_rate_minor IS NULL OR source_rate_minor >= 0))');
        DB::statement("ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_rate_currency_check CHECK (source_rate_currency IS NULL OR source_rate_currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_seconds_check CHECK (source_seconds IS NULL OR source_seconds >= 0)');
        DB::statement("ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_time_seconds_check CHECK (origin <> 'time' OR source_seconds IS NOT NULL)");
        DB::statement("ALTER TABLE invoice_item_sources ADD CONSTRAINT invoice_item_sources_conversion_check CHECK (conversion_snapshot IS NULL OR jsonb_typeof(conversion_snapshot) = 'object')");

        DB::unprepared(Immutability::childGuardTriggerSql('invoice_item_sources', 'invoice_id', 'invoices', 'state', 'draft'));
        DB::unprepared(Immutability::truncateGuardSql('invoice_item_sources'));

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('invoice_id');
            $table->char('currency', 3);

            $table->bigInteger('amount_minor');
            $table->date('paid_on');
            $table->string('method', 16);
            $table->string('note', 500)->nullable();

            // The proforma payment this one was carried from; a self-reference, so the key is added below.
            $table->uuid('carried_from_payment_id')->nullable()->unique('invoice_payments_carried_from_unique');
            $table->foreignUuid('created_by')->constrained('users');

            $table->timestampsTz();

            $table->index(['invoice_id', 'paid_on'], 'invoice_payments_invoice_id_paid_on_index');
        });

        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_id_invoice_unique UNIQUE (id, invoice_id)');
        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_invoice_currency_fk FOREIGN KEY (invoice_id, currency) REFERENCES invoices (id, currency)');
        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_carried_from_fk FOREIGN KEY (carried_from_payment_id) REFERENCES invoice_payments (id)');
        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_amount_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_method_check CHECK (method IN ('transfer', 'cash'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_invoice_payment() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
              document_kind  text;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                SELECT state, kind INTO document_state, document_kind FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                IF document_state IS DISTINCT FROM 'issued' OR document_kind = 'credit_note' THEN
                  RAISE EXCEPTION 'a payment is recorded only for an issued invoice or proforma' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION 'a recorded payment is never changed' USING ERRCODE = 'KP001';
              END IF;

              IF current_setting('kokpit.payment_deletion', true) IS DISTINCT FROM OLD.id::text THEN
                RAISE EXCEPTION 'a payment is deleted only by the sanctioned deletion of that payment' USING ERRCODE = 'KP001';
              END IF;
              RETURN OLD;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER invoice_payments_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice_payments FOR EACH ROW EXECUTE FUNCTION kokpit_guard_invoice_payment()');
        DB::unprepared(Immutability::truncateGuardSql('invoice_payments'));

        Schema::create('invoice_emails', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('invoice_id')->constrained('invoices');

            $table->string('recipient', 255);
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('attach_work_report')->default(false);

            $table->string('state', 16)->default('queued');
            $table->integer('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('failure_code', 32)->nullable();

            $table->foreignUuid('created_by')->constrained('users');

            $table->timestampsTz();

            $table->index(['invoice_id', 'created_at'], 'invoice_emails_invoice_id_created_at_index');
        });

        DB::statement("ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_state_check CHECK (state IN ('queued', 'sending', 'sent', 'failed'))");
        DB::statement('ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_attempts_check CHECK (attempts >= 0)');
        DB::statement("ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_sent_at_check CHECK ((state = 'sent') = (sent_at IS NOT NULL))");
        DB::statement("ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_failure_code_check CHECK (failure_code IS NULL OR state = 'failed')");
        DB::statement('ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_body_check CHECK (length(body) <= 5000)');
        DB::statement("ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_recipient_check CHECK (btrim(recipient) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_emails');
        Schema::dropIfExists('invoice_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_invoice_payment()');
        Schema::dropIfExists('invoice_item_sources');
        Schema::dropIfExists('invoice_admin');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_invoice_pdf_link()');
    }
};
