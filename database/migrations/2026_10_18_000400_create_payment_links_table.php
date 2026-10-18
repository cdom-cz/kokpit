<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The record of the Stripe payment link of a document (PA-01, PA-04; D-05 to D-08, Phase 10 D-13, research
 * Pitfall 13). The lifecycle of a link lives in its own Admin-only table, never on the frozen `invoices` row: a
 * document may get a new link after a partial payment, and an issued `invoices` row accepts no write.
 *
 * A row exists only for an issued invoice or proforma whose card switch was frozen on (`invoice_admin.
 * card_payment_enabled`), in the currency of the document (the composite key `(invoice_id, currency)`, as on
 * `invoice_payments`), for a positive amount. The amount is what the link offers, which is the remaining amount at
 * the time it was created (D-07); matching a payment compares with it.
 *
 * States: pending (the row exists, the Stripe link does not yet), active (it carries the Stripe id and an https URL
 * on a stripe.com host), failed (with a failure class) and deactivated (with a reason: paid, cancelled,
 * amount_changed, manual, replaced). Failed and deactivated are final. At most one live link (pending or active)
 * exists per document: the partial unique index `payment_links_one_live_per_invoice` (D-08). A regeneration
 * deactivates the old link first.
 *
 * `kokpit_guard_payment_link()` is the defence in depth behind the application (SQLSTATE KP001): an insert needs the
 * document issued, not a credit note, and its card switch on. The record is never deleted and its document,
 * currency, offered amount and creator never change. Only the allowed state moves are accepted, the Stripe
 * references and the remote-deactivation stamp may only go from null to a value, and a Stripe id once set never
 * changes (the references stay for the audit trail).
 *
 * `remote_deactivated_at` is the time the link was also switched off at Stripe; the sweep that does it finds the
 * rows by `payment_links_remote_pending_index`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('invoice_id');
            $table->char('currency', 3);

            $table->bigInteger('amount_minor');
            $table->string('state', 16)->default('pending');
            $table->string('deactivation_reason', 16)->nullable();
            $table->string('failure_class', 32)->nullable();

            $table->string('stripe_link_id', 255)->nullable()->unique('payment_links_stripe_link_id_unique');
            $table->string('stripe_url', 500)->nullable();

            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampTz('remote_deactivated_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users');

            $table->timestampsTz();

            $table->index(['invoice_id', 'created_at'], 'payment_links_invoice_created_index');
        });

        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_invoice_currency_fk FOREIGN KEY (invoice_id, currency) REFERENCES invoices (id, currency)');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_amount_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_state_check CHECK (state IN ('pending', 'active', 'failed', 'deactivated'))");
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_deactivation_check CHECK ((state = 'deactivated') = (deactivation_reason IS NOT NULL) AND (deactivation_reason IS NULL OR deactivation_reason IN ('paid', 'cancelled', 'amount_changed', 'manual', 'replaced')))");
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_failure_check CHECK ((state = 'failed') = (failure_class IS NOT NULL))");
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_stripe_url_check CHECK (stripe_url IS NULL OR stripe_url ~ '^https://([a-z0-9-]+\\.)*stripe\\.com/')");
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_active_check CHECK (state <> 'active' OR (stripe_link_id IS NOT NULL AND stripe_url IS NOT NULL))");
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_remote_check CHECK (remote_deactivated_at IS NULL OR state = 'deactivated')");

        DB::statement("CREATE UNIQUE INDEX payment_links_one_live_per_invoice ON payment_links (invoice_id) WHERE state IN ('pending', 'active')");
        DB::statement("CREATE INDEX payment_links_remote_pending_index ON payment_links (deactivated_at) WHERE state = 'deactivated' AND stripe_link_id IS NOT NULL AND remote_deactivated_at IS NULL");

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

              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER payment_links_guard BEFORE INSERT OR UPDATE OR DELETE ON payment_links FOR EACH ROW EXECUTE FUNCTION kokpit_guard_payment_link()');
        DB::unprepared(Immutability::truncateGuardSql('payment_links'));
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_payment_link()');
    }
};
