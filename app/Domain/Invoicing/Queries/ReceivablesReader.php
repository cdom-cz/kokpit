<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Queries;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceListTab;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * What is owed and what is late, derived and never stored (IN-05, IN-06; D-17, D-18, D-20; research Pattern 6): the one
 * place that turns payments, credit notes, due dates and the links between documents into a display status, a list tab
 * and the CZK summaries of the dashboard and the client panels, so no two screens can disagree.
 *
 * "Today" is a Prague calendar day (`Y-m-d`) the caller binds from PHP, see {@see ReceivablesReader::today()}; it is
 * never SQL `now()` or `CURRENT_DATE`, which ignore a frozen test clock and know nothing of the panel timezone
 * ({@see TimeEntry::elapsedSecondsSql()}). A due date equal to today is not late.
 *
 * Overdue is an issued invoice or proforma (R-19-2: an unpaid proforma is overdue after its due date as well), not
 * cancelled, with a remaining amount above zero and a due date before today; a partially paid document counts by its
 * remaining amount. The remaining amount, the cancelled rule and the payments come from the SQL fragments of
 * {@see InvoiceBalances} and {@see Invoice::CANCELLED_SQL}, the same text for the tab filters and the statuses.
 *
 * The raw fragments read the Admin-only payments table and bypass the Partner scope, so every method refuses everyone
 * but the Admin and a system run, like {@see InvoiceBalances} and the time report reader (T-10-52).
 */
final class ReceivablesReader
{
    /** An issued invoice was made from the proforma (the paid proforma no longer waits, D-18). */
    public const string CONVERTED_SQL = "EXISTS (SELECT 1 FROM invoices AS from_proforma WHERE from_proforma.proforma_id = invoices.id AND from_proforma.state = 'issued')";

    /** An issued document that can be paid: an invoice or a proforma. A credit note owes nothing. */
    private const string PAYABLE_SQL = "invoices.state = 'issued' AND invoices.kind IN ('invoice', 'proforma')";

    /** A payable document, not cancelled, with something left to pay. */
    private const string OPEN_SQL = self::PAYABLE_SQL.' AND NOT '.Invoice::CANCELLED_SQL.' AND '.InvoiceBalances::REMAINING_SQL.' > 0';

    /**
     * The calendar day of the panel timezone, Europe/Prague, at the clock's current instant: the day every caller
     * binds as "today". The clock is the frozen-clock aware one of the time module, never the database clock.
     */
    public static function today(): string
    {
        return TimerClock::now()->setTimezone(FilamentTimezone::get())->format('Y-m-d');
    }

    /**
     * The display status of each given document, keyed by its id: the status, the remaining amount in the
     * document currency (null for a draft and a credit note, which owe nothing) and the days past the due date
     * (null unless overdue).
     *
     * @param  iterable<Invoice>  $invoices
     * @return array<string, array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null}>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws InvalidArgumentException when `$today` is not a calendar day
     */
    public function statuses(iterable $invoices, string $today): array
    {
        $this->authorise();
        $day = $this->day($today);

        $documents = [];

        foreach ($invoices as $invoice) {
            $documents[$invoice->id] = $invoice;
        }

        if ($documents === []) {
            return [];
        }

        $balances = app(InvoiceBalances::class)->for(array_keys($documents));
        $flags = $this->flags(array_keys($documents));
        $statuses = [];

        foreach ($documents as $id => $invoice) {
            $statuses[$id] = $this->derive(
                $invoice,
                $balances[$id]['paid'] ?? 0,
                $balances[$id]['remaining'] ?? null,
                $flags[$id]['cancelled'] ?? false,
                $flags[$id]['converted'] ?? false,
                $day,
            );
        }

        return $statuses;
    }

    /**
     * Narrows a query of invoices to one list tab. The query must read the table `invoices` unaliased.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws InvalidArgumentException when `$today` is not a calendar day
     */
    public function constrain(Builder $query, InvoiceListTab $tab, string $today): Builder
    {
        $this->authorise();
        [$sql, $bindings] = $this->condition($tab, $this->day($today));

        return $query->whereRaw('('.$sql.')', $bindings);
    }

