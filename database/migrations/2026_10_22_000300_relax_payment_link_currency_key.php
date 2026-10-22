<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A payment link no longer pins the currency of its document (IN-13, IN-03; D-06 with its owner ruling of 2026-10-23,
 * D-11, D-12; research G10, G11, Pitfall 2). Reversibility is costly: once the currency of a document with old link
 * rows has changed, `down()` refuses, because the composite key could not be restored over those rows.
 *
 * Phase 10.1 lets an issued document stay open until it is sent, and the currency of an open document may change
 * (the bank follows it). The composite key `payment_links_invoice_currency_fk (invoice_id, currency) -> invoices
 * (id, currency)` of `2026_10_18_000400_create_payment_links_table.php` made the database refuse that change as soon
 * as one link row existed. It becomes a plain key `payment_links_invoice_fk (invoice_id) -> invoices (id)`, and the
 * rule "a link is in the currency of its document" moves into the insert branch of `kokpit_guard_payment_link()`:
 * every NEW link is in the current currency of the document, every OLD row keeps the currency it offered (its
 * currency never changes, the update branch is unchanged). A document that does not exist is left to the plain key,
 * which refuses it with 23503.
 *
 * What stays: `invoice_payments_invoice_currency_fk` is not touched, so a document with a recorded payment can never
 * change its currency (owner ruling of D-06); the half of the ruling for a credit note is the KP001 rule of
 * `kokpit_guard_invoice_row()` from plan 10.1-01. Only the insert branch of the guard gains the currency check, every
 * other branch is the Phase 11 body.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and
 * session_replication_role = replica skips them, so the production database role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_invoice_currency_fk');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices (id)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_payment_link() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              document_state    text;
              document_kind     text;
              document_currency text;
              card_enabled      boolean;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                SELECT state, kind, currency INTO document_state, document_kind, document_currency FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                IF NOT FOUND THEN
                  -- No such document: the foreign key refuses the row.
                  RETURN NEW;
                END IF;
                IF document_state IS DISTINCT FROM 'issued' OR document_kind IS NOT DISTINCT FROM 'credit_note' THEN
                  RAISE EXCEPTION 'a payment link is created only for an issued invoice or proforma' USING ERRCODE = 'KP001';
                END IF;
                IF NEW.currency IS DISTINCT FROM document_currency THEN
                  RAISE EXCEPTION 'a payment link is created in the currency of its document' USING ERRCODE = 'KP001';
                END IF;
                SELECT card_payment_enabled INTO card_enabled FROM invoice_admin WHERE invoice_id = NEW.invoice_id;
                IF card_enabled IS DISTINCT FROM true THEN
                  RAISE EXCEPTION 'a payment link is created only for a document whose card payment was switched on at issue' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a payment link record is never deleted' USING ERRCODE = 'KP001';
              END IF;

              IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                 OR NEW.currency IS DISTINCT FROM OLD.currency
                 OR NEW.amount_minor IS DISTINCT FROM OLD.amount_minor
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by
                 OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'the document, currency, amount and creator of a payment link never change' USING ERRCODE = 'KP001';
              END IF;

              IF (OLD.stripe_link_id IS NOT NULL AND NEW.stripe_link_id IS DISTINCT FROM OLD.stripe_link_id)
                 OR (OLD.stripe_url IS NOT NULL AND NEW.stripe_url IS DISTINCT FROM OLD.stripe_url)
                 OR (OLD.remote_deactivated_at IS NOT NULL AND NEW.remote_deactivated_at IS DISTINCT FROM OLD.remote_deactivated_at) THEN
                RAISE EXCEPTION 'the Stripe references and the remote deactivation of a payment link are set once' USING ERRCODE = 'KP001';
              END IF;

              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT ((OLD.state = 'pending' AND NEW.state IN ('active', 'failed', 'deactivated'))
                        OR (OLD.state = 'active' AND NEW.state = 'deactivated')) THEN
                  RAISE EXCEPTION 'a payment link cannot move from % to %', OLD.state, NEW.state USING ERRCODE = 'KP001';
                END IF;
              ELSIF OLD.state IN ('failed', 'deactivated') THEN
                IF NEW.deactivation_reason IS DISTINCT FROM OLD.deactivation_reason
                   OR NEW.failure_class IS DISTINCT FROM OLD.failure_class
                   OR NEW.activated_at IS DISTINCT FROM OLD.activated_at
                   OR NEW.deactivated_at IS DISTINCT FROM OLD.deactivated_at THEN
                  RAISE EXCEPTION 'a failed or deactivated payment link is final' USING ERRCODE = 'KP001';
                END IF;
              END IF;

              RETURN NEW;
            END $$
            SQL);
    }

    public function down(): void
    {
        $stale = DB::selectOne('SELECT count(*) AS total FROM payment_links l JOIN invoices i ON i.id = l.invoice_id WHERE l.currency <> i.currency');

        if ((int) ($stale->total ?? 0) > 0) {
            throw new RuntimeException('Cannot restore the composite key payment_links_invoice_currency_fk: '.$stale->total.' payment link(s) name a currency their document no longer has.');
        }

        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_invoice_fk');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_invoice_currency_fk FOREIGN KEY (invoice_id, currency) REFERENCES invoices (id, currency)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_payment_link() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              document_state text;
              document_kind  text;
              card_enabled   boolean;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                SELECT state, kind INTO document_state, document_kind FROM invoices WHERE id = NEW.invoice_id FOR SHARE;
                IF document_state IS DISTINCT FROM 'issued' OR document_kind IS NOT DISTINCT FROM 'credit_note' THEN
                  RAISE EXCEPTION 'a payment link is created only for an issued invoice or proforma' USING ERRCODE = 'KP001';
                END IF;
                SELECT card_payment_enabled INTO card_enabled FROM invoice_admin WHERE invoice_id = NEW.invoice_id;
                IF card_enabled IS DISTINCT FROM true THEN
                  RAISE EXCEPTION 'a payment link is created only for a document whose card payment was switched on at issue' USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a payment link record is never deleted' USING ERRCODE = 'KP001';
              END IF;

              IF NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                 OR NEW.currency IS DISTINCT FROM OLD.currency
                 OR NEW.amount_minor IS DISTINCT FROM OLD.amount_minor
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by
                 OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'the document, currency, amount and creator of a payment link never change' USING ERRCODE = 'KP001';
              END IF;

              IF (OLD.stripe_link_id IS NOT NULL AND NEW.stripe_link_id IS DISTINCT FROM OLD.stripe_link_id)
                 OR (OLD.stripe_url IS NOT NULL AND NEW.stripe_url IS DISTINCT FROM OLD.stripe_url)
                 OR (OLD.remote_deactivated_at IS NOT NULL AND NEW.remote_deactivated_at IS DISTINCT FROM OLD.remote_deactivated_at) THEN
                RAISE EXCEPTION 'the Stripe references and the remote deactivation of a payment link are set once' USING ERRCODE = 'KP001';
              END IF;

              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT ((OLD.state = 'pending' AND NEW.state IN ('active', 'failed', 'deactivated'))
                        OR (OLD.state = 'active' AND NEW.state = 'deactivated')) THEN
                  RAISE EXCEPTION 'a payment link cannot move from % to %', OLD.state, NEW.state USING ERRCODE = 'KP001';
                END IF;
              ELSIF OLD.state IN ('failed', 'deactivated') THEN
                IF NEW.deactivation_reason IS DISTINCT FROM OLD.deactivation_reason
                   OR NEW.failure_class IS DISTINCT FROM OLD.failure_class
                   OR NEW.activated_at IS DISTINCT FROM OLD.activated_at
                   OR NEW.deactivated_at IS DISTINCT FROM OLD.deactivated_at THEN
                  RAISE EXCEPTION 'a failed or deactivated payment link is final' USING ERRCODE = 'KP001';
                END IF;
              END IF;

              RETURN NEW;
            END $$
            SQL);
    }
};
