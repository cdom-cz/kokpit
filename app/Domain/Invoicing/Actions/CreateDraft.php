<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\ItemGrouping;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Queries\InvoiceableEntries;
use App\Domain\Invoicing\Support\DocumentPayment;
use App\Domain\Invoicing\Support\DraftLines;
use App\Domain\Invoicing\Support\EntryReservation;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\TimeItemGrouping;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * Creates a draft invoice or proforma for exactly one client (IN-02, IN-08, D-01, D-11): the one checked entry point
 * of a new draft. The Admin only.
 *
 * Data keys: `client_id`, `kind` (`invoice` or `proforma`), `currency` (optional) and `source` (`empty` or `time`).
 * The currency defaults to the client's currency, the language to the client's invoice language and the VAT mode to
 * the current setting.
 *
 * The `time` source bills unbilled time (IN-02, D-02, D-04, D-07, D-09): `period_from` and `period_to` (Prague days,
 * the first not after the last), `project_ids` (projects of the client, archived ones allowed), `without_project`
 * (bool, the entries that have no project) and `grouping` (`project` or `task`, default `project`). In the one
 * transaction of the draft the candidates of {@see InvoiceableEntries} are claimed with
 * {@see EntryReservation::claim()}, which locks them in id order and reserves only the rows still free, and the lines
 * are built by {@see TimeItemGrouping} from the claimed rows only, so two drafts created at the same moment never
 * share an entry or count its seconds twice. Fixed-price time is never claimed (ruling R-13-1). A line whose rate is
 * in another currency than the draft is previewed with today's CNB rates; without a CNB list for it the draft is
 * refused with `cnb_missing` under `currency` and nothing is reserved (R-13-4). The draft has no number, and its issue and due
 * dates stay null until the Admin types them: a null issue date means "today (Europe/Prague) at issue" and a null due
 * date "the issue date plus the client's payment terms at issue" (R-11-1).
 *
 * A new invoice or proforma draft is always a `transfer` document, whatever the client flag or the global setting says;
 * the Admin chooses `online` by hand later (D-02). It stores the first configured account of its currency as its chosen
 * account, or none when no account has that currency (D-05): a transfer document without an account is a blocker of
 * issuing, not an error of the draft, and an online one needs no account.
 *
 * The Partner-readable `invoices` row and its Admin-only `invoice_admin` row are written together, while the document
 * is a draft: after issue the database accepts no new child row. The card switch of the Admin-only row starts as the
 * client's online-payment flag (D-06).
 */