    /**
     * The number of documents under each tab, keyed by the tab value, from one aggregate query, optionally for one
     * client. A tab and its count are the same condition, so they cannot disagree.
     *
     * @return array<string, int>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws InvalidArgumentException when `$today` is not a calendar day
     */
    public function tabCounts(string $today, ?string $clientId = null): array
    {
        $this->authorise();
        $day = $this->day($today);

        /** @var list<literal-string> $selects */
        $selects = [];
        $bindings = [];

        foreach (InvoiceListTab::cases() as $tab) {
            [$sql, $tabBindings] = $this->condition($tab, $day);
            $selects[] = 'COUNT(*) FILTER (WHERE '.$sql.') AS '.$tab->value;
            $bindings = [...$bindings, ...$tabBindings];
        }

        $row = Invoice::query()
            ->when($clientId !== null, static fn (Builder $query): Builder => $query->where('invoices.client_id', $clientId))
            ->selectRaw(implode(', ', $selects), $bindings)
            ->toBase()
            ->first();

        $counts = [];

        foreach (InvoiceListTab::cases() as $tab) {
            $counts[$tab->value] = (int) ($row->{$tab->value} ?? 0);
        }

        return $counts;
    }

    /**
     * What is late and what is merely unpaid, with the remaining amounts in CZK (D-20, UI-SPEC U-6), optionally for
     * one client: the overdue and the unpaid (not yet late) documents each as a count and a CZK sum, and the number
     * of paid proformas still waiting for their invoice.
     *
     * @return array{overdue: array{count: int, czk: Money}, unpaid: array{count: int, czk: Money}, awaiting_invoice: int}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws InvalidArgumentException when `$today` is not a calendar day
     */
    public function summary(string $today, ?string $clientId = null): array
    {
        $this->authorise();
        $day = $this->day($today);

        return [
            'overdue' => $this->owed(InvoiceListTab::Overdue, $day, $clientId),
            'unpaid' => $this->owed(InvoiceListTab::Unpaid, $day, $clientId),
            'awaiting_invoice' => $this->tabCounts($day, $clientId)[InvoiceListTab::AwaitingInvoice->value],
        ];
    }

    /**
     * The count and the CZK sum of the remaining amounts under one tab.
     *
     * @return array{count: int, czk: Money}
     */
    private function owed(InvoiceListTab $tab, string $today, ?string $clientId): array
    {
        $rows = $this->constrain(Invoice::query(), $tab, $today)
            ->when($clientId !== null, static fn (Builder $query): Builder => $query->where('invoices.client_id', $clientId))
            ->leftJoin('invoice_admin', 'invoice_admin.invoice_id', '=', 'invoices.id')
            ->selectRaw('invoices.currency AS currency, '.InvoiceBalances::REMAINING_SQL.' AS remaining, invoice_admin.exchange_rate AS rate, invoice_admin.exchange_unit_amount AS unit_amount')
            ->toBase()
            ->get();

        $czk = Money::zero('CZK');

        foreach ($rows as $row) {
            $remaining = Money::ofMinor((int) $row->remaining, (string) $row->currency);

            if ($remaining->currency === 'CZK') {
                $czk = $czk->plus($remaining);

                continue;
            }

            // Each document converts once with its own frozen issue-time rate (ruling R-19-4: a document without the
            // trio can only be a CZK one, which needs none; a foreign one without it cannot be priced and adds nothing).
            if (is_string($row->rate) && $row->unit_amount !== null) {
                $czk = $czk->plus($remaining->convert($row->rate, 'CZK', (int) $row->unit_amount));
            }
        }

        return ['count' => $rows->count(), 'czk' => $czk];
    }

    /**
     * Whether each document is cancelled and whether an issued invoice was made from it, from one query.
     *
     * @param  list<string>  $ids
     * @return array<string, array{cancelled: bool, converted: bool}>
     */
    private function flags(array $ids): array
    {
        $rows = Invoice::query()
            ->whereIn('invoices.id', $ids)
            ->selectRaw('invoices.id AS id, '.Invoice::CANCELLED_SQL.' AS cancelled, '.self::CONVERTED_SQL.' AS converted')
            ->toBase()
            ->get();

        $flags = [];

        foreach ($rows as $row) {
            $flags[(string) $row->id] = [
                'cancelled' => in_array($row->cancelled, [true, 1, '1', 't', 'true'], true),
                'converted' => in_array($row->converted, [true, 1, '1', 't', 'true'], true),
            ];
        }

        return $flags;
    }

