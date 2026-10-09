<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Queries;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TimeEntryResource;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The finished entries of one user grouped by Prague day, newest day first, for the side panel
 * "Poslední záznamy" (D-08, UI-SPEC Surface B).
 *
 * A page is the next `$days` days that CONTAIN finished entries (a quiet day is skipped, it is not
 * a page slot). An entry belongs to the Prague day of its start (research A5), so a stretch from
 * 23:30 to 00:30 is listed under the day it started. Running entries are not listed, and the day
 * totals count finished entries only (U-6).
 *
 * Two queries find the days and their entries, however many entries there are: one for the Prague
 * dates (limit `$days + 1`, the extra date only answers `has_more`), one for the entries of those
 * dates. The second filters through UTC range predicates computed from the Prague day boundaries,
 * so the (user_id, started_at) index is used and a 23 or 25 hour day is right (research Pattern 9).
 * The client, project and task are eager-loaded with their archived rows (the relations include
 * them). Rows are arrays of scalars, ready for the view and the Livewire snapshot, without any
 * rate or price.
 */
final class RecentEntries
{
    private const string ZONE = 'Europe/Prague';

    private const int DESCRIPTION_LENGTH = 80;

    /**
     * @param  string|null  $before  a Prague date `Y-m-d`; only days strictly before it are read
     * @return array{days: list<array{date: string, label: string, total_seconds: int, entries: list<array<string, scalar|null>>}>, has_more: bool}
     */
    public function days(User $user, ?string $before, int $days = 7): array
    {
        $days = max(1, $days);

        $dates = TimeEntry::query()
            ->where('time_entries.user_id', $user->getKey())
            ->whereNotNull('time_entries.ended_at')
            ->when($before, fn ($query, string $cursor) => $query->where('time_entries.started_at', '<', $this->dayStart($cursor)))
            ->selectRaw("(time_entries.started_at AT TIME ZONE 'Europe/Prague')::date AS day")
            ->distinct()
            ->orderByDesc('day')
            ->limit($days + 1)
            ->pluck('day')
            ->map(static fn (mixed $day): string => substr((string) $day, 0, 10))
            ->all();

        $hasMore = count($dates) > $days;
        $dates = array_slice($dates, 0, $days);

        if ($dates === []) {
            return ['days' => [], 'has_more' => false];
        }

        $entries = TimeEntry::query()
            ->with(['client', 'project', 'task'])
            ->where('time_entries.user_id', $user->getKey())
            ->whereNotNull('time_entries.ended_at')
            ->where('time_entries.started_at', '>=', $this->dayStart($dates[count($dates) - 1]))
            ->where('time_entries.started_at', '<', $this->dayStart($dates[0], 1))
            ->orderByDesc('time_entries.started_at')
            ->orderByDesc('time_entries.id')
            ->get();

        $byDay = [];

        foreach ($entries as $entry) {
            $byDay[$entry->started_at->setTimezone(self::ZONE)->format('Y-m-d')][] = $entry;
        }

        $result = [];

        foreach ($dates as $date) {
            $rows = $byDay[$date] ?? [];

            $result[] = [
                'date' => $date,
                'label' => $this->label($date),
                'total_seconds' => (int) array_sum(array_map(static fn (TimeEntry $entry): int => (int) $entry->duration_seconds, $rows)),
                'entries' => array_map($this->row(...), $rows),
            ];
        }

        return ['days' => $result, 'has_more' => $hasMore];
    }

    /**
     * The start of a Prague day as a UTC instant, `$plusDays` later when asked; the next day is
     * taken in Prague time, so a 23 or 25 hour day is never counted as 24 hours.
     */
    private function dayStart(string $date, int $plusDays = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::ZONE)->addDays($plusDays)->startOfDay()->utc();
    }

    /**
     * "Dnes", or the Czech weekday and date; the year only outside the current year.
     */
    private function label(string $date): string
    {
        $day = CarbonImmutable::parse($date, self::ZONE);
        $today = TimerClock::now()->setTimezone(self::ZONE);

        if ($day->isSameDay($today)) {
            return (string) __('kokpit.time.panel.today');
        }

        $weekdays = __('kokpit.time.panel.weekdays');
        $name = is_array($weekdays) ? (string) ($weekdays[$day->dayOfWeekIso] ?? '') : '';

        return $name.' '.$day->format($day->year === $today->year ? 'j. n.' : LocalisationServiceProvider::DATE_FORMAT);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function row(TimeEntry $entry): array
    {
        $task = $entry->task;
        $client = (string) $entry->client?->name;

        if ($task !== null) {
            $title = $task->reference.' · '.$task->title;
            $second = $client;
        } else {
            $title = $client;
            $description = trim((string) $entry->description);
            $second = $entry->project !== null
                ? $entry->project->name
                : ($description === '' ? '' : Str::limit($description, self::DESCRIPTION_LENGTH));
        }

        return [
            'id' => $entry->id,
            'view_url' => TimeEntryResource::getUrl('view', ['record' => $entry]),
            'title' => $title,
            'task_url' => $task === null ? null : TaskResource::getUrl('view', ['record' => $task]),
            'second' => $second,
            'duration_seconds' => (int) $entry->duration_seconds,
        ];
    }
}
