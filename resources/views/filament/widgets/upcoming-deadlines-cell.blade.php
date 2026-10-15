{{--
    The task cell of the table "Nejbližší termíny": reference and title as one link, one line with an ellipsis and
    the full title as tooltip, the client and the project in gray under it. The row is a Task with its project and
    client eager-loaded; every value goes through {{ }}. The due date is the shared cell
    `filament.tasks.due-date-cell` (TaskDueDate). Spacing in rem multiples of 0.25 only (UI-SPEC F-2).
--}}
@php
    use App\Filament\Resources\TaskResource;

    $task = $getRecord();
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
