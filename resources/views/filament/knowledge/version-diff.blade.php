{{--
    The one diff component of Phase 19 (VER-04, D-09): the compare page and the restore modal both render it.
    Takes $result (VersionDiffResult), $fromNo and $toNo (version numbers), $fromTitle and $toTitle, $caption (the
    caller's table caption) and $maxHeight (null, or a CSS length such as 60vh for an own scroll area).
    Every value is printed through the escaped echo: a stored line is plain text here, never Markdown or HTML.
    Styled by resources/css/kokpit-diff.css.
--}}
@php
    $maxHeight ??= null;
    $maxRows = number_format(\App\Domain\Knowledge\Support\VersionDiff::MAX_ROWS, 0, '', ' ');
@endphp
<div class="kokpit-diff-block">
    @if (! $result->identical && ! $result->tooLarge)
        <p class="kokpit-diff__summary">{{ __('knowledge.diff.summary', ['added' => $result->added, 'removed' => $result->removed]) }}</p>
    @endif

    @if ($fromTitle !== $toTitle)
        <div class="kokpit-diff__title">
            <div class="kokpit-diff__line kokpit-diff__line--removed">
                <span class="kokpit-diff__line-marker" aria-hidden="true">-</span>
                <span><span class="kokpit-diff__sr">{{ __('knowledge.diff.removed') }}</span>{{ $fromTitle }}</span>
            </div>
            <div class="kokpit-diff__line kokpit-diff__line--added">
                <span class="kokpit-diff__line-marker" aria-hidden="true">+</span>
                <span><span class="kokpit-diff__sr">{{ __('knowledge.diff.added') }}</span>{{ $toTitle }}</span>
            </div>
        </div>
    @else
        <p class="kokpit-diff__title-unchanged">{{ __('knowledge.diff.title_unchanged', ['title' => $toTitle]) }}</p>
    @endif

    @if ($result->identical)
        <p class="kokpit-diff__empty">{{ __('knowledge.diff.identical') }}</p>
    @elseif ($result->tooLarge)
        <p class="kokpit-diff__empty">{{ __('knowledge.diff.too_large') }}</p>
    @else
        <div class="kokpit-diff__scroll" @if ($maxHeight !== null) style="max-height: {{ $maxHeight }};" @endif>
            <table class="kokpit-diff">
                <caption class="kokpit-diff__sr">{{ $caption }}</caption>
                <colgroup>
                    <col class="kokpit-diff__col-number">
                    <col class="kokpit-diff__col-number">
                    <col class="kokpit-diff__col-marker">
                    <col>
                </colgroup>
                <thead class="kokpit-diff__sr">
                    <tr>
                        <th scope="col">{{ __('knowledge.diff.column_old', ['from' => $fromNo]) }}</th>
                        <th scope="col">{{ __('knowledge.diff.column_new', ['to' => $toNo]) }}</th>
                        <th scope="col">{{ __('knowledge.diff.column_marker') }}</th>
                        <th scope="col">{{ __('knowledge.diff.column_text') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result->rows as $row)
                        @if ($row->type === \App\Domain\Knowledge\Support\DiffRow::COLLAPSED)
                            <tr>
                                <td class="kokpit-diff__collapsed" colspan="4">{{ __('knowledge.diff.collapsed', ['count' => $row->collapsed]) }}</td>
                            </tr>
                        @else
                            @php
                                $marker = match ($row->type) {
                                    \App\Domain\Knowledge\Support\DiffRow::ADDED => '+',
                                    \App\Domain\Knowledge\Support\DiffRow::REMOVED => '-',
                                    default => '',
                                };
                            @endphp
                            <tr class="kokpit-diff__row--{{ $row->type }}">
                                <td class="kokpit-diff__number">{{ $row->oldLine }}</td>
                                <td class="kokpit-diff__number">{{ $row->newLine }}</td>
                                <td class="kokpit-diff__marker"><span aria-hidden="true">{{ $marker }}</span></td>
                                <td class="kokpit-diff__text">@if ($row->type === \App\Domain\Knowledge\Support\DiffRow::ADDED)<span class="kokpit-diff__sr">{{ __('knowledge.diff.added') }}</span>@elseif ($row->type === \App\Domain\Knowledge\Support\DiffRow::REMOVED)<span class="kokpit-diff__sr">{{ __('knowledge.diff.removed') }}</span>@endif{{ $row->text }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($result->truncated)
            <p class="kokpit-diff__notice">{{ __('knowledge.diff.truncated', ['max' => $maxRows]) }}</p>
        @endif
    @endif
</div>
