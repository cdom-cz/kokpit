{{-- The meta line under the heading of an article: version and last edit, then the badges that apply. Inline styles only. --}}
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
