@php
    $empty = __('kokpit.tasks.empty_value');
    $rows = [
        'status' => null,
        'priority' => null,
        'start_date' => $start_date,
        'due_date' => $due_date,
        'assignee' => $assignee,
        'requester' => $requester,
        'parent' => $parent_reference,
    ];
@endphp

<div style="display: grid; gap: 1rem;">
    @if ($escalated)
        <div>
            <x-filament::badge color="danger">{{ $escalated_by === null ? __('kokpit.task_board.card.escalated') : __('kokpit.task_board.card.escalated').' · '.$escalated_by }}</x-filament::badge>
        </div>
    @endif

    <dl style="display: grid; grid-template-columns: max-content 1fr; gap: 0.5rem 1rem; margin: 0;">
        @foreach ($rows as $field => $value)
            <dt style="font-weight: 600;">{{ __('kokpit.tasks.fields.'.$field) }}</dt>
            <dd style="margin: 0; overflow-wrap: anywhere;">
                @if ($field === 'status')
                    <x-filament::badge :color="$status->getColor()">{{ $status->getLabel() }}</x-filament::badge>
                @elseif ($field === 'priority')
                    <x-filament::badge :color="$priority->getColor()">{{ $priority->getLabel() }}</x-filament::badge>
                @else
                    {{ $value ?? $empty }}
                @endif
            </dd>
        @endforeach
    </dl>

    <div>
        <div style="font-weight: 600; margin-bottom: 0.5rem;">{{ __('kokpit.tasks.fields.description') }}</div>

        {{-- A rich text table can be wider than the panel, so the description scrolls sideways. --}}
        <div style="overflow-x: auto; overflow-wrap: anywhere;">
            @if (blank($description))
                {{ $empty }}
            @else
                {{ \App\Domain\Shared\Text\RichText::render($description) }}
            @endif
        </div>
    </div>
</div>
