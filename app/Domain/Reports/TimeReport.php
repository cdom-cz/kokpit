<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\RateBook;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The time report of a period: the summary over the whole filtered set and the tree client > project > task
 * (RE-01, RE-02; D-01, D-06, D-07).
 *
 * Everything is formed in one pass over the entries of {@see TimeReportReader}: each entry is priced once by
 * {@see EntryAmounts}, and that rounded amount is added to the summary and to its client, project and task row.
 * Every figure of the page and of the exports is therefore the same sum of the same numbers, whatever rows are
 * shown. The CNB lookup is loaded once for the currencies the entries are priced in, so the number of queries
 * does not depend on the number of entries.
 *
 * Currencies are never added together: a client has one currency, the summary and the footer list one amount per
 * currency, and only the CZK figure sums across clients. An entry priced in a currency without a CNB list leaves
 * the CZK sum and is counted in `czk_missing`; the CZK figure is then a lower bound, never a silent zero.
 *
 * Worked time is billed + unbilled + non-billable (a billed entry is always billable), the same four sums as
 * `TimeTotals`. Fixed-price hours are part of the billable hours and carry no amount (D-07).
 *
 * @phpstan-type Seconds array{worked: int, billable: int, non_billable: int, billed: int, unbilled: int, fixed: int}
 * @phpstan-type Node array{id: ?string, label: string, reference: ?string, currency: ?string, seconds: Seconds, amount: ?Money, czk: ?Money, czk_missing: int, czk_missing_day: ?string, fixed: bool, fixed_price: bool, children: list<array<string, mixed>>}
 * @phpstan-type Summary array{worked: int, billable: int, non_billable: int, billed: int, unbilled: int, fixed: int, amounts: array{billed: array<string, Money>, unbilled: array<string, Money>, billable: array<string, Money>}, czk: array{billed: ?Money, unbilled: ?Money, billable: ?Money}, czk_missing: int, czk_missing_by_state: array{billed: int, unbilled: int}}
 * @phpstan-type Footer array{seconds: Seconds, czk: ?Money, amounts: array<string, Money>, czk_missing: int}
 */
