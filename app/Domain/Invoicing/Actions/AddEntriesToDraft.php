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
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\TimeItemGrouping;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use DomainException;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OverflowException;

/**
 * Adds newly tracked time to a draft, "Přidat nový čas" (IN-02, D-06). The Admin only.
 *
 * The chosen entries must belong to the draft's client; of those, the ones that are still finished, billable, unbilled,
 * held by no draft and hourly are claimed for this draft with the same locked claim as {@see CreateDraft}
 * ({@see EntryReservation::claim()}), so an entry that another draft took in the meantime is simply not added. New lines
 * are built by {@see TimeItemGrouping} from the claimed entries only and appended after the last position. An existing
 * line is never read or rewritten: a line the Admin has edited by hand keeps its text, quantity and price (D-06).
 *
 * The grouping follows the draft: per task when any of its time lines already names a task, otherwise per project.
 * A line whose rate is in another currency is previewed with today's CNB rates like in the first draft; a missing
 * list is a `currency` field error and nothing is claimed.
 */
final readonly class AddEntriesToDraft
{
    public function __construct(
        private InvoiceableEntries $entries,
        private EntryReservation $reservation,
        private TimeItemGrouping $grouping,
        private DraftLines $lines,
    ) {}

    /**
     * @param  array<array-key, mixed>  $entryIds
     * @return array{entries: int, items: int} the entries claimed and the lines appended
     *
     * @throws ValidationException `entry_ids` when an entry is unknown or of another client, `currency`, `items`
     * @throws DomainException when the document is no longer a draft
     */
    public function handle(User $actor, Invoice $draft, array $entryIds): array
    {
        Gate::forUser($actor)->authorize('update', $draft);

        $ids = array_values(array_unique(array_filter($entryIds, static fn (mixed $id): bool => is_string($id) && Str::isUuid($id))));

        return DB::transaction(function () use ($draft, $ids): array {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            if ($ids === []) {
                return ['entries' => 0, 'items' => 0];
            }

            if (TimeEntry::query()->whereIn('id', $ids)->where('client_id', $locked->client_id)->count() !== count($ids)) {
                throw ValidationException::withMessages(['entry_ids' => [__('invoices.errors.entries_foreign')]]);
            }

            $now = TimerClock::now();
            $today = $now->setTimezone(FilamentTimezone::get())->format('Y-m-d');

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

            $grouping = $this->lines->groupedPerTask($locked) ? ItemGrouping::PerTask : ItemGrouping::PerProject;

            try {
                $lines = $this->grouping->lines($rows, $grouping, $locked->currency, $locked->language->value, $today);
            } catch (ExchangeRateMissing $missing) {
                throw ValidationException::withMessages(['currency' => [__('invoices.errors.cnb_missing', ['currency' => $missing->currency, 'date' => IssueChecks::czechDate($today)])]]);
            } catch (OverflowException) {
                throw ValidationException::withMessages(['items' => [__('invoices.errors.item_price')]]);
            }

            $this->lines->appendTime($locked, $lines);
            $this->lines->refreshTotals($locked);

            return ['entries' => $claimed->count(), 'items' => count($lines)];
        });
    }
}
