<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\ItemGrouping;
use App\Domain\Shared\Money\Money;
use InvalidArgumentException;
use OverflowException;

/**
 * Turns the time entries a draft claimed into its lines (IN-02, D-02, D-09): one line per group, one unit price per
 * line, the hours from the exact seconds of the group.
 *
 * Pure value logic over rows, no query and no write. A row is what `InvoiceableEntries` returns: the entry id, its
 * exact seconds, its project and task and the effective hourly rate. The groups are:
 * - per project: the project and the rate; per task: the task, the project and the rate. Entries without a project
 *   form one line described `invoice_items.no_project` whatever the grouping; under per-task grouping the entries of
 *   a project without a task form the line `invoice_items.no_task` (R-13-2). Per-project lines are described by the
 *   project name, per-task lines by "<task reference> <task title>", in the language of the draft.
 * - a group splits by the effective rate (minor units and currency), so a project whose tasks carry different rates
 *   gets a line for each of them (R-13-3).
 *
 * The quantity is {@see InvoiceQuantity::fromSeconds()} of the group's seconds, the only rounding of a quantity. A line
 * in the currency of the draft costs {@see Money::times()} of its unit price and is not converted. A line whose rate is
 * in another currency is previewed with the CNB lists of today through the same single-rounding method the issue uses
 * (R-13-5, plan 10-01 R-01-1): the unit price printed is {@see Money::convertThroughCzk()} of the source rate, a
 * display figure, and the line amount is {@see Money::timesThroughCzk()} of the quantity and the source rate, rounded
 * once, so it may differ by rounding from quantity times the printed price. Such a line is flagged
 * `price_converted`, and its source flagged `needs_conversion` so the issue converts it again with the lists of the
 * issue date. A group without time (zero seconds) makes no line, because a line has a non-zero quantity.
 *
 * Lines come in a fixed order: by project name (entries without a project last), then by task reference (the entries
 * without a task of a project last), then by rate.
 */
