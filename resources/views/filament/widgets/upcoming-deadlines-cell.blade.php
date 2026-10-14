{{--
    A cell of the table "Nejbližší termíny": the task (reference and title as one link, one line with an ellipsis and
    the full title as tooltip, the client and the project in gray under it) and the due date (the word "Dnes" in
    semibold for today, the date otherwise; an overdue task adds the danger date, the "Po termínu" badge with the
    warning triangle and the line "Po termínu o :count dní", so colour is never the only carrier). The row is a Task
    with its project and client eager-loaded; every value goes through {{ }}. Spacing in rem multiples of 0.25 only
    (UI-SPEC F-2).
--}}
@php
    use App\Filament\Resources\TaskResource;
    use App\Filament\Widgets\UpcomingDeadlines;
    use App\Providers\LocalisationServiceProvider;
    use Filament\Support\Icons\Heroicon;

    $task = $getRecord();
@endphp

@if ($kind === 'task')
    @php
        $context = collect([$task->project?->client?->name, $task->project?->name])->filter()->implode(' · ');
        $ellipsis = 'display: block; max-width: 20rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;';
    @endphp

    <div style="display: grid; gap: 0.25rem; padding: 0.5rem 1rem;">
        <a
            href="{{ TaskResource::getUrl('view', ['record' => $task]) }}"
            title="{{ $task->title }}"
            style="{{ $ellipsis }} font-weight: 600; color: inherit;"
        >{{ $task->reference }} · {{ $task->title }}</a>

        @if ($context !== '')
            <span style="{{ $ellipsis }} font-size: 0.875rem; color: var(--gray-500);" title="{{ $context }}">{{ $context }}</span>
        @endif
    </div>
@else
    @php
        $due = $task->due_date->format('Y-m-d');
        $days = UpcomingDeadlines::overdueDays($due, $today);
    @endphp

    <div style="display: grid; justify-items: start; gap: 0.25rem; padding: 0.5rem 1rem; white-space: nowrap; font-variant-numeric: tabular-nums;">
        @if ($due === $today)
            <span style="font-weight: 600;">{{ __('kokpit.dashboard.deadlines.today') }}</span>
        @else
            <span style="{{ $days > 0 ? 'color: var(--danger-600);' : '' }}">{{ $task->due_date->format(LocalisationServiceProvider::DATE_FORMAT) }}</span>
        @endif

        @if ($days > 0)
            <x-filament::badge color="danger" size="sm" :icon="Heroicon::OutlinedExclamationTriangle">
                {{ __('kokpit.dashboard.deadlines.overdue_badge') }}
            </x-filament::badge>

            <span style="font-size: 0.875rem; color: var(--danger-600);">{{ trans_choice('kokpit.dashboard.deadlines.overdue_days', $days, ['count' => $days]) }}</span>
        @endif
    </div>
@endif
