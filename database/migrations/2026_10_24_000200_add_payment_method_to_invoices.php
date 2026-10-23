<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The payment method of a document (Phase 14; D-01, D-02, D-06, D-09; owner amendment OA-7 of 14-CONTEXT.md).
 *
 * `invoices.payment_method` says how one invoice or proforma is paid, exactly one of two: `transfer` (the bank details
 * are printed and the customer pays the account) or `online` (the customer pays by card through the stable pay link,
 * and no bank details are printed). A credit note has no method and keeps NULL. The column lives on the
 * Partner-readable `invoices` row on purpose: the Partner detail shows how the own document is paid, and the value
 * carries no account, price, rate or token. The chosen bank account itself is stored apart, on the Admin-only
 * `invoice_admin` row (next migration).
 *
 * The default is set by the row guard, not by a column default. A column default would also fill a credit note and
 * break the rule "a credit note has no method", and the guard keeps every raw-SQL writer and fixture valid: a document
 * inserted without a method is a bank-transfer document. The INSERT branch of `kokpit_guard_invoice_row()` sets
 * `NEW.payment_method := 'transfer'` for a non-credit-note with no method, the same way it already sets the lock of a
 * credit note; a BEFORE trigger runs ahead of the checks. A later write of NULL to a non-credit-note is refused by
 * `invoices_payment_method_kind_check`.
 *
 * The method is an open column of an issued document (D-09): writable while the document is open, refused with KP001
 * once it is locked, like the currency and the bank snapshot since Phase 10.1. The open list grows by exactly this
 * column and nothing else, on purpose; the architecture and schema tests pin the list. Every other branch and every
 * refusal text of the function stays as in Phase 10.1.
 *
 * Phase 10 D-12 is amended by owner amendment OA-7 (14-CONTEXT.md): `invoices_issued_fields_check` used to require the
 * bank snapshot of every issued invoice and proforma. From here the snapshot is required for `transfer` and forbidden
 * for `online`: `(payment_method = 'online') = (bank_snapshot IS NULL)`. An issued online document therefore carries no
 * bank details, and an issued transfer document must carry them.
 *
 * Existing documents are backfilled as transfer documents. The guard refuses a write to a locked row, so the one
 * UPDATE runs with only `invoices_row_guard` disabled, and only around that statement; `updated_at` is not touched,
 * so the backfill leaves no trace on any document. The order is: add the column, replace the function, disable the
 * trigger, backfill, enable the trigger, add the two checks, replace the issued-fields check.
 *
 * down() refuses to run while an online document exists (the old check cannot hold an issued online row without a
 * snapshot); otherwise it restores the old check and the Phase 10.1 body of the function, then drops the column.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and session_replication_role = replica
 * skips them, so the production application role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices ADD COLUMN payment_method varchar(16) NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_invoice_row() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              open_columns text[] := ARRAY[
                'currency', 'language', 'issue_date', 'taxable_supply_date', 'due_date',
                'subtotal_minor', 'vat_minor', 'total_minor', 'note',
                'supplier_snapshot', 'customer_snapshot', 'bank_snapshot', 'payment_method', 'lock_state', 'updated_at'
              ];
              unlock_columns text[] := ARRAY['lock_state', 'updated_at'];
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.kind <> 'credit_note' AND NEW.payment_method IS NULL THEN
                  NEW.payment_method := 'transfer';
                END IF;
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

        // The guard refuses a write to a locked row, so the backfill runs with only this trigger off, around this one statement.
        DB::statement('ALTER TABLE invoices DISABLE TRIGGER invoices_row_guard');
        DB::statement("UPDATE invoices SET payment_method = 'transfer' WHERE kind <> 'credit_note'");
        DB::statement('ALTER TABLE invoices ENABLE TRIGGER invoices_row_guard');

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('transfer', 'online'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_payment_method_kind_check CHECK ((kind = 'credit_note') = (payment_method IS NULL))");

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_issued_fields_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_issued_fields_check CHECK (state = 'draft' OR (issue_date IS NOT NULL AND supplier_snapshot IS NOT NULL AND customer_snapshot IS NOT NULL AND appearance_snapshot IS NOT NULL AND (kind = 'credit_note' OR (due_date IS NOT NULL AND (payment_method = 'online') = (bank_snapshot IS NULL)))))");
    }

    public function down(): void
    {
        $online = DB::selectOne("SELECT count(*) AS total FROM invoices WHERE payment_method = 'online'");

        if ((int) ($online->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: an online document exists, and the old check cannot hold an issued online document without a bank snapshot.');
        }

        // Verbatim from 2026_10_17_000200_create_invoices_tables.php.
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_issued_fields_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_issued_fields_check CHECK (state = 'draft' OR (issue_date IS NOT NULL AND supplier_snapshot IS NOT NULL AND customer_snapshot IS NOT NULL AND appearance_snapshot IS NOT NULL AND (kind = 'credit_note' OR (due_date IS NOT NULL AND bank_snapshot IS NOT NULL))))");

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_payment_method_kind_check');
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_payment_method_check');

        // The function must not name the column once it is dropped: restore the Phase 10.1 body first (verbatim from
        // 2026_10_22_000100_add_lock_state_to_invoices.php).
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

        DB::statement('ALTER TABLE invoices DROP COLUMN payment_method');
    }
};
