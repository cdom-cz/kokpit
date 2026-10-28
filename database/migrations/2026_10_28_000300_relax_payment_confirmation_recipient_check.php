<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A skipped payment confirmation may keep its recipient (Phase 16; D-06, D-07, research Pitfall 2).
 *
 * Until now `payment_confirmations_recipient_check` tied the recipient to the state both ways: null exactly when the row
 * is skipped. With the per-client switch "send documents by e-mail" a skipped row can also be one whose client has an
 * invoice e-mail but has document e-mails switched off (D-06, written directly as skipped by the queue Action), or one
 * that was queued for an address and skipped by the job's re-check (D-07). Both keep the address they would have gone
 * to, so the history shows who was not mailed.
 *
 * The new check allows a null recipient only on a skipped row, requires a recipient on every row in any other state,
 * and still forbids a blank recipient everywhere. The state check already holds `skipped` (the Phase 13 state for a
 * client without a usable e-mail), and every existing row satisfies the relaxed check, since the old one was stricter.
 *
 * down() refuses to run while a skipped row with a recipient exists (the old check cannot hold it, and clearing the
 * address would rewrite the history); otherwise it restores the check verbatim from
 * 2026_10_25_000100_create_payment_confirmations_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_confirmations DROP CONSTRAINT payment_confirmations_recipient_check');
        DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_recipient_check CHECK ((recipient IS NULL AND state = 'skipped') OR (recipient IS NOT NULL AND btrim(recipient) <> ''))");
    }

    public function down(): void
    {
        $kept = DB::selectOne("SELECT count(*) AS total FROM payment_confirmations WHERE state = 'skipped' AND recipient IS NOT NULL");

        if ((int) ($kept->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: a skipped payment confirmation keeps its recipient, and the old check cannot hold it.');
        }

        // Verbatim from 2026_10_25_000100_create_payment_confirmations_table.php.
        DB::statement('ALTER TABLE payment_confirmations DROP CONSTRAINT payment_confirmations_recipient_check');
        DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_recipient_check CHECK ((recipient IS NULL) = (state = 'skipped') AND (recipient IS NULL OR btrim(recipient) <> ''))");
    }
};
