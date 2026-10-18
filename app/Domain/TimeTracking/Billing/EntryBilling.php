<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Billing;

use App\Domain\Projects\Enums\BillingType;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * The single writer of the billing and link columns of time entries: `billing_state`, `billed_at`, `invoice_id`
 * and the snapshot `billed_rate_minor`, `billed_rate_currency`, `billed_amount_minor` (TI-05, TI-08, IN-02,
 * IN-10). The manual billing actions of Phase 6 and every invoicing Action call it, so the snapshot rule and the
 * states of an entry live in one place (ruling R-09-2 replaces the Phase 6 rule that invoicing bills through
 * MarkEntriesBilled).
 *
 * States of an entry (see the link migration): free (no link, unbilled), reserved (link, unbilled, held by a
 * draft), billed by a document (link, billed), billed by hand (no link, billed).
 * - bill(): free -> billed by hand, or reserved -> billed by its issued document (the link stays);
 * - unbill(): billed by hand -> free;
 * - reserve() / unreserve(): free <-> reserved;
 * - release(): billed or reserved -> free, link and snapshot cleared (a draft dropped, or a full storno, D-14);
 * - repoint(): billed by a proforma -> billed by the invoice created from it (D-15), snapshot and billed_at kept.
 *
 * Every method takes rows the caller has already locked FOR UPDATE in id order, and saves each row as a model, so
 * each change writes exactly one allowlisted history row (a bulk update would raise no model event). A row in the
 * wrong state is a programming error and raises a LogicException; the database link guard is the backstop for
 * every writer, this class never relies on being the only one.
 *
 * The snapshot (research Open Question 9, ruling R-09-1) is taken by every billing, manual or by an invoice,
 * through the one method {@see EntryBilling::snapshot()}: the effective hourly rate at that moment and the amount
 * of the exact seconds at that rate, rounded once. It is not the invoice line amount (lines round per line). A
 * fixed-price, non-billable or rate-less entry snapshots nothing. Unbilling and releasing clear it.
 *
 * Admin and system contexts only, because it reads rates (T-06-13).
 */
final class EntryBilling
{
    public function __construct(
        private readonly PartnerContext $context,
        private readonly TimeEntryRateResolver $rates,
        private readonly TaskBillingResolver $tasks,
    ) {}

    /**
     * Bills the rows at the instant, snapshotting the rate and the amount of each.
     *
     * @param  iterable<TimeEntry>  $rows  unbilled rows, locked FOR UPDATE in id order
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when a row is not unbilled
     */
    public function bill(iterable $rows, CarbonImmutable $at): void
    {
        $this->assertAllowed();

        foreach ($rows as $row) {
            if ($row->billing_state !== BillingState::Unbilled) {
                throw new LogicException('Only an unbilled time entry can be billed.');
            }

            $row->forceFill([
                'billing_state' => BillingState::Billed,
                'billed_at' => $at,
                ...$this->snapshot($row),
            ])->save();
        }
    }

    /**
     * Returns rows billed by hand to unbilled and clears their snapshot.
     *
     * @param  iterable<TimeEntry>  $rows  billed rows without a link, locked FOR UPDATE in id order
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when a row is not billed by hand
     */
    public function unbill(iterable $rows): void
    {
        $this->assertAllowed();

        foreach ($rows as $row) {
            if ($row->billing_state !== BillingState::Billed || $row->invoice_id !== null) {
                throw new LogicException('Only a time entry billed by hand can be unbilled.');
            }

            $row->forceFill($this->freed())->save();
        }
    }

    /**
     * Reserves free rows for a draft document.
     *
     * @param  iterable<TimeEntry>  $rows  unbilled rows without a link, locked FOR UPDATE in id order
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when a row is billed or already linked
     */
    public function reserve(iterable $rows, string $invoiceId): void
    {
        $this->assertAllowed();

        foreach ($rows as $row) {
            if ($row->billing_state !== BillingState::Unbilled || $row->invoice_id !== null) {
                throw new LogicException('Only a free time entry can be reserved.');
            }

            $row->forceFill(['invoice_id' => $invoiceId])->save();
        }
    }

