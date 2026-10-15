{{--
    The due-date cell shared by the table "Nejbližší termíny" and the Admin task list: the word "Dnes" in semibold for
    today, the date otherwise, the empty value for a task without a due date; an overdue open task adds the danger
    date, the "Po termínu" badge with the warning triangle and the line "Po termínu o :count dní", so colour is never
    the only carrier. The row is a Task; the caller passes `today` (the Prague day) and `padding`. Every value goes
    through {{ }}. Spacing in rem multiples of 0.25 only (UI-SPEC F-2).
--}}
@php
    use App\Filament\Support\TaskDueDate;
    use App\Providers\LocalisationServiceProvider;
    use Filament\Support\Icons\Heroicon;

    $task = $getRecord();
    $due = $task->due_date?->format('Y-m-d');
    $days = TaskDueDate::overdueDaysOf($task, $today);
@endphp

@if ($due === null)
    <div style="display: grid; justify-items: start; gap: 0.25rem; padding: {{ $padding }}; white-space: nowrap;">
        <p class="fi-ta-placeholder">{{ __('kokpit.tasks.empty_value') }}</p>
    </div>
@else
    <div style="display: grid; justify-items: start; gap: 0.25rem; padding: {{ $padding }}; white-space: nowrap; font-variant-numeric: tabular-nums;">
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
