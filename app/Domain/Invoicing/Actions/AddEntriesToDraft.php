<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\ItemGrouping;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceableEntries;
use App\Domain\Invoicing\Support\DraftLines;
use App\Domain\Invoicing\Support\EntryReservation;
use App\Domain\Invoicing\Support\InvoiceAudit;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
use App\Domain\Invoicing\Support\IssuedEditFinisher;
use App\Domain\Invoicing\Support\TimeItemGrouping;
use App\Domain\Invoicing\Support\TimeLineConversion;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use OverflowException;

/**
 * Adds newly tracked time to a draft or to an open issued invoice or proforma, "Přidat nový čas" (IN-02, IN-13, D-06,
 * D-07). The Admin only. The name stays; the lifecycle is decided on the locked document (R-10.1-09-1): a draft follows
 * the path below unchanged, an issued document asks {@see IssuedDocumentRules::assertEditable()} and follows the issued
 * path, anything else keeps the `not_draft` refusal.
 *
 * The chosen entries must belong to the document's client; of those, the ones that are still finished, billable,
 * unbilled, held by no document and hourly are claimed for this document with the same locked claim as
 * {@see CreateDraft} ({@see EntryReservation::claim()}), so an entry that another draft took in the meantime is simply
 * not added. New lines are built by {@see TimeItemGrouping} from the claimed entries only and appended after the last
 * position. An existing line is never read or rewritten: a line the Admin has edited by hand keeps its text, quantity
 * and price (D-06).
 *
 * The grouping follows the document: per task when any of its time lines already names a task, otherwise per project.
 * A line whose rate is in another currency is previewed with today's CNB rates like in the first draft; a missing
 * list is a `currency` field error and nothing is claimed.
 *
 * The issued path (R-10.1-09-2): the entries are claimed, then billed at once with their rate snapshot in id order
 * (reserving and billing are two statements, as the link guard requires), the lines are priced with the CNB lists of the
 * stored issue date and not of today, a line in another currency than its rate is frozen with those quotes by
 * {@see TimeLineConversion::freezeAt()}, the totals are refreshed, and the path ends with
 * {@see IssuedEditFinisher::finish()}: the CZK total, the card link, the PDF and, after the first send, the audit row
 * (D-09, D-11, D-13). A missing list of the issue date is `cnb_missing` under `currency` with that date.
 *
 * @phpstan-type Row array{id: string, seconds: int, project_id: ?string, project_name: ?string, task_id: ?string, task_reference: ?string, task_title: ?string, billing_type: string, rate_minor: ?int, rate_currency: ?string}
 * @phpstan-type Line array{description: string, quantity: InvoiceQuantity, unit: string, unit_price: Money, line_total: Money, price_converted: bool, source: array{origin: string, project_id: ?string, task_id: ?string, source_rate_minor: int, source_rate_currency: string, source_seconds: int, needs_conversion: bool}}
 */