    /**
     * The status of one document from its balances and links (ruling R-19-3): a draft and a credit note have a status
     * and no remaining amount; a cancelled invoice owes nothing; a proforma with nothing left is converted when an
     * issued invoice was made from it and otherwise waits for its invoice (D-18); the rest is unpaid, partly paid,
     * overdue or paid by the remaining amount.
     *
     * @return array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null}
     */
    private function derive(Invoice $invoice, int $paid, ?int $remaining, bool $cancelled, bool $converted, string $today): array
    {
        if ($invoice->state === InvoiceState::Draft) {
            return ['status' => InvoiceStatus::Draft, 'remaining' => null, 'overdue_days' => null];
        }

        if ($invoice->kind === InvoiceKind::CreditNote) {
            return ['status' => InvoiceStatus::CreditNote, 'remaining' => null, 'overdue_days' => null];
        }

        $left = Money::ofMinor($cancelled ? 0 : ($remaining ?? 0), $invoice->currency);

        if ($cancelled) {
            return ['status' => InvoiceStatus::Cancelled, 'remaining' => $left, 'overdue_days' => null];
        }

        if ($left->isZero()) {
            $status = match (true) {
                $invoice->kind !== InvoiceKind::Proforma => InvoiceStatus::Paid,
                $converted => InvoiceStatus::Converted,
                default => InvoiceStatus::AwaitingInvoice,
            };

            return ['status' => $status, 'remaining' => $left, 'overdue_days' => null];
        }

        $late = $this->daysLate($invoice, $today);

        if ($late !== null) {
            return ['status' => InvoiceStatus::Overdue, 'remaining' => $left, 'overdue_days' => $late];
        }

        return ['status' => $paid > 0 ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Unpaid, 'remaining' => $left, 'overdue_days' => null];
    }

    /**
     * The whole days between the due date and today when the due date lies before today, otherwise null.
     */
    private function daysLate(Invoice $invoice, string $today): ?int
    {
        if ($invoice->due_date === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $due = new DateTimeImmutable($invoice->due_date->format('Y-m-d'), $utc);
        $now = new DateTimeImmutable($today, $utc);

        return $due < $now ? (int) $due->diff($now)->days : null;
    }

    /**
     * The condition of one tab as SQL over the table `invoices` and the values it binds (R-19-3).
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private function condition(InvoiceListTab $tab, string $today): array
    {
        $issued = "invoices.state = 'issued' AND invoices.kind <> 'credit_note' AND NOT ".Invoice::CANCELLED_SQL;

        return match ($tab) {
            InvoiceListTab::All => ['TRUE', []],
            InvoiceListTab::Drafts => ["invoices.state = 'draft'", []],
            InvoiceListTab::Unpaid => [self::OPEN_SQL.' AND (invoices.due_date IS NULL OR invoices.due_date >= CAST(? AS date))', [$today]],
            InvoiceListTab::Overdue => [self::OPEN_SQL.' AND invoices.due_date < CAST(? AS date)', [$today]],
            InvoiceListTab::Paid => [$issued.' AND '.InvoiceBalances::REMAINING_SQL.' = 0', []],
            InvoiceListTab::Cancelled => ["invoices.state = 'issued' AND ".Invoice::CANCELLED_SQL, []],
            InvoiceListTab::AwaitingInvoice => [$issued." AND invoices.kind = 'proforma' AND ".InvoiceBalances::REMAINING_SQL.' = 0 AND NOT '.self::CONVERTED_SQL, []],
        };
    }

    /**
     * A real calendar day as `Y-m-d`.
     *
     * @throws InvalidArgumentException
     */
    private function day(string $today): string
    {
        return PragueDay::day($today) ?? throw new InvalidArgumentException('Today must be a calendar day (Y-m-d): '.$today);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The receivables are available to the Admin only.');
        }
    }
}
