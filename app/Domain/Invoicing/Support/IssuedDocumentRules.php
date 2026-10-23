<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one rule that says whether an issued document may be corrected (D-01, D-04; assumptions A1 and A3; R-10.1-04-2).
 * The write path ({@see UpdateIssuedDocument}) asks it under the row lock, the pages and
 * the later plans ask it to decide what to offer; none of them names the lock state themselves.
 *
 * {@see IssuedDocumentRules::editBlocker()} returns the first reason a document cannot be edited, as the key of
 * `invoices.errors`, or null when it can:
 *
 * - `not_issued`: a draft, which is edited in the draft editor;
 * - `credit_note_locked`: a credit note, never edited after its issue (A1);
 * - `document_locked`: the document was sent and is locked until the Admin unlocks it (D-01);
 * - `cancelled_not_editable`: an issued storno credit note names the invoice (A3);
 * - `converted_not_editable`: the proforma already has its invoice (A3);
 * - `email_in_flight`: an e-mail of the document is queued, or sending and younger than the stale threshold of the
 *   System page (A8, R-10.1-04-2). A queued or sending e-mail does not lock the document (D-01), so the edit waits for
 *   it instead: the customer must not receive a PDF that differs from the stored one. An older sending row counts as
 *   failed and a failed row is not in flight, so nothing blocks forever.
 *
 * Payments and credit notes are not reasons: an invoice with money already received or credited stays correctable
 * (D-04), {@see IssuedDocumentRules::warnings()} only reports when the total falls below them.
 *
 * The e-mail log is Admin-only, so the in-flight reason is read in an Admin or system context, which is where every
 * edit and every page that offers one runs.
 */
final class IssuedDocumentRules
{
    /**
     * The first reason the document cannot be edited, or null when it is an open issued invoice or proforma.
     */
    public static function editBlocker(Invoice $invoice): ?string
    {
        return match (true) {
            ! $invoice->isIssued() => 'not_issued',
            $invoice->kind === InvoiceKind::CreditNote => 'credit_note_locked',
            $invoice->isLocked() => 'document_locked',
            $invoice->isCancelled() => 'cancelled_not_editable',
            $invoice->kind === InvoiceKind::Proforma && $invoice->invoiceFromProforma()->exists() => 'converted_not_editable',
            self::emailInFlight($invoice) => 'email_in_flight',
            default => null,
        };
    }

    /**
     * Why the currency of the document cannot change, as the key of `invoices.errors`, or null when it can (D-06, owner
     * ruling of 2026-10-23, an amendment of D-04 for the currency only): money received is not re-denominated, so a
     * document with a recorded payment (`currency_has_payments`) or one that any credit note, draft or issued, names
     * (`currency_has_credit_notes`) keeps its currency. Every other edit of such a document stays allowed. The database
     * holds the same line for every other writer: the key of the payments and the currency rule of the invoice row guard.
     */
    public static function currencyBlocker(Invoice $invoice): ?string
    {
        return match (true) {
            InvoicePayment::query()->where('invoice_id', $invoice->id)->exists() => 'currency_has_payments',
            Invoice::query()->where('credited_invoice_id', $invoice->id)->exists() => 'currency_has_credit_notes',
            default => null,
        };
    }

