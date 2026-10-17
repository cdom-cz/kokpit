<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The link between a transaction and the invoice it books (FI-03; D-18, D-19 as amended by the owner on
 * 2026-10-17, Phase 9 D-14). This replaces the Phase 9 note "The link to an invoice is added in Phase 10" by what
 * now holds.
 *
 * Columns and keys (ruling R-06-4). `invoice_id` and `invoice_payment_id` are both nullable. The composite key
 * `transactions_invoice_client_fk (invoice_id, client_id) -> invoices (id, client_id)` lets a transaction name only
 * an invoice of its own client, and `transactions_invoice_payment_fk (invoice_payment_id, invoice_id) ->
 * invoice_payments (id, invoice_id)` only a payment of that very invoice. Both are NO ACTION and not deferrable
 * (ruling R-06-7): a payment whose income still exists cannot be deleted (23503). A payment books at most one
 * income (`transactions_invoice_payment_unique`). A linked transaction is an income with a client
 * (`transactions_invoice_income_check`), and a payment link needs the invoice link
 * (`transactions_invoice_payment_link_check`); a composite key is skipped when one column is NULL, so these two
 * CHECKs close the gap.
 *
 * The income of a payment is locked by `kokpit_guard_payment_income()` (ruling R-06-5), for every
 * writer: the finance screen, the Transactions API of Phase 9.1 and raw SQL. For a row that books a payment:
 * - an UPDATE may change only `transaction_date`, `category_id`, the CZK figure that follows the date
 *   (`czk_minor`, `czk_source`, `exchange_rate`, `exchange_unit_amount`, `exchange_rate_date`) and `updated_at`;
 *   type, amount, currency, client, project, description, counterparty, document number and both links are
 *   refused with KP001 (the texts are derived from the invoice);
 * - a DELETE is refused with KP001 unless the transaction-local setting `kokpit.payment_deletion` names the payment
 *   it books. Only `DeletePayment` (plan 10-41) sets it, with `set_config('kokpit.payment_deletion', <payment id>,
 *   true)`, for one payment, and clears it again before its transaction ends.
 * For any other row an UPDATE that sets `invoice_payment_id` is refused, so payment income is created only at
 * insert, by `RecordPayment`.
 *
 * A payment and its income go together (ruling R-06-7). The payment cannot be deleted while its income exists (the
 * NO ACTION key above), so `DeletePayment` deletes the income first. The deferred constraint trigger
 * `transactions_payment_income_pair_check` closes the other half at commit: it raises KP001 when the payment of a
 * deleted income still exists, so a flagged deletion of the income alone can never commit and no writer leaves a
 * paid invoice without its income or an income without its payment. A test that runs inside a wrapping transaction
 * forces the check with SET CONSTRAINTS transactions_payment_income_pair_check IMMEDIATE.
 *
 * Limit, stated once: like every guard here, a database owner who sets the flag deliberately bypasses the rule in
 * the same way as one who disables triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transactions ADD COLUMN invoice_id uuid');
        DB::statement('ALTER TABLE transactions ADD COLUMN invoice_payment_id uuid');

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_invoice_client_fk FOREIGN KEY (invoice_id, client_id) REFERENCES invoices (id, client_id)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_invoice_payment_fk FOREIGN KEY (invoice_payment_id, invoice_id) REFERENCES invoice_payments (id, invoice_id)');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_invoice_income_check CHECK (invoice_id IS NULL OR (type = 'income' AND client_id IS NOT NULL))");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_invoice_payment_link_check CHECK (invoice_payment_id IS NULL OR invoice_id IS NOT NULL)');

        DB::statement('CREATE UNIQUE INDEX transactions_invoice_payment_unique ON transactions (invoice_payment_id) WHERE invoice_payment_id IS NOT NULL');
        DB::statement('CREATE INDEX transactions_invoice_id_index ON transactions (invoice_id) WHERE invoice_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_payment_income() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              allowed text[] := ARRAY['transaction_date', 'category_id', 'czk_minor', 'czk_source', 'exchange_rate', 'exchange_unit_amount', 'exchange_rate_date', 'updated_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF OLD.invoice_payment_id IS NOT NULL AND current_setting('kokpit.payment_deletion', true) IS DISTINCT FROM OLD.invoice_payment_id::text THEN
                  RAISE EXCEPTION 'the income of an invoice payment is deleted only together with its payment' USING ERRCODE = 'KP001';
                END IF;
                RETURN OLD;
              END IF;

              IF OLD.invoice_payment_id IS NOT NULL THEN
                IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
                  RAISE EXCEPTION 'the income of an invoice payment changes only in its date and its category' USING ERRCODE = 'KP001';
                END IF;
              ELSIF NEW.invoice_payment_id IS NOT NULL THEN
                RAISE EXCEPTION 'an existing transaction cannot become the income of an invoice payment' USING ERRCODE = 'KP001';
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER transactions_payment_income_guard BEFORE UPDATE OR DELETE ON transactions FOR EACH ROW EXECUTE FUNCTION kokpit_guard_payment_income()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_check_payment_income_pair() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.invoice_payment_id IS NOT NULL AND EXISTS (SELECT 1 FROM invoice_payments WHERE id = OLD.invoice_payment_id) THEN
                RAISE EXCEPTION 'the income of an invoice payment was deleted while its payment still exists' USING ERRCODE = 'KP001';
              END IF;
              RETURN NULL;
            END $$
            SQL);

        DB::unprepared('CREATE CONSTRAINT TRIGGER transactions_payment_income_pair_check AFTER DELETE ON transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kokpit_check_payment_income_pair()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS transactions_payment_income_pair_check ON transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_check_payment_income_pair()');
        DB::unprepared('DROP TRIGGER IF EXISTS transactions_payment_income_guard ON transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_payment_income()');

        DB::statement('DROP INDEX IF EXISTS transactions_invoice_id_index');
        DB::statement('DROP INDEX IF EXISTS transactions_invoice_payment_unique');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_invoice_payment_link_check');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_invoice_income_check');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_invoice_payment_fk');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_invoice_client_fk');
        DB::statement('ALTER TABLE transactions DROP COLUMN IF EXISTS invoice_payment_id');
        DB::statement('ALTER TABLE transactions DROP COLUMN IF EXISTS invoice_id');
    }
};
