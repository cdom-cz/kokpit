<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The time link follows the lock of the document, not its issued state (IN-13, IN-03; D-07, D-12, A3; research G8,
 * G9, G12). Reversibility is costly: the link guard is the backstop behind every billing writer, and the Actions that
 * add and release time on an open document rely on it.
 *
 * What changes against `kokpit_guard_time_entry_link()` of `2026_10_17_000500_link_time_entries_to_invoices.php`,
 * and why: Phase 10 treated "issued" as "frozen", so only a draft could reserve time and only an issued storno credit
 * note could release it. Since Phase 10.1 an issued invoice or proforma stays open until it is sent, so what decides
 * is the lock. Two branches change, every other branch is the Phase 10 body:
 * - a reservation (unbilled before and after, a new link) needs a document that accepts time changes: a draft, or an
 *   issued invoice or proforma that is open (the old rule named drafts only);
 * - an unbilling (billed -> unbilled) that drops the link of a document is accepted after an issued storno credit
 *   note cancels the document (unchanged), or while the document accepts time changes (new).
 * The INSERT branch stays draft only, billing still needs the document issued, the move of a billed entry from a
 * proforma to the invoice created from it and the frozen snapshot are unchanged. The trigger
 * `time_entries_link_guard` is not re-created, the function is replaced in place.
 *
 * `kokpit_invoice_accepts_time_changes(document uuid)` is the one place that says it: true for a draft, and for an
 * issued, open invoice or proforma that no issued storno credit note cancels and that no invoice names as its
 * proforma (A3: a cancelled invoice and a converted proforma take no time changes even when open); false for a
 * locked document, a credit note and a document that does not exist. It is volatile because it reads the document
 * row FOR SHARE, like every guard reading `invoices`.
 *
 * `down()` restores the Phase 10 body verbatim and drops the helper.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and
 * session_replication_role = replica skips them, so the production database role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_invoice_accepts_time_changes(document uuid) RETURNS boolean LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
              document_kind  text;
              document_lock  text;
            BEGIN
              SELECT state, kind, lock_state INTO document_state, document_kind, document_lock FROM invoices WHERE id = document FOR SHARE;

              IF NOT FOUND THEN
                RETURN false;
              END IF;

              IF document_state = 'draft' THEN
                RETURN true;
              END IF;

              IF document_state <> 'issued' OR document_lock <> 'open' OR document_kind NOT IN ('invoice', 'proforma') THEN
                RETURN false;
              END IF;

              RETURN NOT EXISTS (SELECT 1 FROM invoices WHERE credited_invoice_id = document AND cancels_invoice AND state = 'issued')
                AND NOT EXISTS (SELECT 1 FROM invoices WHERE proforma_id = document);
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
                -- Unbilling. The link goes with it, and only a cancelled invoice lets it go.
                IF NEW.invoice_id IS NOT NULL THEN
                  RAISE EXCEPTION 'an unbilled time entry cannot keep the link of the document that billed it' USING ERRCODE = 'KP001';
                END IF;
                IF OLD.invoice_id IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM invoices WHERE credited_invoice_id = OLD.invoice_id AND cancels_invoice AND state = 'issued'
                ) THEN
                  RAISE EXCEPTION 'time billed by an invoice is released only when the invoice is cancelled by an issued storno credit note' USING ERRCODE = 'KP001';
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
                SELECT state INTO document_state FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                IF document_state IS DISTINCT FROM 'draft' THEN
                  RAISE EXCEPTION 'a time entry is reserved only by a draft' USING ERRCODE = 'KP001';
                END IF;
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_invoice_accepts_time_changes(uuid)');
    }
};
