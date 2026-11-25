{{--
    The one wrapper of a rendered knowledge body (D-06): the view screen shows it now, the preview tab of the editor
    shows it later. $html is the HtmlString returned by KnowledgeMarkdown::render(); `{{ }}` echoes an HtmlString
    unescaped, so no raw echo is needed or allowed here. An empty body shows the muted text instead, with an optional
    link (the edit screen link of the view page). Inline styles only: the panel theme does not compile utility
    classes of views outside the Filament sources.
--}}
@php
    $hasBody = trim($html->toHtml()) !== '';
@endphp

@if ($hasBody)
    <div class="kokpit-prose">{{ $html }}</div>
@else
    <p style="margin:0;color:var(--gray-500);">
        {{ $emptyText }}

        @if (filled($emptyActionUrl ?? null))
            <x-filament::link :href="$emptyActionUrl">{{ $emptyActionLabel }}</x-filament::link>
        @endif
    </p>
@endif
