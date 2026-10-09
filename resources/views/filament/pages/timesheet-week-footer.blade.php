{{-- The footer row of the week grid: "Celkem", one total per day, the total of the week. Rendered inside the table's <tr>, so it holds only cells aligned with the grid columns. --}}
<td class="fi-ta-cell" colspan="3" style="padding: 0.5rem 1rem; font-weight: 600;">
    {{ __('kokpit.time.timesheet.total') }}
</td>

@foreach ($days as $day)
    <td class="fi-ta-cell" style="padding: 0.5rem 1rem; text-align: end; font-weight: 600; white-space: nowrap;">
        <span style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 0.25rem;">
            @if ($dayOverlaps[$day] ?? false)
                {{-- The sum of a day with overlapping entries may be higher than the real time (D-04). --}}
                <span
                    x-data
                    x-tooltip="{ content: @js(__('kokpit.time.timesheet.overlap_day')), theme: $store.theme }"
                    class="fi-color fi-color-warning"
                    style="display: inline-flex; color: var(--color-600);"
                >
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle" style="width: 1.25rem; height: 1.25rem;" />
                    <span class="fi-sr-only">{{ __('kokpit.time.timesheet.overlap_day') }}</span>
                </span>
            @endif

            {{ array_key_exists($day, $dayTotals) ? \App\Domain\TimeTracking\Support\DurationFormat::hoursMinutes($dayTotals[$day]) : __('kokpit.time.empty_value') }}
        </span>
    </td>
@endforeach

<td class="fi-ta-cell" style="padding: 0.5rem 1rem; text-align: end; font-weight: 600; white-space: nowrap;">
    {{ \App\Domain\TimeTracking\Support\DurationFormat::hoursMinutes($total) }}
</td>