final readonly class TimeItemGrouping
{
    private const int DESCRIPTION_MAX_LENGTH = 500;

    public function __construct(private ExchangeRateConverter $rates) {}

    /**
     * @param  iterable<array{id: string, seconds: int, project_id: ?string, project_name: ?string, task_id: ?string, task_reference: ?string, task_title: ?string, billing_type: string, rate_minor: ?int, rate_currency: ?string}>  $claimedRows
     * @param  string  $currency  the currency of the draft
     * @param  string  $language  `cs` or `en`, the language of the descriptions
     * @param  string  $today  the Prague day `Y-m-d` whose CNB lists price a converted line
     * @return list<array{description: string, quantity: InvoiceQuantity, unit: string, unit_price: Money, line_total: Money, price_converted: bool, source: array{origin: string, project_id: ?string, task_id: ?string, source_rate_minor: int, source_rate_currency: string, source_seconds: int, needs_conversion: bool}}>
     *
     * @throws ExchangeRateMissing when a converted line needs a CNB list that does not exist
     * @throws InvalidArgumentException when a row has no hourly rate
     * @throws OverflowException when a quantity or an amount does not fit
     */
    public function lines(iterable $claimedRows, ItemGrouping $grouping, string $currency, string $language, string $today): array
    {
        $groups = [];

        foreach ($claimedRows as $row) {
            if ($row['rate_minor'] === null || $row['rate_currency'] === null) {
                throw new InvalidArgumentException('A time entry without an hourly rate cannot be billed.');
            }

            $projectId = $row['project_id'];
            $taskId = $projectId !== null && $grouping === ItemGrouping::PerTask ? $row['task_id'] : null;
            $key = implode('|', [$projectId ?? '-', $taskId ?? '-', $row['rate_currency'], $row['rate_minor']]);

            $groups[$key] ??= [
                'row' => $row,
                'task_id' => $taskId,
                'seconds' => 0,
                'rate' => Money::ofMinor($row['rate_minor'], $row['rate_currency']),
            ];
            $groups[$key]['seconds'] += $row['seconds'];
        }

        uasort($groups, $this->compare(...));

        $quotes = [];
        $lines = [];

        foreach ($groups as $group) {
            if ($group['seconds'] === 0) {
                continue;
            }

            $quantity = InvoiceQuantity::fromSeconds($group['seconds']);
            $rate = $group['rate'];
            $converted = $rate->currency !== $currency;

            if ($converted) {
                $source = $this->quote($rate->currency, $today, $quotes);
                $target = $this->quote($currency, $today, $quotes);

                $unitPrice = $rate->convertThroughCzk($source['rate'], $source['unit_amount'], $currency, $target['rate'], $target['unit_amount']);
                $lineTotal = $rate->timesThroughCzk($quantity->toDecimal(), $source['rate'], $source['unit_amount'], $currency, $target['rate'], $target['unit_amount']);
            } else {
                $unitPrice = $rate;
                $lineTotal = DraftTotals::lineTotal($unitPrice, $quantity);
            }

            $lines[] = [
                'description' => $this->description($group['row'], $grouping, $language),
                'quantity' => $quantity,
                'unit' => 'h',
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'price_converted' => $converted,
                'source' => [
                    'origin' => 'time',
                    'project_id' => $group['row']['project_id'],
                    'task_id' => $group['task_id'],
                    'source_rate_minor' => $rate->minor,
                    'source_rate_currency' => $rate->currency,
                    'source_seconds' => $group['seconds'],
                    'needs_conversion' => $converted,
                ],
            ];
        }

        return $lines;
    }

    /**
     * The CNB quote of a currency on a day; CZK is its own base, rate one for one unit.
     *
     * @param  array<string, array{rate: string, unit_amount: int}>  $quotes  the quotes found so far, filled in
     * @return array{rate: string, unit_amount: int}
     *
     * @throws ExchangeRateMissing
     */
    private function quote(string $currency, string $today, array &$quotes): array
    {
        if ($currency === 'CZK') {
            return ['rate' => '1', 'unit_amount' => 1];
        }

        if (! isset($quotes[$currency])) {
            $found = $this->rates->rateFor($currency, $today);
            $quotes[$currency] = ['rate' => $found['rate'], 'unit_amount' => $found['unit_amount']];
        }

        return $quotes[$currency];
    }

    /**
     * @param  array{row: array<string, mixed>, task_id: ?string, seconds: int, rate: Money}  $left
     * @param  array{row: array<string, mixed>, task_id: ?string, seconds: int, rate: Money}  $right
     */
    private function compare(array $left, array $right): int
    {
        $leftRow = $left['row'];
        $rightRow = $right['row'];

        // Entries without a project last, then the project name, then the project id so equal names stay apart.
        $byProject = [$leftRow['project_id'] === null, $this->text($leftRow['project_name']), $this->text($leftRow['project_id'])]
            <=> [$rightRow['project_id'] === null, $this->text($rightRow['project_name']), $this->text($rightRow['project_id'])];

        if ($byProject !== 0) {
            return $byProject;
        }

        if (($left['task_id'] === null) !== ($right['task_id'] === null)) {
            return $left['task_id'] === null ? 1 : -1;
        }

        $byTask = strnatcasecmp($this->text($leftRow['task_reference']), $this->text($rightRow['task_reference']));

        if ($byTask !== 0) {
            return $byTask;
        }

        return [$this->text($left['task_id']), $left['rate']->currency, $left['rate']->minor] <=> [$this->text($right['task_id']), $right['rate']->currency, $right['rate']->minor];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function description(array $row, ItemGrouping $grouping, string $language): string
    {
        $project = $this->text($row['project_name']);

        $text = match (true) {
            $row['project_id'] === null => __('invoice_items.no_project', [], $language),
            $grouping === ItemGrouping::PerProject => $project,
            $row['task_id'] === null => __('invoice_items.no_task', ['project' => $project], $language),
            default => trim($this->text($row['task_reference']).' '.$this->text($row['task_title'])),
        };

        return mb_substr(trim($text), 0, self::DESCRIPTION_MAX_LENGTH);
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