final readonly class AddEntriesToDraft
{
    public function __construct(
        private InvoiceableEntries $entries,
        private EntryReservation $reservation,
        private TimeItemGrouping $grouping,
        private DraftLines $lines,
        private EntryBilling $billing,
        private IssuedEditFinisher $finisher,
    ) {}

    /**
     * @param  array<array-key, mixed>  $entryIds
     * @return array{entries: int, items: int} the entries claimed and the lines appended
     *
     * @throws ValidationException `entry_ids` when an entry is unknown or of another client, `currency`, `items`,
     *                             `document` when an issued document cannot take time
     * @throws DomainException when the document is neither a draft nor an issued document
     */
    public function handle(User $actor, Invoice $draft, array $entryIds): array
    {
        Gate::forUser($actor)->authorize('update', $draft);

        $ids = array_values(array_unique(array_filter($entryIds, static fn (mixed $id): bool => is_string($id) && Str::isUuid($id))));

        return DB::transaction(function () use ($actor, $draft, $ids): array {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isIssued()) {
                IssuedDocumentRules::assertEditable($locked);
            } elseif (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            if ($ids === []) {
                return ['entries' => 0, 'items' => 0];
            }

            if (TimeEntry::query()->whereIn('id', $ids)->where('client_id', $locked->client_id)->count() !== count($ids)) {
                throw ValidationException::withMessages(['entry_ids' => [__('invoices.errors.entries_foreign')]]);
            }

            return $locked->isIssued() ? $this->addToIssued($actor, $locked, $ids) : $this->addToDraft($locked, $ids);
        });
    }

    /**
     * @param  list<string>  $ids
     * @return array{entries: int, items: int}
     */
    private function addToDraft(Invoice $locked, array $ids): array
    {
        $now = TimerClock::now();
        $today = $now->setTimezone(FilamentTimezone::get())->format('Y-m-d');

        ['claimed' => $claimed, 'rows' => $rows] = $this->claim($locked, $ids, $now);
        $lines = $this->build($locked, $rows, $today);

        $this->lines->appendTime($locked, $lines);
        $this->lines->refreshTotals($locked);

        return ['entries' => $claimed->count(), 'items' => count($lines)];
    }

    /**
     * @param  list<string>  $ids
     * @return array{entries: int, items: int}
     */
    private function addToIssued(User $actor, Invoice $locked, array $ids): array
    {
        $before = InvoiceAudit::snapshot($locked);
        $issueDate = $locked->issue_date?->format('Y-m-d') ?? throw new LogicException('An issued document has its issue date.');

        $now = TimerClock::now();
        ['claimed' => $claimed, 'rows' => $rows] = $this->claim($locked, $ids, $now);

        if ($claimed->isEmpty()) {
            return ['entries' => 0, 'items' => 0];
        }

        $lines = $this->build($locked, $rows, $issueDate);

        $this->lines->appendTime($locked, $lines);

        // The new lines in another currency than their rate carry the quotes of the issue date; the others are left alone.
        try {
            TimeLineConversion::freezeAt($locked, $issueDate, false, false);
        } catch (ValidationException $exception) {
            $missing = $exception->errors()['cnb_missing'] ?? null;

            throw $missing === null ? $exception : ValidationException::withMessages(['currency' => $missing]);
        }

        $this->billing->bill($claimed, $now);
        $this->lines->refreshTotals($locked, InvoiceAudit::everSent($locked));

        $this->finisher->finish($actor, $locked, $before);

        return ['entries' => $claimed->count(), 'items' => count($lines)];
    }

    /**
     * Claims the eligible entries under their row locks, in id order. `rows` is the input of the lines, with the seconds
     * read under the lock.
     *
     * @param  list<string>  $ids
     * @return array{claimed: Collection<int, TimeEntry>, rows: list<Row>}
     */
    private function claim(Invoice $locked, array $ids, CarbonImmutable $now): array
    {
        $candidates = $this->entries->eligibleAmong($locked->client_id, $ids, $now);
        $claimed = $this->reservation->claim($locked, array_column($candidates, 'id'))->keyBy('id');

        // The lines are built from the rows the claim holds, with the seconds read under the lock.
        $rows = [];

        foreach ($candidates as $candidate) {
            $entry = $claimed->get($candidate['id']);

            if ($entry !== null) {
                $rows[] = [...$candidate, 'seconds' => (int) $entry->duration_seconds];
            }
        }

        return ['claimed' => $claimed->sortKeys()->values(), 'rows' => $rows];
    }

    /**
     * @param  list<Row>  $rows
     * @return list<Line>
     *
     * @throws ValidationException `currency` for a missing list, `items` for an amount that does not fit
     */
    private function build(Invoice $locked, array $rows, string $day): array
    {
        $grouping = $this->lines->groupedPerTask($locked) ? ItemGrouping::PerTask : ItemGrouping::PerProject;

        try {
            return $this->grouping->lines($rows, $grouping, $locked->currency, $locked->language->value, $day);
        } catch (ExchangeRateMissing $missing) {
            throw ValidationException::withMessages(['currency' => [__('invoices.errors.cnb_missing', ['currency' => $missing->currency, 'date' => IssueChecks::czechDate($day)])]]);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => [__('invoices.errors.item_price')]]);
        }
    }
}
