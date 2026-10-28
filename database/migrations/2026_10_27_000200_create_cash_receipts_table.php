<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The cash receipt (PPD, "prijmovy pokladni doklad") of a cash payment (Phase 15; D-06, D-07, D-08, D-09, D-10, D-11,
 * D-13; rulings R-15-02-1 to R-15-02-8 of plan 15-02; owner decision "single-table" of 2026-10-27).
 *
 * A PPD is the receiver's accounting document for cash taken in (Sec. 11 of Act 563/1991 Coll.). Kokpit issues one for
 * every cash payment it records, in its own gap-free series. This table is the whole storage of that document: one
 * Partner-readable row per receipt, holding exactly what the receipt prints, its lock and cancel state and three opaque
 * links (the invoice, the payment, the stored PDF).
 *
 * What the row holds, and what it does not:
 * - No reason, causer or note column. The cancel reason and the unlock reason are free text typed by the Admin; they and
 *   the person who acted live only in the Admin-only activity log written by the Actions (precedent: the invoice unlock
 *   reason of Phase 10.1, owner confirmation AS-16). The column list is pinned as Partner-safe (D-13).
 * - `document_number` is the number of the paid invoice or proforma, copied once at issue. The receipt PDF is generated
 *   from this row only (D-11), so its "paid document" line must not read `invoices`; the number of an issued document
 *   never changes, so the copy cannot go stale. It is frozen with the receipt number.
 *
 * Rules enforced here, for every writer (Actions, console, raw SQL):
 * - One receipt per cash payment (`invoice_payment_id` unique), carrying the payment's amount and currency and the
 *   number of the paid document; the INSERT branch of the row guard checks all of it against `invoice_payments` and
 *   `invoices`. The composite keys tie the receipt to the same invoice and the same client as its payment.
 * - A receipt is created open and not cancelled. The number, paid document number, amount, currency, issue day,
 *   language, appearance snapshot, invoice, client and creation time never change; a receipt is never deleted and the
 *   table is never truncated (D-09: a number is never lost or reused).
 * - Open: the receipt date, the purpose and the supplier and customer snapshots are editable; `open -> locked` is
 *   accepted from any writer (the confirmation send). Locked: no edit, except a missing PDF may be linked again.
 *   `locked -> open` only under the transaction-local setting `kokpit.cash_receipt_unlock` naming the receipt, with
 *   nothing else changing (D-10). Only the `UnlockCashReceipt` Action (plan 15-13) may name that literal; an
 *   architecture test pins it to its files.
 * - Cancel: `cancelled_at` is set once, in either lock state, with nothing else changing but the PDF link and
 *   `updated_at`; it is never cleared and a cancelled receipt accepts no further edit (D-08).
 * - D-08, the payment of a receipt. The foreign key `(invoice_payment_id, invoice_id)` is `ON DELETE SET NULL
 *   (invoice_payment_id)`, so deleting the payment never deletes the receipt (the number stays), and the CHECK
 *   `invoice_payment_id IS NOT NULL OR cancelled_at IS NOT NULL` makes that very set-null fail while the receipt is
 *   not cancelled. A payment with a valid receipt therefore cannot be deleted (23514, nothing changes); after the
 *   cancel it can, and the cancelled receipt keeps its number with an empty payment link. The row guard lets the
 *   set-null UPDATE through whatever the cancel state is (it refuses any other change of the link), so that the CHECK,
 *   not the guard, is the operative defence of this rule.
 *
 * The PDF link follows `kokpit_guard_invoice_pdf_link()` of the invoice tables: a link names only a stored file owned by
 * this very receipt (`owner_type = 'cash_receipt'`), is never repointed from one file to another and is cleared only
 * when its file is gone (the foreign key `ON DELETE SET NULL` does that).
 *
 * No trigger forces a receipt for every cash payment, on purpose (R-15-02-6). No cash payment exists before this phase
 * (AS-2), so D-06 holds without a backfill; it is kept by the one write path `RecordPayment::recordLocked()`, proven by
 * its issue and concurrency tests. A forcing trigger would also break the cash copies that `RecordPayment::carry()`
 * makes during a proforma conversion, which legitimately have no receipt of their own (D-07), and the raw-SQL fixtures
 * of the existing schema tests. The owner accepted on 2026-10-27 that D-06 is enforced in the application only, by
 * `RecordPayment`, with no database constraint trigger; the residual risk that a cash payment written outside
 * `RecordPayment` (raw SQL, console) has no receipt is accepted.
 *
 * down() drops the triggers, the two functions and the table. No data exists before this phase, so nothing is lost by
 * a rollback of the phase itself; once receipts exist, rolling back deletes issued numbers and is a deliberate act.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and set the unlock setting, and
 * session_replication_role = replica skips triggers, so the production application role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            // Both links are composite foreign keys added below, so the client is the client of the invoice and the
            // payment is a payment of that very invoice.
            $table->uuid('invoice_id');
            $table->uuid('client_id');
            $table->uuid('invoice_payment_id')->nullable();

            $table->string('number', 32);
            $table->string('document_number', 32);
            $table->date('issued_on');
            $table->date('received_on');

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('purpose', 500);
            $table->char('language', 2);

            $table->jsonb('supplier_snapshot');
            $table->jsonb('customer_snapshot');
            $table->jsonb('appearance_snapshot');

            $table->string('lock_state', 8)->default('open');
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('pdf_document_id')->nullable();

            $table->timestampsTz();

            $table->unique('number', 'cash_receipts_number_unique');
            $table->unique('invoice_payment_id', 'cash_receipts_invoice_payment_unique');
            $table->index('invoice_id', 'cash_receipts_invoice_id_index');
            $table->index('client_id', 'cash_receipts_client_id_index');
        });

        DB::statement('ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_invoice_client_fk FOREIGN KEY (invoice_id, client_id) REFERENCES invoices (id, client_id)');
        DB::statement('ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_payment_fk FOREIGN KEY (invoice_payment_id, invoice_id) REFERENCES invoice_payments (id, invoice_id) ON DELETE SET NULL (invoice_payment_id)');
        DB::statement('ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_pdf_document_fk FOREIGN KEY (pdf_document_id) REFERENCES documents (id) ON DELETE SET NULL');

        DB::statement('ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_amount_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_language_check CHECK (language IN ('cs', 'en'))");
        DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_lock_state_check CHECK (lock_state IN ('open', 'locked'))");
        DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_purpose_check CHECK (btrim(purpose) <> '')");
        DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_number_check CHECK (btrim(number) <> '')");
        DB::statement("ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_document_number_check CHECK (btrim(document_number) <> '')");
        DB::statement('ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_received_on_check CHECK (received_on <= issued_on)');
        DB::statement('ALTER TABLE cash_receipts ADD CONSTRAINT cash_receipts_payment_link_check CHECK (invoice_payment_id IS NOT NULL OR cancelled_at IS NOT NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_cash_receipt_row() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              -- Every column a receipt may ever change; the rest (id, invoice_id, client_id, number, document_number,
              -- issued_on, amount_minor, currency, language, appearance_snapshot, created_at) is frozen.
              mutable_columns text[] := ARRAY[
                'invoice_payment_id', 'received_on', 'purpose', 'supplier_snapshot', 'customer_snapshot',
                'lock_state', 'cancelled_at', 'pdf_document_id', 'updated_at'
              ];
              link_column     text[] := ARRAY['invoice_payment_id'];
              cancel_columns  text[] := ARRAY['cancelled_at', 'pdf_document_id', 'updated_at'];
              cancelled_open  text[] := ARRAY['pdf_document_id', 'updated_at'];
              locked_columns  text[] := ARRAY['pdf_document_id', 'updated_at'];
              unlock_columns  text[] := ARRAY['lock_state', 'updated_at'];
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.lock_state IS DISTINCT FROM 'open' OR NEW.cancelled_at IS NOT NULL OR NEW.invoice_payment_id IS NULL THEN
                  RAISE EXCEPTION 'a cash receipt is created open, not cancelled and for a payment' USING ERRCODE = 'KP001';
                END IF;
                -- A blank paid-document number is left to cash_receipts_document_number_check.
                IF NOT EXISTS (
                  SELECT 1
                    FROM invoice_payments p
                    JOIN invoices i ON i.id = p.invoice_id
                   WHERE p.id = NEW.invoice_payment_id
                     AND p.invoice_id = NEW.invoice_id
                     AND p.method = 'cash'
                     AND p.amount_minor = NEW.amount_minor
                     AND p.currency = NEW.currency
                     AND (i.number = NEW.document_number OR btrim(NEW.document_number) = '')
                ) THEN
                  RAISE EXCEPTION 'a cash receipt belongs to a cash payment of the paid document, with its amount, currency and document number' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a cash receipt is never deleted' USING ERRCODE = 'KP001';
              END IF;

              IF (to_jsonb(NEW) - mutable_columns) IS DISTINCT FROM (to_jsonb(OLD) - mutable_columns) THEN
                RAISE EXCEPTION 'the number, amount and identity of a cash receipt never change' USING ERRCODE = 'KP001';
              END IF;

              -- The foreign key action after the payment is deleted. The link only drops to null and nothing else
              -- changes; cash_receipts_payment_link_check then refuses it unless the receipt is cancelled.
              IF NEW.invoice_payment_id IS DISTINCT FROM OLD.invoice_payment_id THEN
                IF NEW.invoice_payment_id IS NOT NULL OR (to_jsonb(NEW) - link_column) IS DISTINCT FROM (to_jsonb(OLD) - link_column) THEN
                  RAISE EXCEPTION 'the payment link of a cash receipt only drops to null, when its payment is deleted' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF OLD.cancelled_at IS NOT NULL THEN
                IF NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at THEN
                  RAISE EXCEPTION 'a cancelled cash receipt is never restored' USING ERRCODE = 'KP001';
                END IF;
                IF (to_jsonb(NEW) - cancelled_open) IS DISTINCT FROM (to_jsonb(OLD) - cancelled_open) THEN
                  RAISE EXCEPTION 'a cancelled cash receipt accepts no edit' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.cancelled_at IS NOT NULL THEN
                IF (to_jsonb(NEW) - cancel_columns) IS DISTINCT FROM (to_jsonb(OLD) - cancel_columns) THEN
                  RAISE EXCEPTION 'a cash receipt is cancelled with nothing else changed' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF OLD.lock_state = 'locked' THEN
                IF NEW.lock_state = 'locked' THEN
                  IF (to_jsonb(NEW) - locked_columns) IS DISTINCT FROM (to_jsonb(OLD) - locked_columns) THEN
                    RAISE EXCEPTION 'a locked cash receipt accepts no edit' USING ERRCODE = 'KP001';
                  END IF;
                ELSE
                  IF (to_jsonb(NEW) - unlock_columns) IS DISTINCT FROM (to_jsonb(OLD) - unlock_columns) THEN
                    RAISE EXCEPTION 'a locked cash receipt accepts no edit while it is unlocked' USING ERRCODE = 'KP001';
                  END IF;
                  IF current_setting('kokpit.cash_receipt_unlock', true) IS DISTINCT FROM OLD.id::text THEN
                    RAISE EXCEPTION 'a cash receipt is unlocked only by the sanctioned unlock' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
              END IF;

              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER cash_receipts_row_guard BEFORE INSERT OR UPDATE OR DELETE ON cash_receipts FOR EACH ROW EXECUTE FUNCTION kokpit_guard_cash_receipt_row()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_cash_receipt_pdf_link() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              owner_kind text;
              owner      uuid;
            BEGIN
              IF TG_OP = 'UPDATE' AND NEW.pdf_document_id IS NOT DISTINCT FROM OLD.pdf_document_id THEN
                RETURN NEW;
              END IF;

              IF NEW.pdf_document_id IS NOT NULL THEN
                SELECT owner_type, owner_id INTO owner_kind, owner FROM documents WHERE id = NEW.pdf_document_id;
                IF owner_kind IS DISTINCT FROM 'cash_receipt' OR owner IS DISTINCT FROM NEW.id THEN
                  RAISE EXCEPTION 'the PDF link names only a file owned by its own cash receipt' USING ERRCODE = 'KP001';
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

        DB::unprepared('CREATE TRIGGER cash_receipts_pdf_link_guard BEFORE INSERT OR UPDATE ON cash_receipts FOR EACH ROW EXECUTE FUNCTION kokpit_guard_cash_receipt_pdf_link()');
        DB::unprepared(Immutability::truncateGuardSql('cash_receipts'));
    }

    public function down(): void
    {
        DB::unprepared(Immutability::dropTruncateGuardSql('cash_receipts'));
        DB::unprepared('DROP TRIGGER IF EXISTS cash_receipts_pdf_link_guard ON cash_receipts');
        DB::unprepared('DROP TRIGGER IF EXISTS cash_receipts_row_guard ON cash_receipts');

        Schema::dropIfExists('cash_receipts');

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_cash_receipt_pdf_link()');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_cash_receipt_row()');
    }
};
