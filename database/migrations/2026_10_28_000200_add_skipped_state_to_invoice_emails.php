<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The `skipped` state of a document e-mail (Phase 16; D-07, D-08).
 *
 * A document e-mail still queued when the Admin switches the client's document e-mails off is not sent: the send job
 * finds the switch off after its claim, or immediately before the transport call, and ends the row `skipped`. The row
 * stays in the history (D-07), is neither failed nor unsent (D-08), and is never revived by switching back on. Only the
 * list of allowed values of `invoice_emails_state_check` grows; no column is added.
 *
 * The other checks of the table were reviewed and stay unchanged:
 *  - `invoice_emails_sent_at_check` ((state = 'sent') = (sent_at IS NOT NULL)): a skipped row was never sent and has no
 *    `sent_at`, which the check already demands of every state but `sent`.
 *  - `invoice_emails_failure_code_check` (failure_code IS NULL OR state = 'failed'): a skipped row is not a failure and
 *    carries no failure code, which the check already demands of every state but `failed`.
 *  - `invoice_emails_recipient_check`: a skipped row keeps the recipient it was queued for.
 *
 * down() refuses to run while a skipped row exists (the old check cannot hold such a row, and turning it into another
 * state would rewrite the history); otherwise it restores the check verbatim from
 * 2026_10_17_000300_create_invoice_admin_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_emails DROP CONSTRAINT invoice_emails_state_check');
        DB::statement("ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_state_check CHECK (state IN ('queued', 'sending', 'sent', 'failed', 'skipped'))");
    }

    public function down(): void
    {
        $skipped = DB::selectOne("SELECT count(*) AS total FROM invoice_emails WHERE state = 'skipped'");

        if ((int) ($skipped->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: a skipped document e-mail exists, and the old check cannot hold it.');
        }

        // Verbatim from 2026_10_17_000300_create_invoice_admin_tables.php.
        DB::statement('ALTER TABLE invoice_emails DROP CONSTRAINT invoice_emails_state_check');
        DB::statement("ALTER TABLE invoice_emails ADD CONSTRAINT invoice_emails_state_check CHECK (state IN ('queued', 'sending', 'sent', 'failed'))");
    }
};
