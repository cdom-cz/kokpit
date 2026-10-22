<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The send-driven lock of an issued document (IN-03 amended, IN-11; D-01, D-02, D-08, D-12; phase 10.1). Until now an
 * issued row was frozen by `state = 'issued'`. From here the criterion is the new column `invoices.lock_state`
 * (`open` or `locked`): an issued invoice or proforma stays open, and so correctable, until a successfully sent
 * document e-mail locks it (SendInvoiceEmail, the only lock step). A credit note is locked by its own issue,
 * whoever issues it, and is never opened again, because it copies its invoice and is the storno marker. `state` keeps
 * meaning "numbered or not"; a draft is always open.
 *
 * What is open on an issued row, locked or not, is exactly this list: currency, language, issue date, taxable supply
 * date, due date, the three totals, the printed note, the supplier, customer and bank snapshots, the lock itself and
 * `updated_at`. Everything else is always-on frozen, for every writer and in both lock states, and so is every column
 * a later migration adds until it is listed here on purpose: the number, variable symbol, kind, client, state, VAT
 * mode, appearance snapshot, the links to a proforma and to a credited invoice, the storno marker, the issue time and
 * the creation time. An issued document is never deleted, open or locked, and TRUNCATE stays refused. This is
 * `kokpit_guard_invoice_row()` behind `invoices_row_guard`, which replaces the generic `invoices_frozen_guard`: the
 * generic function would let an open issued row be deleted, because it refuses a DELETE only for a row that differs
 * from the open value.
 *
 * A locked document accepts no write at all, a timestamp-only one included. The only way back to open is the
 * sanctioned unlock: the transaction-local setting `kokpit.invoice_unlock` must name exactly that document, and
 * nothing but `lock_state` and `updated_at` may change with it. Only `UnlockInvoice` (plan 10.1-02) sets the setting,
 * with `set_config('kokpit.invoice_unlock', <invoice id>, true)`, and clears it before its transaction ends; an
 * architecture test keeps the literal out of every other application file, the way `kokpit.payment_deletion` is
 * kept. A credit note is never unlocked, setting or not. Locking is always safe, so an open row moves to locked
 * freely.
 *
 * The children follow the lock, not the state. The items, the item sources and the Admin-only row refuse INSERT,
 * UPDATE and DELETE while their invoice is locked, through the unchanged `kokpit_guard_child_of_frozen_parent()`
 * now keyed on `lock_state` / `open`; the Admin-only row keeps the note, its marker, the PDF link and `updated_at`
 * writable under a lock, as before. A draft is open by the check `invoices_lock_state_issued_check`, so a draft
 * behaves exactly as it did. The Admin-only row of an issued document gets one more rule of its own,
 * `kokpit_guard_invoice_admin_issued()`: its pay token (printed in the PDF and the e-mail, a bearer value), its card
 * switch and its document never change and the row is never deleted, open or locked. The `ON DELETE SET NULL` of the
 * PDF link is untouched. Payments keep their own guard and never depend on the lock.
 *
 * The currency of a credited document (owner ruling of D-06, 2026-10-23): money received is not re-denominated, so
 * the currency of a document that any credit note, draft or issued, names cannot change. Payments pin the currency
 * of their document by the key `invoice_payments_invoice_currency_fk`; a credit note has no such key, because
 * `invoices_credited_client_fk` is on `(credited_invoice_id, client_id)` and the credit note copies the currency of
 * its invoice only in PHP. So the invoice row guard refuses the change with KP001 when `currency` differs and a credit
 * note names the row. The lookup runs only when the currency changes. The function is volatile, so it sees a credit
 * note committed while the update waited for the row: the insert of a credit note takes a key-share lock on the invoice
 * row through that key, and a currency update conflicts with it because `currency` belongs to
 * `invoices_id_currency_unique`. Every other open column of such a document stays writable (D-04).
 *
 * The three checks: `invoices_lock_state_check` (the two values), `invoices_lock_state_issued_check` (a draft is
 * open) and `invoices_credit_note_locked_check` (an issued credit note is locked). A BEFORE trigger runs ahead of the
 * checks, so the guard sets `lock_state := 'locked'` itself on an insert or a flip to issued of a credit note.
 *
 * Existing documents are backfilled as assumption A2 of the phase says: an issued credit note, and an issued invoice
 * or proforma with at least one `invoice_emails` row in state `sent`, become locked; every other issued document and
 * every draft is open. The backfill is one raw UPDATE that does not touch `updated_at`.
 *
 * Order (the old guard refuses the backfill itself): drop `invoices_frozen_guard` and the three child guards, add the
 * column, backfill, add the checks, create the two functions and triggers, re-create the child guards. The TRUNCATE
 * guards and every other guard stay. `down()` restores the frozen-at-issue model.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers, `session_replication_role = replica`
 * skips them, and a database owner who sets the unlock setting deliberately bypasses the rule in the same way, so the
 * production application role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(Immutability::dropGuardTriggerSql('invoices'));
        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_items'));
        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_item_sources'));
        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_admin'));

        DB::statement("ALTER TABLE invoices ADD COLUMN lock_state varchar(8) NOT NULL DEFAULT 'open'");

        DB::statement("UPDATE invoices SET lock_state = 'locked' WHERE state = 'issued' AND (kind = 'credit_note' OR EXISTS (SELECT 1 FROM invoice_emails WHERE invoice_emails.invoice_id = invoices.id AND invoice_emails.state = 'sent'))");

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_lock_state_check CHECK (lock_state IN ('open', 'locked'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_lock_state_issued_check CHECK (state = 'issued' OR lock_state = 'open')");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_credit_note_locked_check CHECK (kind <> 'credit_note' OR state = 'draft' OR lock_state = 'locked')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_invoice_row() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              open_columns text[] := ARRAY[
                'currency', 'language', 'issue_date', 'taxable_supply_date', 'due_date',
                'subtotal_minor', 'vat_minor', 'total_minor', 'note',
                'supplier_snapshot', 'customer_snapshot', 'bank_snapshot', 'lock_state', 'updated_at'
              ];
              unlock_columns text[] := ARRAY['lock_state', 'updated_at'];
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.state = 'issued' AND NEW.kind = 'credit_note' THEN
                  NEW.lock_state := 'locked';
                END IF;
                RETURN NEW;
              END IF;

              IF TG_OP = 'DELETE' THEN
                IF OLD.state <> 'draft' THEN
                  RAISE EXCEPTION 'an issued document is never deleted' USING ERRCODE = 'KP001';
                END IF;
                RETURN OLD;
              END IF;

              IF NEW.currency IS DISTINCT FROM OLD.currency AND EXISTS (SELECT 1 FROM invoices c WHERE c.credited_invoice_id = OLD.id) THEN
                RAISE EXCEPTION 'the currency of a document named by a credit note never changes' USING ERRCODE = 'KP001';
              END IF;

              IF OLD.state = 'draft' THEN
                IF NEW.state = 'issued' AND NEW.kind = 'credit_note' THEN
                  NEW.lock_state := 'locked';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.state IS DISTINCT FROM 'issued' OR (to_jsonb(NEW) - open_columns) IS DISTINCT FROM (to_jsonb(OLD) - open_columns) THEN
                RAISE EXCEPTION 'the number, kind and identity of an issued document never change' USING ERRCODE = 'KP001';
              END IF;

              IF OLD.lock_state = 'locked' THEN
                IF NEW.lock_state = 'locked' THEN
                  IF to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN
                    RAISE EXCEPTION 'a locked document accepts no write' USING ERRCODE = 'KP001';
                  END IF;
                ELSE
                  IF (to_jsonb(NEW) - unlock_columns) IS DISTINCT FROM (to_jsonb(OLD) - unlock_columns) THEN
                    RAISE EXCEPTION 'a locked document accepts no write while it is unlocked' USING ERRCODE = 'KP001';
                  END IF;
                  IF OLD.kind = 'credit_note' OR current_setting('kokpit.invoice_unlock', true) IS DISTINCT FROM OLD.id::text THEN
                    RAISE EXCEPTION 'a document is unlocked only by the sanctioned unlock' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
              END IF;

              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER invoices_row_guard BEFORE INSERT OR UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION kokpit_guard_invoice_row()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_invoice_admin_issued() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
            BEGIN
              SELECT state INTO document_state FROM invoices WHERE id = OLD.invoice_id FOR SHARE;

              IF document_state = 'issued' THEN
                IF TG_OP = 'DELETE' THEN
                  RAISE EXCEPTION 'the Admin-only row of an issued document is never deleted' USING ERRCODE = 'KP001';
                END IF;
                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                  OR NEW.pay_token IS DISTINCT FROM OLD.pay_token
                  OR NEW.card_payment_enabled IS DISTINCT FROM OLD.card_payment_enabled THEN
                  RAISE EXCEPTION 'the pay token, the card switch and the document of an issued document never change' USING ERRCODE = 'KP001';
                END IF;
              END IF;

              IF TG_OP = 'DELETE' THEN
                RETURN OLD;
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER invoice_admin_issued_guard BEFORE UPDATE OR DELETE ON invoice_admin FOR EACH ROW EXECUTE FUNCTION kokpit_guard_invoice_admin_issued()');

        DB::unprepared(Immutability::childGuardTriggerSql('invoice_items', 'invoice_id', 'invoices', 'lock_state', 'open'));
        DB::unprepared(Immutability::childGuardTriggerSql('invoice_item_sources', 'invoice_id', 'invoices', 'lock_state', 'open'));
        DB::unprepared(Immutability::childGuardTriggerSql('invoice_admin', 'invoice_id', 'invoices', 'lock_state', 'open', [
            'internal_note',
            'internal_note_updated_at',
            'pdf_document_id',
            'updated_at',
        ]));
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_admin_issued_guard ON invoice_admin');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_invoice_admin_issued()');
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_row_guard ON invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_invoice_row()');

        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_items'));
        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_item_sources'));
        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_admin'));

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_credit_note_locked_check');
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_lock_state_issued_check');
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_lock_state_check');
        DB::statement('ALTER TABLE invoices DROP COLUMN IF EXISTS lock_state');

        // The frozen-at-issue model of phase 10: every issued row accepts no write, and its children follow the state.
        DB::unprepared(Immutability::guardTriggerSql('invoices', 'state', 'draft', [], withUpdatedAt: false));
        DB::unprepared(Immutability::childGuardTriggerSql('invoice_items', 'invoice_id', 'invoices', 'state', 'draft'));
        DB::unprepared(Immutability::childGuardTriggerSql('invoice_item_sources', 'invoice_id', 'invoices', 'state', 'draft'));
        DB::unprepared(Immutability::childGuardTriggerSql('invoice_admin', 'invoice_id', 'invoices', 'state', 'draft', [
            'internal_note',
            'internal_note_updated_at',
            'pdf_document_id',
            'updated_at',
        ]));
    }
};