    /**
     * Frees rows reserved by a draft document.
     *
     * @param  iterable<TimeEntry>  $rows  reserved rows, locked FOR UPDATE in id order
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when a row is not reserved
     */
    public function unreserve(iterable $rows): void
    {
        $this->assertAllowed();

        foreach ($rows as $row) {
            if ($row->billing_state !== BillingState::Unbilled || $row->invoice_id === null) {
                throw new LogicException('Only a reserved time entry can be unreserved.');
            }

            $row->forceFill(['invoice_id' => null])->save();
        }
    }

    /**
     * Returns rows billed or reserved by a document to unbilled with no link and no snapshot: the time of a
     * cancelled invoice (D-14) or of a dropped draft. The database refuses a release the document does not allow.
     *
     * @param  iterable<TimeEntry>  $rows  rows with a link, locked FOR UPDATE in id order
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when a row has no link
     */
    public function release(iterable $rows): void
    {
        $this->assertAllowed();

        foreach ($rows as $row) {
            if ($row->invoice_id === null) {
                throw new LogicException('Only a time entry linked to a document can be released.');
            }

            $row->forceFill($this->freed())->save();
        }
    }

    /**
     * Moves billed rows from a proforma to the invoice created from it; the instant and the snapshot stay (D-15).
     *
     * @param  iterable<TimeEntry>  $rows  billed rows with a link, locked FOR UPDATE in id order
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when a row is not billed by a document
     */
    public function repoint(iterable $rows, string $invoiceId): void
    {
        $this->assertAllowed();

        foreach ($rows as $row) {
            if ($row->billing_state !== BillingState::Billed || $row->invoice_id === null) {
                throw new LogicException('Only a time entry billed by a document can be repointed.');
            }

            $row->forceFill(['invoice_id' => $invoiceId])->save();
        }
    }

    /**
     * The rate and the amount the entry is worth right now: the effective hourly rate and the exact seconds at
     * that rate, rounded once. All three are null for a fixed-price, non-billable or rate-less entry, and for an
     * entry that is still running.
     *
     * @return array{billed_rate_minor: ?int, billed_rate_currency: ?string, billed_amount_minor: ?int}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function snapshot(TimeEntry $row): array
    {
        $this->assertAllowed();

        $none = ['billed_rate_minor' => null, 'billed_rate_currency' => null, 'billed_amount_minor' => null];

        if ($row->duration_seconds === null || ! $this->isHourly($row)) {
            return $none;
        }

        $rate = $this->rates->resolve($row)->rate;

        if ($rate === null) {
            return $none;
        }

        return [
            'billed_rate_minor' => $rate->minor,
            'billed_rate_currency' => $rate->currency,
            'billed_amount_minor' => Money::forDuration($rate, (int) $row->duration_seconds)->minor,
        ];
    }

    /**
     * Whether the entry is paid by the hour: the billing type of its task, else of its project, else hourly.
     */
    private function isHourly(TimeEntry $row): bool
    {
        $row->loadMissing(['project.billing', 'task']);

        if ($row->task !== null) {
            return $this->tasks->resolve($row->task)->type === 'hourly';
        }

        $type = $row->project?->billing?->billing_type;

        return $type === null || $type === BillingType::Hourly;
    }

    /**
     * The columns of a free entry: unbilled, no instant, no link, no snapshot.
     *
     * @return array<string, mixed>
     */
    private function freed(): array
    {
        return [
            'billing_state' => BillingState::Unbilled,
            'billed_at' => null,
            'invoice_id' => null,
            'billed_rate_minor' => null,
            'billed_rate_currency' => null,
            'billed_amount_minor' => null,
        ];
    }

    private function assertAllowed(): void
    {
        if (! $this->context->isAdmin() && ! $this->context->isSystem()) {
            throw new AuthorizationException('The billing columns of time entries are written by the Admin or a system run only.');
        }
    }
}
