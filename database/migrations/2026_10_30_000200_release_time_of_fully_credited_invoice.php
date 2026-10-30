<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Time billed by an invoice goes back to unbilled once issued credit notes took back every line of it (audit gap G1,
 * IN-10, TI-08; Phase 17.1 D-01 to D-03, D-08). Reversibility is costly: the link guard is the backstop behind every
 * billing writer, and time released under the new rule stays released after a rollback.
 *
 * What changes against `kokpit_guard_time_entry_link()` of `2026_10_22_000200_follow_invoice_lock_in_time_entry_link.php`
 * (the Phase 10.1 body), and why: since Phase 10.1 the guard lets billed time leave an invoice (billed -> unbilled, the
 * link dropped) in two cases only: an issued storno credit note cancels the document, or the document is still open for
 * changes. A sent invoice is locked, so when partial credit notes take back the last creditable quantity of it, the
 * release the Action asks for (`IssueCreditNote::partial()`) would raise KP001. The unbilling branch therefore gains a
 * third reason: the document is credited in full. Every other branch is the Phase 10.1 body verbatim.
 *
 * `kokpit_invoice_fully_credited(document uuid)` is the one place that says it, a mirror in SQL of
 * `CreditableQuantities::for()` for every writer: it reads `kind` and `state` of the document row FOR SHARE; a missing
 * row, a kind other than invoice or a state other than issued answers false. Otherwise it answers true exactly when the
 * document has at least one line with a positive quantity and no such line is left whose quantity plus the sum of the
 * quantities of every credit line pointing at it (through `credited_item_id`, from an issued credit note whose
 * `credited_invoice_id` is the document) is still above zero. Lines with a negative quantity are never creditable and
 * are ignored. A draft credit note counts for nothing, as in `CreditableQuantities`. It is volatile because it reads
 * the document row FOR SHARE, like every guard reading `invoices`.
 *
 * The invoice is not cancelled by this (D-03): `cancels_invoice`, the unique index `invoices_one_storno_per_invoice`
 * and `Invoice::isCancelled()` are untouched, and the function writes nothing. No row is changed or repaired by this
 * migration (D-08): it replaces functions only, no table, column or data. The trigger `time_entries_link_guard` is not
 * re-created, the function is replaced in place.
 *
 * `down()` restores the Phase 10.1 guard body verbatim and drops the helper.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and session_replication_role = replica
 * skips them, so the production database role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_invoice_fully_credited(document uuid) RETURNS boolean LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
              document_kind  text;
            BEGIN
              SELECT state, kind INTO document_state, document_kind FROM invoices WHERE id = document FOR SHARE;

              IF NOT FOUND OR document_kind <> 'invoice' OR document_state <> 'issued' THEN
                RETURN false;
              END IF;

              -- At least one line that could be credited at all ...
              IF NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id = document AND quantity > 0) THEN
                RETURN false;
              END IF;

              -- ... and none of them with something left after the issued credit notes.
              RETURN NOT EXISTS (
                SELECT 1
                FROM invoice_items item
                WHERE item.invoice_id = document
                  AND item.quantity > 0
                  AND item.quantity + COALESCE((
                    SELECT SUM(credit_line.quantity)
                    FROM invoice_items credit_line
                    JOIN invoices credit_note ON credit_note.id = credit_line.invoice_id
                    WHERE credit_line.credited_item_id = item.id
                      AND credit_note.kind = 'credit_note'
                      AND credit_note.state = 'issued'
                      AND credit_note.credited_invoice_id = document
                  ), 0) > 0
              );
            END $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_time_entry_link() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
              old_kind       text;
              proforma_of    uuid;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.invoice_id IS NOT NULL THEN
                  SELECT state INTO document_state FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                  IF NEW.billing_state <> 'unbilled' OR document_state IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'time entry cannot be created linked to a document unless it is unbilled and the document is a draft' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;

              IF OLD.billing_state = 'billed' AND NEW.billing_state = 'billed' THEN
                IF (NEW.billed_rate_minor, NEW.billed_rate_currency, NEW.billed_amount_minor) IS DISTINCT FROM (OLD.billed_rate_minor, OLD.billed_rate_currency, OLD.billed_amount_minor) THEN
                  RAISE EXCEPTION 'the billed snapshot of a time entry is frozen' USING ERRCODE = 'KP001';
                END IF;
                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id THEN
                  IF OLD.invoice_id IS NULL OR NEW.invoice_id IS NULL THEN
                    RAISE EXCEPTION 'a billed time entry cannot gain or lose its link without being unbilled' USING ERRCODE = 'KP001';
                  END IF;
                  SELECT kind INTO old_kind FROM invoices WHERE id = OLD.invoice_id;
                  SELECT state, proforma_id INTO document_state, proforma_of FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                  IF old_kind IS DISTINCT FROM 'proforma' OR document_state IS DISTINCT FROM 'issued' OR proforma_of IS DISTINCT FROM OLD.invoice_id THEN
                    RAISE EXCEPTION 'a billed time entry moves only from a proforma to the issued invoice created from it' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;

              IF OLD.billing_state = 'billed' THEN
                -- Unbilling. The link goes with it; an issued storno credit note, issued credit notes that took back every line, or a document open for changes lets it go.
                IF NEW.invoice_id IS NOT NULL THEN
                  RAISE EXCEPTION 'an unbilled time entry cannot keep the link of the document that billed it' USING ERRCODE = 'KP001';
                END IF;
                IF OLD.invoice_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM invoices WHERE credited_invoice_id = OLD.invoice_id AND cancels_invoice AND state = 'issued')
                   AND NOT kokpit_invoice_fully_credited(OLD.invoice_id)
                   AND NOT kokpit_invoice_accepts_time_changes(OLD.invoice_id) THEN
                  RAISE EXCEPTION 'time billed by a document is released only while the document is open for changes, after an issued storno credit note cancels it, or once issued credit notes took back every line' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.billing_state = 'billed' THEN
                -- Billing. A reserved entry is billed by its own, issued document; a free entry is billed by hand.
                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id THEN
                  RAISE EXCEPTION 'a time entry is billed with the link it was reserved with' USING ERRCODE = 'KP001';
                END IF;
                IF NEW.invoice_id IS NOT NULL THEN
                  SELECT state INTO document_state FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                  IF document_state IS DISTINCT FROM 'issued' THEN
                    RAISE EXCEPTION 'a time entry is billed only when its document is issued' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;

              -- Unbilled before and after: reserve, release, but never overwrite.
              IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id AND NEW.invoice_id IS NOT NULL THEN
                IF OLD.invoice_id IS NOT NULL THEN
                  RAISE EXCEPTION 'a time entry reserved by one document cannot be taken by another' USING ERRCODE = 'KP001';
                END IF;
                IF NOT kokpit_invoice_accepts_time_changes(NEW.invoice_id) THEN
                  RAISE EXCEPTION 'a time entry is reserved only by a draft or by an issued document open for changes' USING ERRCODE = 'KP001';
                END IF;
              END IF;
              RETURN NEW;
            END $$
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_time_entry_link() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
              old_kind       text;
              proforma_of    uuid;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.invoice_id IS NOT NULL THEN
                  SELECT state INTO document_state FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                  IF NEW.billing_state <> 'unbilled' OR document_state IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'time entry cannot be created linked to a document unless it is unbilled and the document is a draft' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;

              IF OLD.billing_state = 'billed' AND NEW.billing_state = 'billed' THEN
                IF (NEW.billed_rate_minor, NEW.billed_rate_currency, NEW.billed_amount_minor) IS DISTINCT FROM (OLD.billed_rate_minor, OLD.billed_rate_currency, OLD.billed_amount_minor) THEN
                  RAISE EXCEPTION 'the billed snapshot of a time entry is frozen' USING ERRCODE = 'KP001';
                END IF;
                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id THEN
                  IF OLD.invoice_id IS NULL OR NEW.invoice_id IS NULL THEN
                    RAISE EXCEPTION 'a billed time entry cannot gain or lose its link without being unbilled' USING ERRCODE = 'KP001';
                  END IF;
                  SELECT kind INTO old_kind FROM invoices WHERE id = OLD.invoice_id;
                  SELECT state, proforma_id INTO document_state, proforma_of FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                  IF old_kind IS DISTINCT FROM 'proforma' OR document_state IS DISTINCT FROM 'issued' OR proforma_of IS DISTINCT FROM OLD.invoice_id THEN
                    RAISE EXCEPTION 'a billed time entry moves only from a proforma to the issued invoice created from it' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;

              IF OLD.billing_state = 'billed' THEN
                -- Unbilling. The link goes with it; an issued storno credit note or a document open for changes lets it go.
                IF NEW.invoice_id IS NOT NULL THEN
                  RAISE EXCEPTION 'an unbilled time entry cannot keep the link of the document that billed it' USING ERRCODE = 'KP001';
                END IF;
                IF OLD.invoice_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM invoices WHERE credited_invoice_id = OLD.invoice_id AND cancels_invoice AND state = 'issued')
                   AND NOT kokpit_invoice_accepts_time_changes(OLD.invoice_id) THEN
                  RAISE EXCEPTION 'time billed by a document is released only while the document is open for changes or after an issued storno credit note cancels it' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.billing_state = 'billed' THEN
                -- Billing. A reserved entry is billed by its own, issued document; a free entry is billed by hand.
                IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id THEN
                  RAISE EXCEPTION 'a time entry is billed with the link it was reserved with' USING ERRCODE = 'KP001';
                END IF;
                IF NEW.invoice_id IS NOT NULL THEN
                  SELECT state INTO document_state FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                  IF document_state IS DISTINCT FROM 'issued' THEN
                    RAISE EXCEPTION 'a time entry is billed only when its document is issued' USING ERRCODE = 'KP001';
                  END IF;
                END IF;
                RETURN NEW;
              END IF;

              -- Unbilled before and after: reserve, release, but never overwrite.
              IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id AND NEW.invoice_id IS NOT NULL THEN
                IF OLD.invoice_id IS NOT NULL THEN
                  RAISE EXCEPTION 'a time entry reserved by one document cannot be taken by another' USING ERRCODE = 'KP001';
                END IF;
                IF NOT kokpit_invoice_accepts_time_changes(NEW.invoice_id) THEN
                  RAISE EXCEPTION 'a time entry is reserved only by a draft or by an issued document open for changes' USING ERRCODE = 'KP001';
                END IF;
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_invoice_fully_credited(uuid)');
    }
};
