<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Queries;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What is paid, what is credited and what is still owed on a billing document (IN-05, D-17, D-20 groundwork,
 * research assumption A6). Nothing is stored: unpaid, partially paid and paid are three ranges of the one
 * `remaining` figure, which every screen, list filter and summary reads from here so they can never disagree.
 *
 * - paid: the sum of the payments of the document, in its currency;
 * - credited: the sum of the issued credit notes that name the document, as a positive amount (their totals are
 *   negative); a draft credit note does not count;
 * - remaining: the total less paid and credited, never below zero; zero for a cancelled invoice (an issued storno
 *   credit note names it, {@see Invoice::CANCELLED_SQL}), whatever was paid. A credit note or a draft owes nothing
 *   and has no remaining figure: it is null.
 *
 * The three figures are public SQL fragments over a table `invoices` (correlated subqueries), used by the list
 * filters and by {@see InvoiceBalances::for()} itself, so the PHP and the SQL path share one definition. The
 * fragments read the Admin-only payments table and ignore the Partner scope, so the reader refuses everyone but the
 * Admin and a system run, like the time report reader (T-10-45).
 */
final class InvoiceBalances
{
    /** The payments of the document, 0 when there are none. */
    public const string PAID_SQL = '(COALESCE((SELECT SUM(invoice_payments.amount_minor) FROM invoice_payments WHERE invoice_payments.invoice_id = invoices.id), 0))';

    /** The issued credit notes that name the document, as a positive sum (their totals are negative). */
    public const string CREDITED_SQL = "(COALESCE((SELECT -SUM(credit_notes.total_minor) FROM invoices AS credit_notes WHERE credit_notes.credited_invoice_id = invoices.id AND credit_notes.kind = 'credit_note' AND credit_notes.state = 'issued'), 0))";

    /**
     * What is still owed: zero for a draft, a credit note and a cancelled invoice, otherwise the total less paid and
     * credited, floored at zero.
     */
    public const string REMAINING_SQL = "(CASE WHEN invoices.state <> 'issued' OR invoices.kind = 'credit_note' OR ".Invoice::CANCELLED_SQL.' THEN 0 ELSE GREATEST(invoices.total_minor - '.self::PAID_SQL.' - '.self::CREDITED_SQL.', 0) END)';

    /**
     * The balance of each given document, keyed by its id. An unknown id is absent.
     *
     * @param  list<string>  $invoiceIds
     * @return array<string, array{paid: int, credited: int, remaining: int|null}>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function for(array $invoiceIds): array
    {
        $this->authorise();

        if ($invoiceIds === []) {
            return [];
        }

        $rows = Invoice::query()
            ->whereIn('invoices.id', $invoiceIds)
            ->selectRaw('invoices.id AS id, invoices.kind AS kind, invoices.state AS state, '.self::PAID_SQL.' AS paid, '.self::CREDITED_SQL.' AS credited, '.self::REMAINING_SQL.' AS remaining')
            ->toBase()
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $owes = $row->state === InvoiceState::Issued->value && $row->kind !== InvoiceKind::CreditNote->value;

            $balances[(string) $row->id] = [
                'paid' => (int) $row->paid,
                'credited' => (int) $row->credited,
                'remaining' => $owes ? (int) $row->remaining : null,
            ];
        }

        return $balances;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The invoice balances are available to the Admin only.');
        }
    }
}
