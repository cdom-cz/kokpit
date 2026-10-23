<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Queries\InvoiceableEntries;
use App\Domain\Invoicing\Support\DraftLines;
use App\Domain\Invoicing\Support\EntryReservation;
use App\Domain\Invoicing\Support\InvoiceAudit;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
use App\Domain\Invoicing\Support\IssuedEditFinisher;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Bills a fixed-price project with a fixed amount, whole or in part, "Přidat pevnou částku" (IN-02, IN-13, D-05, D-07).
 * The Admin only. The name stays; the lifecycle is decided on the locked document (R-10.1-09-1): a draft follows the
 * path described below, an open issued document asks {@see IssuedDocumentRules::assertEditable()} and follows the issued
 * path, anything else keeps the `not_draft` refusal.
 *
 * Data keys: `project_id` (a fixed-price project of the draft's client, archived ones allowed), `description`
 * (optional, defaults to the name of the project, at most 500 characters), `amount` (greater than zero, in the
 * currency of the draft) and `link_time` (bool). The line is quantity one, unit `paušál` and origin `fixed_amount`
 * with the project in its source; its unit price is the typed amount and counts as a manual price, so issuing never
 * converts it (ruling R-14-3).
 *
 * With `link_time` the unbilled time of the project is taken along: every finished, billable, unbilled entry of the
 * project that no draft holds, up to now, of an effective fixed-price type (ruling R-14-1), is claimed for the draft
 * with the same locked claim as {@see AddEntriesToDraft} and becomes billed, with no rate snapshot because the price
 * is fixed, when the draft is issued. Without it the hours stay unbilled and the Admin can bill the rest later. An
 * existing line is never touched (D-06).
 *
 * The issued path (R-10.1-09-4) appends the same line and, with `link_time`, claims the fixed-price entries and bills
 * them at once (they are billed with no rate snapshot, because the price is fixed), then refreshes the totals and ends
 * with {@see IssuedEditFinisher::finish()}: the CZK total, the card link, the PDF and, after the first send, the audit
 * row. A fixed amount is a manual price, so it is never converted.
 *
 * {@see AddFixedAmount::context()} is what the modal shows before the Admin confirms: the agreed price, what issued
 * fixed-amount lines have billed so far, and how much time `link_time` would take along (ruling R-14-2).
 */
final readonly class AddFixedAmount
{
    private const string UNIT = 'paušál';

    private const int DESCRIPTION_MAX_LENGTH = 500;

    public function __construct(
        private InvoiceableEntries $entries,
        private EntryReservation $reservation,
        private DraftLines $lines,
        private EntryBilling $billing,
        private IssuedEditFinisher $finisher,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException `project_id`, `amount`, `description`, `items`, `document` when an issued document
     *                             cannot take a line
     * @throws DomainException when the document is neither a draft nor an issued document
     */
    public function handle(User $actor, Invoice $draft, array $data): InvoiceItem
    {
        Gate::forUser($actor)->authorize('update', $draft);

        return DB::transaction(function () use ($actor, $draft, $data): InvoiceItem {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isIssued()) {
                IssuedDocumentRules::assertEditable($locked);
            } elseif (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            $project = $this->project($data['project_id'] ?? null, $locked);
            $amount = $this->amount($data['amount'] ?? null, $locked->currency);
            $description = $this->description($data['description'] ?? null, $project);
            $linkTime = (bool) ($data['link_time'] ?? false);

            if ($locked->isIssued()) {
                return $this->addToIssued($actor, $locked, $project, $amount, $description, $linkTime);
            }

            if ($linkTime) {
                $this->reservation->claim($locked, $this->entries->fixedPriceProjectEntries($project->id, TimerClock::now()));
            }

            $item = $this->lines->appendFixedAmount($locked, $project->id, $description, $amount, self::UNIT);
            $this->lines->refreshTotals($locked);

            return $item;
        });
    }

    /**
     * The issued path: the line, the fixed-price time claimed and billed at once with `link_time`, the totals and the
     * finisher, all under the lock the caller holds.
     */
    private function addToIssued(User $actor, Invoice $locked, Project $project, Money $amount, string $description, bool $linkTime): InvoiceItem
    {
        $before = InvoiceAudit::snapshot($locked);
        $now = TimerClock::now();

        if ($linkTime) {
            $claimed = $this->reservation->claim($locked, $this->entries->fixedPriceProjectEntries($project->id, $now));

            // Reserving and billing are two statements, as the link guard requires.
            $this->billing->bill($claimed, $now);
        }

        $item = $this->lines->appendFixedAmount($locked, $project->id, $description, $amount, self::UNIT);
        $this->lines->refreshTotals($locked, InvoiceAudit::everSent($locked));

        $this->finisher->finish($actor, $locked, $before);

        return $item;
    }

    /**
     * What the modal shows for the project: its agreed price, the amounts already billed by issued fixed-amount
     * lines (one per currency, never summed across currencies) and the count and exact seconds of the time that
     * `link_time` would claim.
     *
     * @return array{price: ?Money, billed: list<Money>, unbilled_count: int, unbilled_seconds: int}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function context(Invoice $draft, Project $project): array
    {
        $ids = $this->entries->fixedPriceProjectEntries($project->id, TimerClock::now());

        $seconds = $ids === [] ? 0 : (int) TimeEntry::query()->whereIn('id', $ids)->sum('duration_seconds');
        $price = $project->billing?->billing_type === BillingType::FixedPrice ? $project->billing->fixed_price : null;

        return [
            'price' => $price instanceof Money ? $price : null,
            'billed' => $this->entries->fixedAmountBilled($project->id),
            'unbilled_count' => count($ids),
            'unbilled_seconds' => $seconds,
        ];
    }

    /**
     * @throws ValidationException `project_id` when it is not a project of the client or has no fixed price
     */
    private function project(mixed $id, Invoice $draft): Project
    {
        $project = is_string($id) && Str::isUuid($id)
            ? Project::query()->withTrashed()->with('billing')->where('client_id', $draft->client_id)->whereKey($id)->first()
            : null;

        if (! $project instanceof Project) {
            throw ValidationException::withMessages(['project_id' => [__('invoices.errors.projects_unknown')]]);
        }

        if ($project->billing?->billing_type !== BillingType::FixedPrice) {
            throw ValidationException::withMessages(['project_id' => [__('invoices.errors.fixed_project')]]);
        }

        return $project;
    }

    /**
     * @throws ValidationException `amount`
     */
    private function amount(mixed $value, string $currency): Money
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value) : '');

        try {
            $amount = Money::fromMajor($text, $currency);
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages(['amount' => [__('invoices.errors.fixed_amount')]]);
        }

        if ($amount->minor <= 0) {
            throw ValidationException::withMessages(['amount' => [__('invoices.errors.fixed_amount')]]);
        }

        return $amount;
    }

    /**
     * @throws ValidationException `description`
     */
    private function description(mixed $value, Project $project): string
    {
        $text = is_string($value) ? trim($value) : '';

        if ($text === '') {
            $text = $project->name;
        }

        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            throw ValidationException::withMessages(['description' => [__('invoices.errors.item_description')]]);
        }

        return $text;
    }
}
