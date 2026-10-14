<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Shared\Money\Money;

/**
 * The running totals of one row of the report tree while the entries stream through {@see TimeReport::build()}.
 *
 * A bucket sums whole seconds and the already rounded per-entry amounts, so its figures are the same sums every
 * other row and total is made of. The root bucket (`sumsAmount` false) sums seconds and CZK only: a report over
 * several clients mixes currencies, so a client-currency amount exists on a client row and below it, never above.
 * It is a mutable accumulator and never leaves the aggregate: `TimeReport` turns it into a plain array node.
 *
 * @internal
 */
final class ReportBucket
{
    public int $worked = 0;

    public int $billable = 0;

    public int $nonBillable = 0;

    public int $billed = 0;

    public int $unbilled = 0;

    /** Seconds of billable fixed-price entries: hours without an amount. */
    public int $fixed = 0;

    /** The sum of the entry amounts in the currency of the rates, null while no entry of the row has one. */
    public ?Money $amount = null;

    /** The sum of the CZK amounts of the entries that have a CNB rate, null while none has. */
    public ?Money $czk = null;

    /** The number of priced entries without a CNB rate. */
    public int $czkMissing = 0;

    /** The first Prague day (`Y-m-d`) of an entry without a CNB rate, null while none is missing. */
    public ?string $czkMissingDay = null;

    /** The row is a project whose own billing type is fixed price (its hours may still be hourly on a task). */
    public bool $fixedPrice = false;

    /** @var array<string, self> */
    public array $children = [];

    public function __construct(
        public readonly ?string $id,
        public readonly string $label,
        public readonly ?string $reference = null,
        public readonly ?string $currency = null,
        private readonly bool $sumsAmount = true,
    ) {}

    /**
     * @param  array{amount: Money|null, czk: Money|null, czk_missing: bool}  $amounts
     */
    public function add(ReportEntry $entry, array $amounts): void
    {
        $this->worked += $entry->seconds;

        if (! $entry->billable) {
            $this->nonBillable += $entry->seconds;
        } else {
            $this->billable += $entry->seconds;

            if ($entry->billed) {
                $this->billed += $entry->seconds;
            } else {
                $this->unbilled += $entry->seconds;
            }

            if ($entry->billingType === 'fixed_price') {
                $this->fixed += $entry->seconds;
            }
        }

        if ($this->sumsAmount && $amounts['amount'] !== null) {
            $this->amount = $this->amount === null ? $amounts['amount'] : $this->amount->plus($amounts['amount']);
        }

        if ($amounts['czk'] !== null) {
            $this->czk = $this->czk === null ? $amounts['czk'] : $this->czk->plus($amounts['czk']);
        }

        if ($amounts['czk_missing']) {
            $this->czkMissing++;
            $this->czkMissingDay ??= $entry->workDate;
        }
    }

    /**
     * The child of the key, created on first use.
     */
    public function child(string $key, ?string $id, string $label, ?string $reference = null, ?string $currency = null): self
    {
        return $this->children[$key] ??= new self($id, $label, $reference, $currency);
    }
}
