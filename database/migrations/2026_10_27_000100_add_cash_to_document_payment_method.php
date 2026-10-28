<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Cash as the third payment method of a document (Phase 15; D-01, D-02).
 *
 * `invoices.payment_method` said exactly one of two things since Phase 14: `transfer` or `online`. From here it also
 * says `cash`: the customer pays the document in cash and the Admin records that payment (the cash receipt document
 * follows in later plans). The column and its meaning stay; only the list of allowed values grows, so no new column is
 * added. A credit note keeps NULL, the kind check `invoices_payment_method_kind_check` is not touched.
 *
 * A cash document hides every payment detail, the same way an online document does (D-02): it carries no bank
 * snapshot. `invoices_issued_fields_check` therefore changes from `(payment_method = 'online') = (bank_snapshot IS NULL)`
 * to `(payment_method IN ('online', 'cash')) = (bank_snapshot IS NULL)`: the snapshot is required for `transfer` and
 * forbidden for `online` and `cash`. Every other part of the check is verbatim from
 * 2026_10_24_000200_add_payment_method_to_invoices.php.
 *
 * `kokpit_guard_invoice_row()` needs no change: `payment_method` is already an open column of an issued document (D-09
 * of Phase 14), and the guard names no value of it.
 *
 * Both checks are dropped and re-added under the same names. The table already satisfies both new checks, because
 * they only widen the first one and the second one only moves `cash` into the group that carries no snapshot; no row
 * holds `cash` before this migration.
 *
 * down() refuses to run while a cash document exists (the old checks cannot hold such a row, and turning it into a
 * transfer document would invent bank details); otherwise it restores both checks verbatim from the Phase 14
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_payment_method_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('transfer', 'online', 'cash'))");

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_issued_fields_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_issued_fields_check CHECK (state = 'draft' OR (issue_date IS NOT NULL AND supplier_snapshot IS NOT NULL AND customer_snapshot IS NOT NULL AND appearance_snapshot IS NOT NULL AND (kind = 'credit_note' OR (due_date IS NOT NULL AND (payment_method IN ('online', 'cash')) = (bank_snapshot IS NULL)))))");
    }

    public function down(): void
    {
        $cash = DB::selectOne("SELECT count(*) AS total FROM invoices WHERE payment_method = 'cash'");

        if ((int) ($cash->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: a cash document exists, and the old checks cannot hold it.');
        }

        // Verbatim from 2026_10_24_000200_add_payment_method_to_invoices.php.
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_issued_fields_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_issued_fields_check CHECK (state = 'draft' OR (issue_date IS NOT NULL AND supplier_snapshot IS NOT NULL AND customer_snapshot IS NOT NULL AND appearance_snapshot IS NOT NULL AND (kind = 'credit_note' OR (due_date IS NOT NULL AND (payment_method = 'online') = (bank_snapshot IS NULL)))))");

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_payment_method_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('transfer', 'online'))");
    }
};
