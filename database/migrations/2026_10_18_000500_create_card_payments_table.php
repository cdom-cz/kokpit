<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The record of every payment or refund event that Stripe reports (PA-06, PA-07, PA-08; D-11 to D-14, D-17, research
 * "Schema" item 3 and Pitfall 12). One Admin-only row per event: a payment matched to its document, a duplicate of an
 * already paid document, an unmatched payment (with the reason) or a refund. The row does not depend on the webhook
 * row, so pruning old events never removes it (D-17).
 *
 * Idempotence is enforced here, not in the application (PA-07, D-12): the Stripe event id is unique, the checkout
 * session id is unique, the payment intent is unique among payments (a refund legitimately shares the intent of the
 * payment it returns) and the refund id is unique. `CardPayment::recordOnce()` inserts with ON CONFLICT DO NOTHING
 * against these indexes and reports a repeat without an exception.
 *
 * The references outlive a deleted payment (D-14, costly): `invoice_payment_id` is `ON DELETE SET NULL`, so the
 * sanctioned `DeletePayment` keeps working while the record keeps every Stripe reference and a redelivery or a replay
 * of the same event, session or payment intent finds the reserved row and books nothing again. The same holds for
 * `return_transaction_id`: an expense entered by mistake stays deletable in Finance, the record only loses the link.
 *
 * `kokpit_guard_card_payment()` (SQLSTATE KP001) refuses every delete, and an update of anything but the resolution
 * of the record and the two links that may be set to null or filled once; the resolution moves once from `none` and is
 * final. `card_payments_truncate_guard` refuses TRUNCATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->string('type', 16);
            $table->string('reason', 16)->nullable();

            $table->string('resolution', 16)->default('none');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->string('resolution_note', 500)->nullable();
            $table->foreignUuid('assigned_invoice_id')->nullable()->constrained('invoices');

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('offered_amount_minor')->nullable();
            $table->char('offered_currency', 3)->nullable();

            $table->string('stripe_event_id', 255);
            $table->string('stripe_session_id', 255)->nullable();
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->string('stripe_refund_id', 255)->nullable();
            $table->string('stripe_link_id', 255)->nullable();

            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices');
            $table->foreignUuid('payment_link_id')->nullable()->constrained('payment_links');
            $table->uuid('invoice_payment_id')->nullable();
            $table->uuid('return_transaction_id')->nullable();

            $table->timestampTz('received_at');
            $table->timestampsTz();

            $table->unique('stripe_event_id', 'card_payments_event_unique');
            $table->unique('stripe_refund_id', 'card_payments_refund_unique');
            $table->unique('invoice_payment_id', 'card_payments_invoice_payment_unique');
            $table->unique('return_transaction_id', 'card_payments_return_transaction_unique');
            $table->index('invoice_id', 'card_payments_invoice_id_index');
            $table->index('received_at', 'card_payments_received_at_index');
        });

        DB::statement('ALTER TABLE card_payments ADD CONSTRAINT card_payments_invoice_payment_fk FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments (id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE card_payments ADD CONSTRAINT card_payments_return_transaction_fk FOREIGN KEY (return_transaction_id) REFERENCES transactions (id) ON DELETE SET NULL');

        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_type_check CHECK (type IN ('matched', 'duplicate', 'unmatched', 'refund'))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_reason_check CHECK (COALESCE((type IN ('matched', 'refund') AND reason IS NULL) OR (type = 'duplicate' AND reason = 'already_paid') OR (type = 'unmatched' AND reason IN ('amount', 'currency', 'cancelled', 'undetermined')), false))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_resolution_check CHECK (resolution IN ('none', 'assigned', 'noted', 'returned'))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_resolution_type_check CHECK ((type = 'matched' AND resolution = 'none') OR (type = 'refund' AND resolution IN ('none', 'noted')) OR (type IN ('duplicate', 'unmatched')))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_resolved_check CHECK ((resolution = 'none' AND resolved_at IS NULL AND resolved_by IS NULL) OR (resolution <> 'none' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_note_check CHECK (resolution <> 'noted' OR (resolution_note IS NOT NULL AND btrim(resolution_note) <> ''))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_assigned_check CHECK ((resolution = 'assigned') = (assigned_invoice_id IS NOT NULL))");
        DB::statement('ALTER TABLE card_payments ADD CONSTRAINT card_payments_amount_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_offered_check CHECK ((offered_amount_minor IS NULL) = (offered_currency IS NULL) AND (offered_amount_minor IS NULL OR (offered_amount_minor > 0 AND offered_currency ~ '^[A-Z]{3}\$')))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_refund_id_check CHECK ((type = 'refund') = (stripe_refund_id IS NOT NULL))");
        DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_matched_invoice_check CHECK (type <> 'matched' OR invoice_id IS NOT NULL)");

        DB::statement('CREATE UNIQUE INDEX card_payments_session_unique ON card_payments (stripe_session_id) WHERE stripe_session_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX card_payments_payment_intent_unique ON card_payments (stripe_payment_intent_id) WHERE type <> 'refund' AND stripe_payment_intent_id IS NOT NULL");
        DB::statement("CREATE INDEX card_payments_unresolved_index ON card_payments (received_at) WHERE resolution = 'none' AND type <> 'matched'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_card_payment() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a card payment record is never deleted' USING ERRCODE = 'KP001';
              END IF;

              IF NEW.id IS DISTINCT FROM OLD.id
                 OR NEW.type IS DISTINCT FROM OLD.type
                 OR NEW.reason IS DISTINCT FROM OLD.reason
                 OR NEW.amount_minor IS DISTINCT FROM OLD.amount_minor
                 OR NEW.currency IS DISTINCT FROM OLD.currency
                 OR NEW.offered_amount_minor IS DISTINCT FROM OLD.offered_amount_minor
                 OR NEW.offered_currency IS DISTINCT FROM OLD.offered_currency
                 OR NEW.stripe_event_id IS DISTINCT FROM OLD.stripe_event_id
                 OR NEW.stripe_session_id IS DISTINCT FROM OLD.stripe_session_id
                 OR NEW.stripe_payment_intent_id IS DISTINCT FROM OLD.stripe_payment_intent_id
                 OR NEW.stripe_refund_id IS DISTINCT FROM OLD.stripe_refund_id
                 OR NEW.stripe_link_id IS DISTINCT FROM OLD.stripe_link_id
                 OR NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                 OR NEW.payment_link_id IS DISTINCT FROM OLD.payment_link_id
                 OR NEW.received_at IS DISTINCT FROM OLD.received_at
                 OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'a card payment record keeps what Stripe reported and what it points at' USING ERRCODE = 'KP001';
              END IF;

              -- The resolution moves once from none and is final, with everything that belongs to it, the assigned
              -- document included (it can only be set while the record is resolved, so only from null).
              IF OLD.resolution <> 'none'
                 AND (NEW.resolution IS DISTINCT FROM OLD.resolution
                      OR NEW.resolved_at IS DISTINCT FROM OLD.resolved_at
                      OR NEW.resolved_by IS DISTINCT FROM OLD.resolved_by
                      OR NEW.resolution_note IS DISTINCT FROM OLD.resolution_note
                      OR NEW.assigned_invoice_id IS DISTINCT FROM OLD.assigned_invoice_id) THEN
                RAISE EXCEPTION 'the resolution of a card payment record is final' USING ERRCODE = 'KP001';
              END IF;

              -- The two links to the money may be cleared (the payment or the expense is deleted, the database sets
              -- null) or filled once; a link never moves from one row to another.
              IF (OLD.invoice_payment_id IS NOT NULL AND NEW.invoice_payment_id IS NOT NULL AND NEW.invoice_payment_id <> OLD.invoice_payment_id)
                 OR (OLD.return_transaction_id IS NOT NULL AND NEW.return_transaction_id IS NOT NULL AND NEW.return_transaction_id <> OLD.return_transaction_id) THEN
                RAISE EXCEPTION 'the payment and the return expense of a card payment record never move to another row' USING ERRCODE = 'KP001';
              END IF;

              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER card_payments_guard BEFORE UPDATE OR DELETE ON card_payments FOR EACH ROW EXECUTE FUNCTION kokpit_guard_card_payment()');
        DB::unprepared(Immutability::truncateGuardSql('card_payments'));
    }

    public function down(): void
    {
        Schema::dropIfExists('card_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_card_payment()');
    }
};