final readonly class CreateDraft
{
    public function __construct(
        private InvoiceableEntries $entries,
        private EntryReservation $reservation,
        private TimeItemGrouping $grouping,
        private DraftLines $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);

        $source = $data['source'] ?? 'empty';

        if ($source !== 'empty' && $source !== 'time') {
            throw new InvalidArgumentException('A draft is created from an empty or a time source.');
        }

        $kind = $this->kind($data['kind'] ?? null);
        $time = $source === 'time' ? $this->timeRequest($data) : null;

        return DB::transaction(function () use ($data, $kind, $time): Invoice {
            $client = $this->client($data['client_id'] ?? null);
            $currency = $this->currency($data['currency'] ?? null, $client);
            $projectIds = $time === null ? [] : $this->projects($time['project_ids'], $client);

            $draft = new Invoice;
            $draft->forceFill([
                'client_id' => $client->id,
                'kind' => $kind,
                'currency' => $currency,
                'language' => $client->invoice_language,
                'vat_mode' => app(InvoicingSettings::class)->vat_mode,
                'payment_method' => DocumentPaymentMethod::Transfer,
            ])->save();

            $admin = new InvoiceAdmin;
            // D-06: the draft takes the client's online-payment value; the Admin can change it on the draft.
            // D-05: the chosen account starts as the first account of the currency, or none.
            $admin->forceFill([
                'invoice_id' => $draft->id,
                'card_payment_enabled' => $kind->carriesPayment() && $client->online_payment_enabled,
                'bank_account_id' => DocumentPayment::preselect($currency),
            ])->save();

            if ($time !== null) {
                $this->addTime($draft, $client, $time, $projectIds);
            }

            return $draft->refresh();
        });
    }

    /**
     * The checked shape of the time source, before anything is read or written.
     *
     * @param  array<string, mixed>  $data
     * @return array{from: string, to: string, project_ids: list<string>, without_project: bool, grouping: ItemGrouping}
     *
     * @throws ValidationException `period_from`, `period_to`, `project_ids`, `grouping`
     */
    private function timeRequest(array $data): array
    {
        $from = PragueDay::day($data['period_from'] ?? null);
        $to = PragueDay::day($data['period_to'] ?? null);

        $errors = [];

        if ($from === null) {
            $errors['period_from'] = [__('invoices.errors.date_invalid')];
        }

        if ($to === null) {
            $errors['period_to'] = [__('invoices.errors.date_invalid')];
        } elseif ($from !== null && $to < $from) {
            $errors['period_to'] = [__('invoices.errors.period_order')];
        }

        $grouping = $data['grouping'] ?? ItemGrouping::PerProject;
        $grouping = $grouping instanceof ItemGrouping ? $grouping : (is_string($grouping) ? ItemGrouping::tryFrom($grouping) : null);

        if ($grouping === null) {
            $errors['grouping'] = [__('invoices.errors.grouping')];
        }

        $ids = $data['project_ids'] ?? [];

        if (! is_array($ids) || array_filter($ids, static fn (mixed $id): bool => ! is_string($id) || ! Str::isUuid($id)) !== []) {
            $errors['project_ids'] = [__('invoices.errors.projects_unknown')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($grouping === null || ! is_array($ids)) {
            throw new LogicException('The checks above rule this out.');
        }

        // Both days are set when no error was collected.

        return [
            'from' => (string) $from,
            'to' => (string) $to,
            'project_ids' => array_values(array_unique(array_map(static fn (mixed $id): string => (string) $id, $ids))),
            'without_project' => (bool) ($data['without_project'] ?? false),
            'grouping' => $grouping,
        ];
    }

    /**
     * The chosen projects, every one of them a project of the client; archived projects are allowed because their
     * unbilled time is still billable.
     *
     * @param  list<string>  $ids
     * @return list<string>
     *
     * @throws ValidationException `project_ids`
     */
    private function projects(array $ids, Client $client): array
    {
        if ($ids === []) {
            return [];
        }

        $found = Project::query()->withTrashed()->where('client_id', $client->id)->whereIn('id', $ids)->pluck('id')->all();

        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['project_ids' => [__('invoices.errors.projects_unknown')]]);
        }

        return $ids;
    }

    /**
     * Claims the candidate entries for the draft and writes the lines, the sources and the totals.
     *
     * @param  array{from: string, to: string, project_ids: list<string>, without_project: bool, grouping: ItemGrouping}  $time
     * @param  list<string>  $projectIds
     *
     * @throws ValidationException `currency` when a CNB list is missing, `items` when the lines do not fit
     */
    private function addTime(Invoice $draft, Client $client, array $time, array $projectIds): void
    {
        $now = TimerClock::now();
        $today = $now->setTimezone(FilamentTimezone::get())->format('Y-m-d');

        $candidates = $this->entries->candidates($client->id, $time['from'], $time['to'], $projectIds, $time['without_project'], $now);
        $claimed = $this->reservation->claim($draft, array_column($candidates, 'id'))->keyBy('id');

        // The lines are built from the rows the claim holds, with the seconds read under the lock.
        $rows = [];

        foreach ($candidates as $candidate) {
            $entry = $claimed->get($candidate['id']);

            if ($entry !== null) {
                $rows[] = [...$candidate, 'seconds' => (int) $entry->duration_seconds];
            }
        }

        try {
            $lines = $this->grouping->lines($rows, $time['grouping'], $draft->currency, $draft->language->value, $today);
        } catch (ExchangeRateMissing $missing) {
            throw ValidationException::withMessages(['currency' => [__('invoices.errors.cnb_missing', ['currency' => $missing->currency, 'date' => IssueChecks::czechDate($today)])]]);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => [__('invoices.errors.item_price')]]);
        }

        $this->lines->appendTime($draft, $lines);
        $this->lines->refreshTotals($draft);
    }

    /**
     * @throws ValidationException `kind`
     */
    private function kind(mixed $value): InvoiceKind
    {
        $kind = $value instanceof InvoiceKind ? $value : (is_string($value) ? InvoiceKind::tryFrom($value) : null);

        if ($kind === null || $kind === InvoiceKind::CreditNote) {
            throw ValidationException::withMessages(['kind' => __('invoices.errors.kind')]);
        }

        return $kind;
    }

    /**
     * The client row, read under a share lock so an archive cannot slip in before the draft is written.
     *
     * @throws ValidationException `client_id`
     */
    private function client(mixed $id): Client
    {
        $client = is_string($id) && $id !== ''
            ? Client::query()->withTrashed()->whereKey($id)->sharedLock()->first()
            : null;

        if (! $client instanceof Client) {
            throw ValidationException::withMessages(['client_id' => __('invoices.errors.client_unknown')]);
        }

        if ($client->trashed()) {
            throw ValidationException::withMessages(['client_id' => __('invoices.errors.client_archived')]);
        }

        return $client;
    }

    /**
     * @throws ValidationException `currency`
     */
    private function currency(mixed $value, Client $client): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $client->currency;
        }

        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (! Money::isKnownCurrency($code)) {
            throw ValidationException::withMessages(['currency' => __('invoices.errors.currency')]);
        }

        return $code;
    }
}