    /**
     * The currencies the editor offers for the document, sorted: those of the supplier's bank accounts, the currency of
     * the client and the one the document has now. The write path accepts nothing else (T-10.1-31).
     *
     * @return list<string>
     */
    public static function currencyChoices(Invoice $invoice): array
    {
        $codes = array_map(static fn (BankAccount $account): string => $account->currency, app(BankAccountSettings::class)->accounts);
        $codes[] = $invoice->currency;

        if ($invoice->client !== null) {
            $codes[] = $invoice->client->currency;
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * What is odd about the amounts after an edit, without blocking it (D-04): `total_below_paid` carries the paid
     * amount when more was paid than the document now totals, `total_below_credited` the credited amount when more was
     * credited. Both come from the stored payment and credit note rows through {@see InvoiceBalances}, so the paid
     * state and the remaining amount follow on their own. A draft, a credit note and a reader that is not the Admin
     * get an empty list; the method never throws.
     *
     * @return array<string, Money>
     */
    public static function warnings(Invoice $invoice): array
    {
        if (! $invoice->isIssued() || $invoice->kind === InvoiceKind::CreditNote) {
            return [];
        }

        try {
            $balance = app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id] ?? null;
        } catch (AuthorizationException) {
            return [];
        }

        if ($balance === null) {
            return [];
        }

        $warnings = [];

        if ($balance['paid'] > $invoice->total_minor) {
            $warnings['total_below_paid'] = Money::ofMinor($balance['paid'], $invoice->currency);
        }

        if ($balance['credited'] > $invoice->total_minor) {
            $warnings['total_below_credited'] = Money::ofMinor($balance['credited'], $invoice->currency);
        }

        return $warnings;
    }

    /**
     * The ids of the lines of the document that a credit note line names, draft or issued (assumption A4): such a line
     * keeps its quantity, unit and unit price and cannot be deleted. The write path and the editor ask here, so both
     * see the same lines.
     *
     * @return array<string, true> the ids as keys
     */
    public static function creditedLineIds(Invoice $invoice): array
    {
        /** @var array<string, true> $credited */
        $credited = InvoiceItem::query()
            ->whereIn('credited_item_id', InvoiceItem::query()->where('invoice_id', $invoice->id)->select('id'))
            ->distinct()
            ->pluck('credited_item_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();

        return $credited;
    }

    /**
     * Throws when the new issue date of the (locked) document would make its number contradict its series
     * (assumption A5, R-10.1-07-2, research Pitfall 4). The number never changes (D-08), so the date may only move
     * where the number still fits:
     *
     * - not after today (Prague);
     * - inside the numbering period of the old date under the pattern the series has now: both days, taken at Prague
     *   noon, give the same allocator scope key ({@see NumberPattern::scopeKey()});
     * - between the issue dates of the previous and the next issued document of the same kind, neighbours ordered by
     *   the instant they were issued; a missing neighbour leaves that bound open, and a bound itself is allowed.
     *
     * Every problem is collected under `issue_date`, so the Admin sees all of them at once.
     *
     * @param  string  $newDate  Prague `Y-m-d`
     * @param  string  $today  the current Prague calendar day, `Y-m-d`
     *
     * @throws ValidationException `issue_date`
     */
    public static function assertIssueDate(Invoice $locked, string $newDate, string $today): void
    {
        $messages = [];

        if ($newDate > $today) {
            $messages[] = __('invoices.errors.issue_date_future');
        }

        $old = $locked->issue_date?->format('Y-m-d');

        if ($old !== null) {
            try {
                $pattern = app(NumberingSettings::class)->patternFor($locked->kind->numberingKind());

                if ($pattern->scopeKey(self::noonOf($old)) !== $pattern->scopeKey(self::noonOf($newDate))) {
                    $messages[] = __('invoices.errors.issue_date_scope');
                }
            } catch (InvalidNumberPattern) {
                $messages[] = __('invoices.errors.pattern_invalid', ['kind' => mb_strtolower($locked->kind->numberingKind()->getLabel())]);
            }
        }

        $order = self::orderProblem($locked, $newDate);

        if ($order !== null) {
            $messages[] = $order;
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['issue_date' => $messages]);
        }
    }

    /**
     * Whether the frozen exchange figures stay behind a moved issue date: a document not in CZK, or one with a line
     * converted at issue, keeps the CNB rates of the original issue date (assumption A6).
     */
    public static function keepsIssueRates(Invoice $invoice): bool
    {
        return $invoice->currency !== 'CZK'
            || InvoiceItemSource::query()->where('invoice_id', $invoice->id)->whereNotNull('conversion_snapshot')->exists();
    }

    /**
     * Throws when the (locked) document cannot be edited.
     *
     * @throws ValidationException `document` with the copy of the reason
     */
    public static function assertEditable(Invoice $locked): void
    {
        $reason = self::editBlocker($locked);

        if ($reason !== null) {
            throw ValidationException::withMessages(['document' => __('invoices.errors.'.$reason)]);
        }
    }

    /**
     * The copy for a date outside the bounds the neighbours of the series set, or null when it lies inside them.
     */
    private static function orderProblem(Invoice $locked, string $newDate): ?string
    {
        if ($locked->issued_at === null) {
            return null;
        }

        $previous = self::neighbourDay($locked, '<', 'desc');
        $next = self::neighbourDay($locked, '>', 'asc');
        $below = $previous !== null && $newDate < $previous;
        $above = $next !== null && $newDate > $next;

        if (! $below && ! $above) {
            return null;
        }

        return match (true) {
            $previous !== null && $next !== null => __('invoices.errors.issue_date_order', ['previous' => IssueChecks::czechDate($previous), 'next' => IssueChecks::czechDate($next)]),
            $previous !== null => __('invoices.errors.issue_date_after_previous', ['previous' => IssueChecks::czechDate($previous)]),
            default => __('invoices.errors.issue_date_before_next', ['next' => IssueChecks::czechDate((string) $next)]),
        };
    }

    /**
     * The issue date of the nearest issued document of the same kind on one side of this one in the order of issuing.
     *
     * @param  '<'|'>'  $operator
     * @param  'asc'|'desc'  $direction
     */
    private static function neighbourDay(Invoice $locked, string $operator, string $direction): ?string
    {
        return self::dayOf(
            Invoice::query()
                ->where('kind', $locked->kind->value)
                ->where('state', InvoiceState::Issued->value)
                ->whereKeyNot($locked->id)
                ->where('issued_at', $operator, $locked->issued_at)
                ->orderBy('issued_at', $direction)
                ->value('issue_date'),
        );
    }

    private static function dayOf(mixed $value): ?string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_string($value) => substr($value, 0, 10),
            default => null,
        };
    }

    /**
     * Midday of a Prague calendar day: the instant the numbering reads its year or month from, as the issue does.
     */
    private static function noonOf(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $day.' 12:00', FilamentTimezone::get())
            ?? throw new InvalidArgumentException('Not a calendar day: '.$day);
    }

    /**
     * Whether an e-mail of the document is queued, or sending and younger than the stale threshold (A8). The age of a
     * sending row runs from its last move, the same reading as the System page.
     */
    private static function emailInFlight(Invoice $invoice): bool
    {
        $staleBefore = CarbonImmutable::now()->subSeconds(max(1, (int) config('kokpit.health.unsent_invoice_email_stale_after')));

        return InvoiceEmail::query()
            ->where('invoice_id', $invoice->id)
            ->where(static function (Builder $query) use ($staleBefore): void {
                $query->where('state', InvoiceEmailState::Queued->value)
                    ->orWhere(static function (Builder $sending) use ($staleBefore): void {
                        $sending->where('state', InvoiceEmailState::Sending->value)->where('updated_at', '>', $staleBefore);
                    });
            })
            ->exists();
    }
}
