{{-- A bar per day: the height is the completion ratio; a day without anything shows only the empty track. --}}
<div style="display: flex; align-items: flex-end; gap: 2px; height: 5rem;" role="img" aria-label="{{ $label }}">
    @foreach ($data as $point)
        <div
            title="{{ \App\Domain\Signal\Support\SignalCalendar::format($point['date']) }}: {{ $point['done'] }} / {{ $point['total'] }}"
            style="flex: 1 1 0; min-width: 2px; height: 100%; display: flex; align-items: flex-end; background: rgba(127, 127, 127, 0.12); border-radius: 2px;"
        >
            <div style="width: 100%; height: {{ round($point['ratio'] * 100) }}%; background: var(--primary-600); border-radius: 2px;"></div>
        </div>
    @endforeach
</div>
