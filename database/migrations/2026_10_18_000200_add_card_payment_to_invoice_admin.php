<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The per-document card switch and the stable pay token (Phase 11, plan 11-03, rulings R11-03-2, D-01, D-06).
 *
 * invoice_admin gains two columns:
 *
 * - card_payment_enabled: whether the document offers payment by card. On a draft it is the Admin's choice (it starts as
 *   the client's value); at issue it is frozen as the AND of the draft switch, the global setting and a configured
 *   Stripe, for an invoice or a proforma only.
 * - pay_token: the 32 random letters and digits of the stable pay URL `<app.url>/pay/<token>`, printed into the PDF and
 *   the e-mail. It exists exactly when the switch is on at issue, is unique, and is a bearer value, so it is never
 *   logged and the Admin-only table is never readable by a Partner.
 *
 * invoice_admin_pay_token_check ties the two together: a token needs the switch on and the exact format. The existing
 * child guard of invoice_admin (plan 10-03) freezes every column but the note, its marker, the PDF link and updated_at
 * once the invoice is issued, so both new columns are frozen with the document and no trigger changes here.
 *
 * down() refuses to run once any document carries a token: the printed links would stop working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_admin', function ($table): void {
            $table->boolean('card_payment_enabled')->default(false);
            $table->string('pay_token', 32)->nullable();

            $table->unique('pay_token', 'invoice_admin_pay_token_unique');
        });

        DB::statement("ALTER TABLE invoice_admin ADD CONSTRAINT invoice_admin_pay_token_check CHECK (pay_token IS NULL OR (card_payment_enabled AND pay_token ~ '^[A-Za-z0-9]{32}\$'))");
    }

    public function down(): void
    {
        $issued = DB::selectOne('SELECT count(*) AS total FROM invoice_admin WHERE pay_token IS NOT NULL');

        if ((int) ($issued->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: a document carries a pay token that is already printed or sent.');
        }

        DB::statement('ALTER TABLE invoice_admin DROP CONSTRAINT invoice_admin_pay_token_check');

        Schema::table('invoice_admin', function ($table): void {
            $table->dropUnique('invoice_admin_pay_token_unique');
            $table->dropColumn(['card_payment_enabled', 'pay_token']);
        });
    }
};