final readonly class TimeReport
{
    /**
     * @param  Summary  $summary
     * @param  list<Node>  $tree  clients, each with its projects, each with its tasks (and their days or users); cut at the leaf cap
     * @param  Footer  $footer
     * @param  bool  $capped  more leaf rows exist than `kokpit.report.max_rendered_rows`, so the tree is cut and the totals are not
     * @param  int  $renderedLeafCount  the leaf rows (task rows, or the day or user rows below them) left in the tree
     * @param  int  $leafCap  the cap the tree was cut at, never below 1
     */
    private function __construct(
        public array $summary,
        public array $tree,
        public array $footer,
        public int $entryCount,
        public bool $capped,
        public int $renderedLeafCount,
        public int $leafCap,
    ) {}

    /**
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public static function build(ReportFilters $filters, CarbonInterface $now): self
    {
        $reader = app(TimeReportReader::class);

        $currencies = array_values(array_filter(
            $reader->rateCurrencies($filters),
            static fn (string $currency): bool => $currency !== 'CZK',
        ));
        $rates = RateBook::load($currencies, $filters->from, $filters->to);

        // The root row spans clients of different currencies, so it sums hours and CZK but never a client-currency amount.
        $total = new ReportBucket(null, '', sumsAmount: false);
        $amounts = ['billed' => [], 'unbilled' => [], 'billable' => []];
        $czk = ['billed' => null, 'unbilled' => null, 'billable' => null];
        $missingByState = ['billed' => 0, 'unbilled' => 0];
        $count = 0;

        foreach ($reader->entries($filters, $now) as $entry) {
            $count++;
            $priced = EntryAmounts::of($entry, $rates);

            $total->add($entry, $priced);
            $state = $entry->billed ? 'billed' : 'unbilled';

            if ($priced['czk_missing']) {
                $missingByState[$state]++;
            }

            if ($priced['amount'] !== null) {
                foreach ([$state, 'billable'] as $bucket) {
                    $currency = $priced['amount']->currency;
                    $amounts[$bucket][$currency] = isset($amounts[$bucket][$currency])
                        ? $amounts[$bucket][$currency]->plus($priced['amount'])
                        : $priced['amount'];

                    if ($priced['czk'] !== null) {
                        $czk[$bucket] = $czk[$bucket] === null ? $priced['czk'] : $czk[$bucket]->plus($priced['czk']);
                    }
                }
            }

            $client = $total->child($entry->clientId, $entry->clientId, $entry->clientName, null, $entry->clientCurrency);
            $project = $client->child(
                $entry->projectId ?? 'none',
                $entry->projectId,
                $entry->projectName ?? (string) __('kokpit.report.tree.no_project'),
            );
            $project->fixedPrice = $entry->projectBillingType === 'fixed_price';
            $task = $project->child(
                $entry->taskId ?? 'none',
                $entry->taskId,
                $entry->taskId === null ? (string) __('kokpit.report.tree.no_task') : $entry->taskReference.' · '.$entry->taskTitle,
                $entry->taskReference,
            );

            $client->add($entry, $priced);
            $project->add($entry, $priced);
            $task->add($entry, $priced);

            // The "Rozpad" rows below the task: its Prague days or its users, from the same priced entry.
            if ($filters->split === 'day') {
                $task->child($entry->workDate, null, $entry->workDate)->add($entry, $priced);
            } elseif ($filters->split === 'user') {
                $task->child($entry->userId, $entry->userId, $entry->userName)->add($entry, $priced);
            }
        }

        ksort($amounts['billed']);
        ksort($amounts['unbilled']);
        ksort($amounts['billable']);

        $summary = [
            'worked' => $total->worked,
            'billable' => $total->billable,
            'non_billable' => $total->nonBillable,
            'billed' => $total->billed,
            'unbilled' => $total->unbilled,
            'fixed' => $total->fixed,
            'amounts' => $amounts,
            'czk' => $czk,
            'czk_missing' => $total->czkMissing,
            'czk_missing_by_state' => $missingByState,
        ];

        $footerAmounts = [];

        foreach ($total->children as $client) {
            if ($client->amount !== null && $client->currency !== null) {
                $footerAmounts[$client->amount->currency] = isset($footerAmounts[$client->amount->currency])
                    ? $footerAmounts[$client->amount->currency]->plus($client->amount)
                    : $client->amount;
            }
        }

        ksort($footerAmounts);

        $cap = max(1, (int) config('kokpit.report.max_rendered_rows'));
        [$tree, $leaves, $rendered] = self::tree($total, $filters->split, $cap);

        return new self(
            $summary,
            $tree,
            [
                'seconds' => self::seconds($total),
                'czk' => $total->czk,
                'amounts' => $footerAmounts,
                'czk_missing' => $total->czkMissing,
            ],
            $count,
            $leaves > $cap,
            $rendered,
            $cap,
        );
    }

    /**
     * The clients of the root bucket as nodes: clients and projects by name in Czech order (entries without a
     * project last), tasks by their number (entries without a task last), and below each task, with a "Rozpad",
     * its days in date order or its users in Czech order.
     *
     * Only the first `$cap` leaf rows are kept, in the order they are listed; a row above them whose leaves were all
     * cut is dropped, and a task row keeps its own totals when only some of its day or user rows are cut.
     *
     * @return array{list<Node>, int, int} the nodes, the number of leaf rows there were, the number kept
     */
    private static function tree(ReportBucket $root, string $split, int $cap): array
    {
        $clientRank = self::ranks(Client::query()->withTrashed(), array_keys($root->children));
        $clients = $root->children;
        uasort($clients, static fn (ReportBucket $a, ReportBucket $b): int => ($clientRank[$a->id ?? ''] ?? PHP_INT_MAX) <=> ($clientRank[$b->id ?? ''] ?? PHP_INT_MAX));

        $projectIds = [];
        $userIds = [];

        foreach ($clients as $client) {
            foreach ($client->children as $project) {
                if ($project->id !== null) {
                    $projectIds[] = $project->id;
                }

                foreach ($project->children as $task) {
                    foreach ($task->children as $child) {
                        if ($split === 'user' && $child->id !== null) {
                            $userIds[$child->id] = $child->id;
                        }
                    }
                }
            }
        }

        $projectRank = self::ranks(Project::query()->withTrashed(), $projectIds);
        $userRank = self::ranks(User::query(), array_values($userIds));
        $nodes = [];
        $leaves = 0;
        $rendered = 0;

        foreach ($clients as $client) {
            $projects = $client->children;
            uasort($projects, static fn (ReportBucket $a, ReportBucket $b): int => self::rankOf($projectRank, $a) <=> self::rankOf($projectRank, $b));

            $projectNodes = [];

            foreach ($projects as $project) {
                $tasks = $project->children;
                uasort($tasks, static fn (ReportBucket $a, ReportBucket $b): int => self::taskNumber($a) <=> self::taskNumber($b));

                $taskNodes = [];

                foreach ($tasks as $task) {
                    $leafBuckets = $task->children;

                    if ($split === 'day') {
                        ksort($leafBuckets);
                    } else {
                        uasort($leafBuckets, static fn (ReportBucket $a, ReportBucket $b): int => self::rankOf($userRank, $a) <=> self::rankOf($userRank, $b));
                    }

                    $room = $cap - $rendered;

                    if ($split === 'none') {
                        $leaves++;

                        if ($room > 0) {
                            $rendered++;
                            $taskNodes[] = self::node($task, []);
                        }

                        continue;
                    }

                    $leaves += count($leafBuckets);

                    if ($room > 0) {
                        $kept = array_slice(array_values($leafBuckets), 0, $room);
                        $rendered += count($kept);
                        $taskNodes[] = self::node($task, array_map(static fn (ReportBucket $leaf): array => self::node($leaf, []), $kept));
                    }
                }

                if ($taskNodes !== []) {
                    $projectNodes[] = self::node($project, $taskNodes);
                }
            }

            if ($projectNodes !== []) {
                $nodes[] = self::node($client, $projectNodes);
            }
        }

        return [$nodes, $leaves, $rendered];
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return Node
     */
    private static function node(ReportBucket $bucket, array $children): array
    {
        return [
            'id' => $bucket->id,
            'label' => $bucket->label,
            'reference' => $bucket->reference,
            'currency' => $bucket->currency,
            'seconds' => self::seconds($bucket),
            'amount' => $bucket->amount,
            'czk' => $bucket->czk,
            'czk_missing' => $bucket->czkMissing,
            'czk_missing_day' => $bucket->czkMissingDay,
            'fixed' => $bucket->fixed > 0,
            'fixed_price' => $bucket->fixedPrice,
            'children' => $children,
        ];
    }

    /**
     * @return Seconds
     */
    private static function seconds(ReportBucket $bucket): array
    {
        return [
            'worked' => $bucket->worked,
            'billable' => $bucket->billable,
            'non_billable' => $bucket->nonBillable,
            'billed' => $bucket->billed,
            'unbilled' => $bucket->unbilled,
            'fixed' => $bucket->fixed,
        ];
    }

    /**
     * The position of every id in the Czech alphabetical order of the names, from the database collation
     * (the one mechanism for text sorted for people).
     *
     * @template TModel of Client|Project|User
     *
     * @param  Builder<TModel>  $query
     * @param  list<int|string>  $ids
     * @return array<string, int>
     */
    private static function ranks(Builder $query, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $query->whereIn('id', $ids);
        CzechCollation::orderBy($query, 'name');

        $ranks = [];

        foreach ($query->orderBy('id')->toBase()->pluck('id') as $position => $id) {
            $ranks[(string) $id] = (int) $position;
        }

        return $ranks;
    }

    /**
     * A named row by its rank; the row without an entity (no project) after every named one.
     *
     * @param  array<string, int>  $ranks
     */
    private static function rankOf(array $ranks, ReportBucket $bucket): int
    {
        return $bucket->id === null ? PHP_INT_MAX : ($ranks[$bucket->id] ?? PHP_INT_MAX - 1);
    }

    /**
     * The number of a task row, the digits after the last dash of its reference; no task last.
     */
    private static function taskNumber(ReportBucket $bucket): int
    {
        if ($bucket->reference === null) {
            return PHP_INT_MAX;
        }

        $dash = strrpos($bucket->reference, '-');

        return $dash === false ? PHP_INT_MAX - 1 : (int) substr($bucket->reference, $dash + 1);
    }
}
