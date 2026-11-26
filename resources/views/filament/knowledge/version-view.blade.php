{{--
    The read-only screen of one stored version (VER-02, D-08): the meta line, the callouts that apply (at most one of
    each), the stored title and the stored body rendered by the one renderer inside the prose view. $html is the
    HtmlString of KnowledgeMarkdown::render(); `{{ }}` echoes it without escaping, so no raw echo is needed here.
    Takes $version, $title, $authorName, $createdAt, $isCurrent, $currentNo, $currentUrl, $redacted, $archived, $html.
    Inline styles only: the panel theme does not compile utility classes of views outside the Filament sources.
--}}
<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;font-size:0.875rem;line-height:1.25rem;font-weight:600;color:var(--gray-500);">
        <span>{{ __('knowledge.versions.meta', ['n' => $version->version_no, 'author' => $authorName, 'date' => $createdAt]) }}</span>

        @if ($isCurrent)
            <x-filament::badge color="success">{{ __('knowledge.versions.badges.current') }}</x-filament::badge>
        @endif

        @if ($version->restored_from_version_no !== null)
            <x-filament::badge color="gray" icon="heroicon-o-arrow-uturn-left">{{ __('knowledge.versions.badges.restored_from', ['n' => $version->restored_from_version_no]) }}</x-filament::badge>
        @endif

        @if ($redacted)
            <x-filament::badge color="warning" icon="heroicon-o-eye-slash">{{ __('knowledge.versions.badges.redacted') }}</x-filament::badge>
        @endif
    </div>

    @if (! $isCurrent)
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            :description="__('knowledge.versions.callouts.old', ['current' => $currentNo])"
        >
            <x-slot name="footer">
                <x-filament::link :href="$currentUrl">{{ __('knowledge.versions.actions.view_current') }}</x-filament::link>
            </x-slot>
        </x-filament::callout>
    @endif

    @if ($redacted)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-eye-slash"
            :heading="__('knowledge.versions.callouts.redacted_heading')"
            :description="__('knowledge.versions.callouts.redacted_body')"
        />
    @endif

    @if ($archived)
        <x-filament::callout
            color="info"
            icon="heroicon-o-archive-box"
            :description="__('knowledge.versions.callouts.archived')"
        />
    @endif

    <x-filament::section>
        <p style="margin:0 0 1rem;font-size:1rem;line-height:1.5rem;font-weight:600;">{{ $title }}</p>

        @include('filament.knowledge.prose', [
            'html' => $html,
            'emptyText' => __('knowledge.empty.body'),
            'emptyActionUrl' => null,
            'emptyActionLabel' => null,
        ])
    </x-filament::section>
</x-filament-panels::page>
