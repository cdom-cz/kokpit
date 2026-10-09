{{--
    The footer of the tab "Úkoly a čas": the line "Bez úkolu" (time logged to the project without a task) and the line
    "Celkem" (every task plus "Bez úkolu"). Rendered inside the table's <tfoot><tr>, so it holds only cells; the second
    line opens its own row. The first three columns (Číslo, Název, Stav) are one label cell, the others follow the
    visible columns, so the hidden "Nefakturovatelné" column leaves no gap.
--}}
@php
    $figureColumns = collect($columns)
        ->map(fn ($column): string => $column->getName())
        ->reject(fn (string $name): bool => in_array($name, ['reference', 'title', 'status'], true))
        ->values();
    $lines = [
        ['label' => __('kokpit.time.project.no_task'), 'figures' => $withoutTask, 'weight' => 400],
        ['label' => __('kokpit.time.project.total'), 'figures' => $total, 'weight' => 600],
    ];
@endphp

@foreach ($lines as $line)
    @if (! $loop->first)
        </tr><tr>
    @endif

    <td class="fi-ta-cell" colspan="3" style="padding: 0.5rem 1rem; font-weight: {{ $line['weight'] }};">
        {{ $line['label'] }}
    </td>

    @foreach ($figureColumns as $name)
        <td class="fi-ta-cell" style="padding: 0.5rem 1rem; text-align: end; white-space: nowrap; font-weight: {{ $line['weight'] }};">
            {{ $line['figures'][$name] ?? '' }}
        </td>
    @endforeach
@endforeach
