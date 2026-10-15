<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What the time report is asked about: a client, a project of that client, a period of Prague days and the
 * breakdown of the task rows (RE-01; D-06, UI-SPEC B1).
 *
 * The filters come from the URL and from Livewire properties, so every value is client-controlled and is
 * validated here, never trusted (T-08-32): an id must be a UUID of an existing client, a project must exist
 * AND belong to the chosen client (a project without a client is dropped), a day must be a real calendar day
 * (`PragueDay::day()`), and the period and the split must be one of their lists. Anything invalid falls back
 * to the default (all clients, all projects, this month, no split). The one fallback that is announced is a
 * custom range that ends before it starts: it becomes this month and `rangeError` is set, so the page can say
 * "Datum Od nesmí být později než Do.".
 *
 * Archived clients and projects are valid: the report covers past work. The existence checks read the
 * Admin-only client and project tables, so for anyone else (a Partner, a guest) they find nothing and the ids
 * are dropped; the page and the reader refuse those callers before that.
 */
final readonly class ReportFilters
{
    public const array PERIODS = ['this_month', 'last_month', 'custom'];

    public const array SPLITS = ['none', 'day', 'user'];

    /**
     * @param  string  $from  first Prague day `Y-m-d`; for a preset period the first day of that month
     * @param  string  $to  last Prague day `Y-m-d`, included
     * @param  bool  $rangeError  a custom range that ended before it started was replaced by this month
     */
    private function __construct(
        public ?string $clientId,
        public ?string $projectId,
        public string $period,
        public string $from,
        public string $to,
        public string $split,
        public bool $rangeError,
    ) {}

    /**
     * The filters of the URL or of a Livewire request. Never throws: an invalid value falls back to its default.
     *
     * @param  array<array-key, mixed>  $input  keys client, project, period, from, to, split
     */
    public static function fromInput(array $input, CarbonInterface $now): self
    {
        $clientId = self::existingClient($input['client'] ?? null);
        $projectId = $clientId === null ? null : self::projectOf($clientId, $input['project'] ?? null);
        $split = is_string($input['split'] ?? null) && in_array($input['split'], self::SPLITS, true) ? $input['split'] : 'none';
        $period = is_string($input['period'] ?? null) && in_array($input['period'], self::PERIODS, true) ? $input['period'] : 'this_month';
        $rangeError = false;

        if ($period === 'custom') {
            $from = PragueDay::day($input['from'] ?? null);
            $to = PragueDay::day($input['to'] ?? null);

            if ($from !== null && $to !== null && $from <= $to) {
                return new self($clientId, $projectId, 'custom', $from, $to, $split, false);
            }

            // A reversed range is announced; a missing or impossible day cannot come from the date inputs.
            $rangeError = $from !== null && $to !== null;
            $period = 'this_month';
        }

        [$from, $to] = self::month($now, $period === 'last_month' ? -1 : 0);

        return new self($clientId, $projectId, $period, $from, $to, $split, $rangeError);
    }

    /**
     * A custom period of exactly these days, for callers that already know them (an export job, a dashboard link).
     *
     * @throws InvalidArgumentException when a day is not a real calendar day, the period ends before it starts, or an id is not the UUID of an existing client or of a project of that client
     */
    public static function period(string $from, string $to, ?string $clientId = null, ?string $projectId = null): self
    {
        $start = PragueDay::day($from) ?? throw new InvalidArgumentException('Not a calendar day: '.$from);
        $end = PragueDay::day($to) ?? throw new InvalidArgumentException('Not a calendar day: '.$to);

        if ($start > $end) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        if ($clientId === null && $projectId !== null) {
            throw new InvalidArgumentException('A project needs its client.');
        }

        if ($clientId !== null && self::existingClient($clientId) === null) {
            throw new InvalidArgumentException('There is no such client.');
        }

        if ($clientId !== null && $projectId !== null && self::projectOf($clientId, $projectId) === null) {
            throw new InvalidArgumentException('There is no such project of the client.');
        }

        return new self($clientId, $projectId, 'custom', $start, $end, 'none', false);
    }

    /**
     * The filters as they are stored with an export: client, project and the days of the period.
     *
     * @return array{client: ?string, project: ?string, from: string, to: string}
     */
    public function toArray(): array
    {
        return ['client' => $this->clientId, 'project' => $this->projectId, 'from' => $this->from, 'to' => $this->to];
    }

    /**
     * The filters of a stored export, validated again as a client-controlled value would be. A stored value that is
     * no longer valid is an error and never a fallback: an export must not silently cover other data than it was
     * asked for.
     *
     * @param  array<array-key, mixed>  $stored  the result of {@see ReportFilters::toArray()}
     *
     * @throws InvalidArgumentException
     */
    public static function fromStored(array $stored): self
    {
        $client = $stored['client'] ?? null;
        $project = $stored['project'] ?? null;
        $from = $stored['from'] ?? null;
        $to = $stored['to'] ?? null;

        if (! is_string($from) || ! is_string($to) || ($client !== null && ! is_string($client)) || ($project !== null && ! is_string($project))) {
            throw new InvalidArgumentException('The stored report filters are malformed.');
        }

        return self::period($from, $to, $client, $project);
    }

    /**
     * The query string of the report page for these filters; defaults are left out.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->clientId !== null) {
            $query['client'] = $this->clientId;
        }

        if ($this->projectId !== null) {
            $query['project'] = $this->projectId;
        }

        if ($this->period !== 'this_month') {
            $query['period'] = $this->period;
        }

        if ($this->period === 'custom') {
            $query['from'] = $this->from;
            $query['to'] = $this->to;
        }

        if ($this->split !== 'none') {
            $query['split'] = $this->split;
        }

        return $query;
    }

    /**
     * The first instant of the period.
     */
    public function startUtc(): CarbonImmutable
    {
        return PragueDay::start($this->from);
    }

    /**
     * The first instant after the period: the midnight following its last day.
     */
    public function endUtcExclusive(): CarbonImmutable
    {
        return PragueDay::endExclusive($this->to);
    }

    /**
     * The first and the last day of the Prague month `$offset` months from the month of `$now`.
     *
     * @return array{string, string}
     */
    private static function month(CarbonInterface $now, int $offset): array
    {
        $first = CarbonImmutable::instance($now)->setTimezone(FilamentTimezone::get())->startOfMonth()->addMonthsNoOverflow($offset);

        return [$first->format('Y-m-d'), $first->endOfMonth()->format('Y-m-d')];
    }

    /**
     * The id when it is the UUID of an existing client, archived ones included; null for anything else.
     */
    private static function existingClient(mixed $id): ?string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Client::query()->withTrashed()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * The id when it is the UUID of an existing project of the client, archived ones included; null for anything else.
     */
    private static function projectOf(string $clientId, mixed $id): ?string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Project::query()->withTrashed()->where('client_id', $clientId)->whereKey($id)->exists() ? $id : null;
    }
}
