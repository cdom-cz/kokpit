<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A card payment record points only at a payment in the currency it was paid in (IN-13, IN-14; D-06 with its owner
 * ruling of 2026-10-23, D-11; review CR-01). Reversible: `down()` drops the trigger and the function, no stored row is
 * changed.
 *
 * Why: since `2026_10_22_000300_relax_payment_link_currency_key.php` a payment link no longer pins the currency of its
 * document, so an open document can change currency while an old Stripe Checkout session of the former currency is
 * still payable. The webhook compares the paid currency with the link and the link with the document
 * (`RecordCardPayment`), and this trigger is the database backstop at the point where money is booked: a card payment
 * record may point at an `invoice_payments` row only when both carry the same currency.
 *
 * The chain it completes: `invoice_payments` keeps its composite key to `invoices (id, currency)` and a document that
 * has a payment cannot change its currency, so record currency = payment currency = document currency for every
 * writer, the webhook, `AssignCardPayment`, and any future code or raw SQL.
 *
 * `kokpit_guard_card_payment_currency()` runs BEFORE INSERT and BEFORE UPDATE OF `invoice_payment_id`, so it fires when
 * the link is set or filled and not when the database clears it (`ON DELETE SET NULL`) or when anything else on the
 * record changes. Stored rows are not rechecked by the trigger. SQLSTATE KP001, like every guard here.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and session_replication_role = replica
 * skips them, so the production database role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_card_payment_currency() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              payment_currency text;
            BEGIN
              IF NEW.invoice_payment_id IS NULL THEN
                RETURN NEW;
              END IF;

              SELECT currency INTO payment_currency FROM invoice_payments WHERE id = NEW.invoice_payment_id;

              -- No such payment: the foreign key refuses the row.
              IF FOUND AND payment_currency IS DISTINCT FROM NEW.currency THEN
                RAISE EXCEPTION 'a card payment record points only at a payment in the currency it was paid in' USING ERRCODE = 'KP001';
              END IF;

              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER card_payments_currency_guard BEFORE INSERT OR UPDATE OF invoice_payment_id ON card_payments FOR EACH ROW EXECUTE FUNCTION kokpit_guard_card_payment_currency()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS card_payments_currency_guard ON card_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_card_payment_currency()');
    }
};
