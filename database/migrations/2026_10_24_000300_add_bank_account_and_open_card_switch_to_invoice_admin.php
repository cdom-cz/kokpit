<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The chosen bank account and the open card switch of an issued document (Phase 14; D-03, D-05, D-09; owner amendment
 * OA-1 of 14-CONTEXT.md, which reverses the frozen-at-issue rule of Phase 11 D-06 and T-10.1-24).
 *
 * `invoice_admin.bank_account_id` stores which supplier bank account the document names, by the stable id of the entry
 * in the `bank.accounts` setting (plan 14-01). It is separate from the currency (D-05): the account of a document may
 * be in another currency than the document (D-06). It has no foreign key, because the target is an entry of a settings
 * payload and not a row, and it lives on the Admin-only row on purpose: it names a settings entry and has no Partner
 * use. The Partner reads the bank snapshot printed on the document, never this id. The account of an issued document
 * is audited by its bank fingerprint, so the id is not on the history allowlist.
 *
 * The card switch `card_payment_enabled` is the single "is card payment offered" predicate of a document. Until now an
 * issued document froze it together with the pay token. From here both are writable on an open issued document, and
 * the lock freezes them again:
 *
 * - the switch may change in both directions while the document is open;
 * - the pay token is set once. A first token (NULL -> value) is allowed on an open issued document, a set token never
 *   changes and never disappears, open or locked (`kokpit_guard_invoice_admin_issued()`, a bearer value that is
 *   already printed in a PDF or an e-mail must keep answering);
 * - the token outlives a switch-off, so a printed pay URL keeps answering and the pay page shows its existing
 *   information state. `invoice_admin_pay_token_check` therefore checks the format only and no longer ties the token
 *   to the switch (the name is kept; a test drops it by name).
 *
 * The lock still freezes all three through the unchanged child guard `invoice_admin_parent_guard`: under a locked
 * parent only `internal_note`, `internal_note_updated_at`, `pdf_document_id` and `updated_at` may change, so the
 * switch, the token and the account are frozen once the document is sent. On an issued parent the row is still never
 * deleted and never moves to another document.
 *
 * down() refuses to run while any row carries a token with the switch off, because the old check cannot hold it;
 * otherwise it restores the Phase 10.1 guard and the old check, then drops the column.
 *
 * The tie between the online method and the card switch is application-level only: no CHECK or trigger couples
 * `invoices.payment_method = 'online'` with `invoice_admin.card_payment_enabled`, because a cross-table rule needs a
 * trigger and an online invoice converted from a paid proforma while card payment was unavailable rightly keeps the
 * switch off. `DocumentPayment` owns the rule (14-REVIEW.md IN-01).
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and session_replication_role = replica
 * skips them, so the production application role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_admin ADD COLUMN bank_account_id uuid NULL');
        DB::statement("COMMENT ON COLUMN invoice_admin.bank_account_id IS 'Id of an entry of the bank.accounts setting; no foreign key because the target is a settings entry, not a row (Phase 14, D-05).'");

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
                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id THEN
                  RAISE EXCEPTION 'the document of an issued Admin-only row never changes' USING ERRCODE = 'KP001';
                END IF;
                IF OLD.pay_token IS NOT NULL AND NEW.pay_token IS DISTINCT FROM OLD.pay_token THEN
                  RAISE EXCEPTION 'an issued pay token never changes' USING ERRCODE = 'KP001';
                END IF;
              END IF;

              IF TG_OP = 'DELETE' THEN
                RETURN OLD;
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::statement('ALTER TABLE invoice_admin DROP CONSTRAINT invoice_admin_pay_token_check');
        DB::statement("ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_pay_token_check CHECK (pay_token IS NULL OR pay_token ~ '^[A-Za-z0-9]{32}\$')");
    }

    public function down(): void
    {
        $orphaned = DB::selectOne('SELECT count(*) AS total FROM invoice_admin WHERE pay_token IS NOT NULL AND NOT card_payment_enabled');

        if ((int) ($orphaned->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: a document carries a pay token with its card switch off, which the old check cannot hold.');
        }

        DB::statement('ALTER TABLE invoice_admin DROP CONSTRAINT invoice_admin_pay_token_check');
        DB::statement("ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_pay_token_check CHECK (pay_token IS NULL OR (card_payment_enabled AND pay_token ~ '^[A-Za-z0-9]{32}\$'))");

        // The Phase 10.1 body, verbatim from 2026_10_22_000100_add_lock_state_to_invoices.php.
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

        DB::statement('ALTER TABLE invoice_admin DROP COLUMN bank_account_id');
    }
};
