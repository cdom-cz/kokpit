<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The log of the payment confirmation e-mail (Phase 13, P13-05, D-05). One Admin-only row per payment credited to an
 * invoice, whatever its method: it keeps who was mailed, in which language, how much was still owed after the
 * payment and the state of the sending job.
 *
 * Idempotence is enforced here, not in the application: `invoice_payment_id` is unique, so a webhook retry or a
 * corrected payment can never queue a second e-mail for the same payment; the second insert fails with 23505.
 *
 * The row follows its payment (`ON DELETE CASCADE`): a payment taken back inside the grace window before the e-mail
 * leaves takes its unsent row with it and there is nothing left to send (assumption A-13-6). A row for an unknown
 * payment is refused by the foreign key.
 *
 * No existing table carries the mark: `invoice_payments` and `invoices` refuse updates once written, and the document
 * e-mail log `invoice_emails` is not reused because its state drives the invoice lock and the in-flight edit rule.
 *
 * There is no guard trigger: the job moves `state` and `attempts` freely, like the one of `invoice_emails`.
 * The checks keep every row coherent: the state against `sent_at`, a failure code only on a failed row, and a
 * recipient that is null exactly when the row is skipped (nobody to mail) and never blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->uuid('invoice_payment_id');
            $table->foreignUuid('invoice_id')->constrained('invoices');

            $table->string('recipient', 255)->nullable();
            $table->char('language', 2);
            $table->bigInteger('remaining_minor');

            $table->string('state', 16)->default('queued');
            $table->integer('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('failure_code', 32)->nullable();

            $table->timestampsTz();

            $table->unique('invoice_payment_id', 'payment_confirmations_invoice_payment_unique');
            $table->index('invoice_id', 'payment_confirmations_invoice_id_index');
        });

        DB::statement('ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_invoice_payment_fk FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments (id) ON DELETE CASCADE');

        DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_state_check CHECK (state IN ('queued', 'sending', 'sent', 'failed', 'skipped'))");
        DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_language_check CHECK (language IN ('cs', 'en'))");
        DB::statement('ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_remaining_check CHECK (remaining_minor >= 0)');
        DB::statement('ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_attempts_check CHECK (attempts >= 0)');
        DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_sent_at_check CHECK ((state = 'sent') = (sent_at IS NOT NULL))");
        DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_failure_code_check CHECK (failure_code IS NULL OR state = 'failed')");
        DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_recipient_check CHECK ((recipient IS NULL) = (state = 'skipped') AND (recipient IS NULL OR btrim(recipient) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_confirmations');
    }
};
