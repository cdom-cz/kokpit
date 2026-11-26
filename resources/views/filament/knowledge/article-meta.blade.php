{{-- The meta line under the heading of an article: version and last edit, then the badges that apply, and below it the
     row of the classification (folder, client, project, knowledge tags). Inline styles only. --}}
@php
    $folderPath = $folderPath ?? null;
    $folderUrl = $folderUrl ?? null;
    $clientName = $clientName ?? null;
    $clientUrl = $clientUrl ?? null;
    $projectLabel = $projectLabel ?? null;
    $projectUrl = $projectUrl ?? null;
    $tags = $tags ?? [];
    $hasClassification = $folderPath !== null || $clientName !== null || $projectLabel !== null || $tags !== [];
@endphp
<div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;font-size:0.875rem;line-height:1.25rem;font-weight:600;color:var(--gray-500);">
    <span>{{ __('knowledge.meta.version', ['version' => $version]) }}</span>
    <span aria-hidden="true">&middot;</span>
    <span>{{ __('knowledge.meta.edited_at', ['date' => $editedAt]) }}</span>

    @if ($sensitive)
        <x-filament::badge color="warning" icon="heroicon-o-lock-closed">{{ __('knowledge.badges.sensitive') }}</x-filament::badge>
    @endif

    @if ($archived)
        <x-filament::badge color="gray">{{ __('knowledge.badges.archived') }}</x-filament::badge>
    @endif
</div>

@if ($hasClassification)
    <div data-kokpit-classification style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;margin-top:0.5rem;font-size:0.875rem;line-height:1.25rem;color:var(--gray-500);">
        @if ($folderPath !== null)
            <x-filament::link :href="$folderUrl" icon="heroicon-o-folder" size="sm" color="gray">{{ $folderPath }}</x-filament::link>
        @endif

        @if ($clientName !== null)
            <x-filament::link :href="$clientUrl" size="sm" color="gray">{{ $clientName }}</x-filament::link>
        @endif

        @if ($projectLabel !== null)
            <x-filament::link :href="$projectUrl" size="sm" color="gray">{{ $projectLabel }}</x-filament::link>
        @endif

        @foreach ($tags as $tag)
            <x-filament::badge color="gray">{{ $tag }}</x-filament::badge>
        @endforeach
    </div>
@endif
