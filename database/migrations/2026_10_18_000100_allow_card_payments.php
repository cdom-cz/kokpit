<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A card payment (Phase 11, plan 11-02, ruling R11-02-4) reaches the system from a queue job with no user, so an
 * invoice payment may now have the method `card` and, for that method only, no creator:
 *
 * - invoice_payments_method_check accepts transfer, cash and card;
 * - invoice_payments.created_by drops NOT NULL;
 * - invoice_payments_creator_check refuses a payment without created_by unless its method is card, so every manual
 *   payment still names the person who recorded it and the database holds that rule on its own.
 *
 * A card payment assigned by the Admin carries the Admin as creator; one matched from the Stripe webhook carries
 * none. DDL fires no row guard, so the payment guard trigger of 10-41 is not involved.
 *
 * down() refuses to run while a card payment, or any payment without a creator, exists, because the old constraints
 * would refuse that row; otherwise it restores both constraints and NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_payments DROP CONSTRAINT invoice_payments_method_check');
        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_method_check CHECK (method IN ('transfer', 'cash', 'card'))");
        DB::statement('ALTER TABLE invoice_payments ALTER COLUMN created_by DROP NOT NULL');
        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_creator_check CHECK (method = 'card' OR created_by IS NOT NULL)");
    }

    public function down(): void
    {
        $blocking = DB::selectOne("SELECT count(*) AS total FROM invoice_payments WHERE method = 'card' OR created_by IS NULL");

        if ((int) ($blocking->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: a card payment or a payment without a creator exists, and the previous constraints would refuse it.');
        }

        DB::statement('ALTER TABLE invoice_payments DROP CONSTRAINT invoice_payments_creator_check');
        DB::statement('ALTER TABLE invoice_payments ALTER COLUMN created_by SET NOT NULL');
        DB::statement('ALTER TABLE invoice_payments DROP CONSTRAINT invoice_payments_method_check');
        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_method_check CHECK (method IN ('transfer', 'cash'))");
    }
};
