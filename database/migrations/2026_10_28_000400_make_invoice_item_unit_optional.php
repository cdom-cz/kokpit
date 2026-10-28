<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The unit of an invoice or proforma line is optional (IN-01, IN-02).
 *
 * Some lines, such as a flat fee or a licence, have no meaningful unit, and the editor refused to save them until
 * something was typed into the Unit field. A line without a unit is now stored with a NULL unit; a blank string stays
 * forbidden, the same convention as the recipient of a payment confirmation.
 *
 * The column drops NOT NULL and `invoice_items_unit_check` is relaxed to "NULL or not blank". Every existing row
 * satisfies the relaxed check, since the old one was stricter. The column length of 16 is unchanged.
 *
 * down() refuses to run while a line with a NULL unit exists: backfilling a unit would rewrite printed documents, and
 * the child guard triggers block writes to locked documents anyway. Otherwise it restores NOT NULL and the check
 * verbatim from 2026_10_17_000200_create_invoices_tables.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_items DROP CONSTRAINT invoice_items_unit_check');
        DB::statement('ALTER TABLE invoice_items ALTER COLUMN unit DROP NOT NULL');
        DB::statement("ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_unit_check CHECK (unit IS NULL OR btrim(unit) <> '')");
    }

    public function down(): void
    {
        $without = DB::selectOne('SELECT count(*) AS total FROM invoice_items WHERE unit IS NULL');

        if ((int) ($without->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: an invoice line has no unit, and the old column cannot hold it.');
        }

        // Verbatim from 2026_10_17_000200_create_invoices_tables.php.
        DB::statement('ALTER TABLE invoice_items DROP CONSTRAINT invoice_items_unit_check');
        DB::statement('ALTER TABLE invoice_items ALTER COLUMN unit SET NOT NULL');
        DB::statement("ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_unit_check CHECK (btrim(unit) <> '')");
    }
};
