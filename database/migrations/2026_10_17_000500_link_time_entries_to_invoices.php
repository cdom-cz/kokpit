<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The link between tracked time and the document that bills it (IN-02, IN-10, TI-08; D-03, D-04, D-05, D-14, D-15).
 *
 * `time_entries.invoice_id` is the whole reservation: one nullable column, so two drafts can never hold the same
 * entry. Reversibility is costly (D-03): the billing lock, the unbilled queries and the guard below depend on this
 * one column. The composite key `(invoice_id, client_id) -> invoices (id, client_id)` keeps an entry with the
 * documents of its own client. The states of an entry:
 *   free            no link, unbilled;
 *   reserved        link, unbilled, held by a draft (reserved time still counts as unbilled everywhere, R-06-3);
 *   billed          link, billed, the document is issued;
 *   billed by hand  no link, billed (Phase 6, TI-05, unchanged).
 *
 * The rules of the link guard `kokpit_guard_time_entry_link()` (BEFORE INSERT OR UPDATE), for every writer:
 * - an entry is reserved only while it is unbilled and only by a draft, and a reservation is never overwritten by
 *   another document (release first, then reserve);
 * - a reserved entry is billed only with its link kept and only when its document is issued; billing and reserving
 *   in one statement, or billing while dropping the link, is refused;
 * - a billed entry keeps its link and its snapshot: the only change of the link is the move from a proforma to the
 *   invoice created from it (the invoice is issued and names the proforma, D-15), and the snapshot never changes;
 * - a billed link is released only together with unbilling (D-14), and only when the invoice is cancelled, that is
 *   when an issued credit note with `cancels_invoice` names it (plan 10-04 R-04-5); a partial credit note does not
 *   count, and nothing is written on the invoice. An unbilled entry cannot keep the link of a document it was
 *   billed by.
 * Billing and unbilling by hand (no link) are not touched.
 *
 * A billed entry snapshots its rate and amount (`billed_rate_minor`, `billed_rate_currency`,
 * `billed_amount_minor`); all three are null while the entry is unbilled and are frozen while it is billed. An
 * entry billed before this phase keeps null snapshots (R-06-2).
 *
 * The generic frozen guard `time_entries_frozen_guard` is re-created through Immutability with the new columns in
 * its mutable list, because they change together with the billing state. `duration_seconds` stays in the list: it
 * is a generated column, PostgreSQL computes it after the BEFORE trigger, so NEW holds NULL there. Listing a column
 * as mutable lets raw SQL change it on a billed row, which is why the link guard above exists. A guard function
 * cannot see a generated column, so it never compares duration_seconds.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and
 * session_replication_role = replica skips them, so the production database role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE time_entries ADD COLUMN invoice_id uuid');
        DB::statement('ALTER TABLE time_entries ADD COLUMN billed_rate_minor bigint');
        DB::statement('ALTER TABLE time_entries ADD COLUMN billed_rate_currency char(3)');
        DB::statement('ALTER TABLE time_entries ADD COLUMN billed_amount_minor bigint');

        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_invoice_client_fk FOREIGN KEY (invoice_id, client_id) REFERENCES invoices (id, client_id)');
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_billed_snapshot_check CHECK (billing_state = 'billed' OR (billed_rate_minor IS NULL AND billed_rate_currency IS NULL AND billed_amount_minor IS NULL))");
        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_billed_snapshot_pair_check CHECK ((billed_rate_minor IS NULL) = (billed_rate_currency IS NULL))');
        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_billed_rate_check CHECK (billed_rate_minor IS NULL OR billed_rate_minor >= 0)');
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_billed_rate_currency_check CHECK (billed_rate_currency IS NULL OR billed_rate_currency ~ '^[A-Z]{3}\$')");

        DB::statement('CREATE INDEX time_entries_invoice_id_index ON time_entries (invoice_id) WHERE invoice_id IS NOT NULL');

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

        DB::unprepared(Immutability::dropGuardTriggerSql('time_entries'));
        DB::unprepared(Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', [
            'billing_state',
            'billed_at',
            'duration_seconds',
            'invoice_id',
            'billed_rate_minor',
            'billed_rate_currency',
            'billed_amount_minor',
        ]));
        DB::unprepared('CREATE TRIGGER time_entries_link_guard BEFORE INSERT OR UPDATE ON time_entries FOR EACH ROW EXECUTE FUNCTION kokpit_guard_time_entry_link()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS time_entries_link_guard ON time_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_time_entry_link()');
        DB::unprepared(Immutability::dropGuardTriggerSql('time_entries'));

        // A reservation or a link cannot outlive the columns; billed state itself stays as it is.
        DB::statement('DROP INDEX IF EXISTS time_entries_invoice_id_index');
        DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_billed_rate_currency_check');
        DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_billed_rate_check');
        DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_billed_snapshot_pair_check');
        DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_billed_snapshot_check');
        DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_invoice_client_fk');
        DB::statement('ALTER TABLE time_entries DROP COLUMN IF EXISTS billed_amount_minor');
        DB::statement('ALTER TABLE time_entries DROP COLUMN IF EXISTS billed_rate_currency');
        DB::statement('ALTER TABLE time_entries DROP COLUMN IF EXISTS billed_rate_minor');
        DB::statement('ALTER TABLE time_entries DROP COLUMN IF EXISTS invoice_id');

        DB::unprepared(Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state', 'billed_at', 'duration_seconds']));
    }
};
