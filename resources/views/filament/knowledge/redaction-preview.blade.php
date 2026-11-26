{{--
    The numbered source preview of the redaction modal (VER-05, D-12): the stored text of one version, one row per line,
    numbered the way the secret guard numbers lines, so the Admin can name the lines to redact. Takes $lines (the stored
    lines, VersionDiff::lines()) and $maxLines (the rows shown at most). Every value is printed through the escaped echo:
    a stored line is plain text here, never Markdown or HTML, and never goes through the renderer. The text is visible to
    the Admin only and is never logged. Styled by resources/css/kokpit-diff.css; layout by inline styles.
--}}
@php
    $shown = array_slice($lines, 0, $maxLines);
    $truncated = count($lines) > $maxLines;
    $maxFormatted = number_format($maxLines, 0, '', ' ');
@endphp
<div class="kokpit-diff-block">
    <p class="kokpit-diff__summary">{{ __('knowledge.redact.source_label') }}</p>

    <div class="kokpit-diff" role="region" tabindex="0" aria-label="{{ __('knowledge.redact.source_label') }}" style="max-height:320px;overflow-y:auto;">
        @foreach ($shown as $index => $text)
            <div style="display:flex;align-items:baseline;">
                <span class="kokpit-diff__number" style="flex:none;box-sizing:border-box;width:64px;padding:0 8px;">{{ $index + 1 }}</span>
                <span class="kokpit-diff__text" style="flex:1 1 auto;min-width:0;min-height:1.5em;padding:0 8px;">{{ $text }}</span>
            </div>
        @endforeach
    </div>

    @if ($truncated)
        <p class="kokpit-diff__notice">{{ __('knowledge.redact.source_truncated', ['max' => $maxFormatted]) }}</p>
    @endif
</div>
