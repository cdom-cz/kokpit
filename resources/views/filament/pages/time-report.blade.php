@php
    use App\Domain\Shared\Money\Money;
    use App\Domain\TimeTracking\Support\DurationFormat;
    use App\Providers\LocalisationServiceProvider;
    use Carbon\CarbonImmutable;
    use Filament\Support\Icons\Heroicon;

    $dash = __('kokpit.report.tree.empty_value');
    $hours = static fn (int $seconds): string => DurationFormat::hoursMinutes($seconds);
    $money = static fn (?Money $amount): string => $amount instanceof Money ? $amount->format('cs') : $dash;

    $summary = $report->summary;
    $footer = $report->footer;
    $periods = ['this_month', 'last_month', 'custom'];
    $splits = ['none', 'day', 'user'];

    // A breakdown link keeps every other filter and sets the split; the default split is left out of the URL.
    $splitLinks = [];
    foreach ($splits as $split) {
        $query = http_build_query([
            ...array_diff_key($filters->toQuery(), ['split' => true]),
            ...($split === 'none' ? [] : ['split' => $split]),
        ]);
        $splitLinks[$split] = $query === '' ? $actionUrl : $actionUrl.'?'.$query;
    }

    // The tree as flat rows in display order: client (indent 0), project (1rem), task (2rem), then the day or user
    // rows (3rem). A row knows the rows it controls and the rows above it, which decide whether it is shown.
    $rows = [];
    $walk = function (array $node, int $level, array $ancestors) use (&$walk, &$rows): string {
        $key = 'r'.count($rows);
        $index = count($rows);
        $rows[$index] = ['key' => $key, 'node' => $node, 'level' => $level, 'ancestors' => $ancestors, 'children' => []];

        foreach ($node['children'] as $child) {
            $rows[$index]['children'][] = $walk($child, $level + 1, [...$ancestors, $key]);
        }

        return $key;
    };
    foreach ($report->tree as $client) {
        $walk($client, 0, []);
    }

    // The tree opens only as deep as the filters ask ($openDepth: 0 no client, 1 a client, 2 a project): a row is
    // visible while its level is at most the depth and open while it has children and its level is below it.
    // Every row that has children can be toggled.
    $initiallyOpen = [];
    $expandable = [];
    foreach ($rows as $row) {
        if ($row['children'] !== []) {
            $expandable[] = $row['key'];
            if ($row['level'] < $openDepth) {
                $initiallyOpen[$row['key']] = true;
            }
        }
    }
    $levelNames = [0 => 'client', 1 => 'project', 2 => 'task', 3 => 'leaf'];

    $indents = [0 => '0', 1 => '1rem', 2 => '2rem', 3 => '3rem'];
    $leafLabel = static fn (string $label): string => $filters->split === 'day'
        ? CarbonImmutable::parse($label)->format(LocalisationServiceProvider::DATE_FORMAT)
        : $label;
    $dayText = static fn (?string $day): string => $day === null ? '' : CarbonImmutable::parse($day)->format(LocalisationServiceProvider::DATE_FORMAT);
    $fixedMarker = $summary['fixed'] > 0 ? '*' : '';
    $capText = number_format($report->leafCap, 0, ',', "\u{00A0}");

    // Numeric cells: 0.5rem padding, tabular figures; an amount line never breaks inside itself (nowrap sits on the
    // line, not on the cell, so a badge may wrap below its amount). The money group is set off by a 1px rule.
    $cellStyle = 'padding: 0.5rem; text-align: right; vertical-align: top; font-variant-numeric: tabular-nums;';
    $headStyle = 'padding: 0.5rem; text-align: right; font-weight: 600; vertical-align: bottom; overflow-wrap: anywhere; hyphens: auto;';
    $groupRule = 'border-left: 1px solid color-mix(in srgb, currentColor 12%, transparent);';
    $lineStyle = 'white-space: nowrap;';
    $ruleStyle = 'border-top: 1px solid color-mix(in srgb, currentColor 12%, transparent);';
    $hourColumns = ['worked', 'unbilled', 'billed', 'non_billable'];
    $mutedStyle = 'margin: 0; opacity: 0.8;';
    // A name is clamped to two lines; the full text sits in the title attribute of the label.
    $labelStyle = 'min-width: 0; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; overflow-wrap: anywhere;';
@endphp

<x-filament-panels::page>
    {{-- The filter strip is a GET form that submits on every change; the period group is made of links. --}}
    <div
        role="group"
        aria-label="{{ __('kokpit.report.controls') }}"
        style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem;"
    >
        <form
            method="get"
            action="{{ $actionUrl }}"
            x-data
            x-on:change="$el.requestSubmit()"
            style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem;"
        >
            <input type="hidden" name="period" value="{{ $filters->period }}">
            <input type="hidden" name="split" value="{{ $filters->split }}">

            <label style="display: grid; gap: 0.25rem;">
                <span style="font-weight: 600;">{{ __('kokpit.report.filters.client') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select name="client">
                        <option value="">{{ __('kokpit.report.filters.all_clients') }}</option>
                        @foreach ($clients as $option)
                            <option value="{{ $option['id'] }}" @selected($filters->clientId === $option['id'])>{{ $option['label'] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label style="display: grid; gap: 0.25rem;">
                <span style="font-weight: 600;">{{ __('kokpit.report.filters.project') }}</span>
                <x-filament::input.wrapper :disabled="$filters->clientId === null">
                    <x-filament::input.select name="project" :disabled="$filters->clientId === null">
                        @if ($filters->clientId === null)
                            <option value="">{{ __('kokpit.report.filters.project_hint') }}</option>
                        @else
                            <option value="">{{ __('kokpit.report.filters.all_projects') }}</option>
                            @foreach ($projects as $option)
                                <option value="{{ $option['id'] }}" @selected($filters->projectId === $option['id'])>{{ $option['label'] }}</option>
                            @endforeach
                        @endif
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            @if ($filters->period === 'custom')
                <label style="display: grid; gap: 0.25rem;">
                    <span style="font-weight: 600;">{{ __('kokpit.report.filters.from') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" name="from" :value="$filters->from" required />
                    </x-filament::input.wrapper>
                </label>

                <label style="display: grid; gap: 0.25rem;">
                    <span style="font-weight: 600;">{{ __('kokpit.report.filters.to') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" name="to" :value="$filters->to" required />
                    </x-filament::input.wrapper>
                </label>
            @endif
        </form>

        <div style="display: grid; gap: 0.25rem;">
            <span style="font-weight: 600;">{{ __('kokpit.report.filters.period') }}</span>
            <div role="group" aria-label="{{ __('kokpit.report.filters.period') }}" style="display: flex; gap: 0.5rem;">
                @foreach ($periods as $period)
                    <x-filament::button
                        tag="a"
                        :href="$periodLinks[$period]"
                        :color="$filters->period === $period ? 'primary' : 'gray'"
                        :aria-current="$filters->period === $period ? 'true' : null"
                    >
                        {{ __('kokpit.report.filters.'.$period) }}
                    </x-filament::button>
                @endforeach
            </div>
        </div>

        <div style="display: grid; gap: 0.25rem;">
            <span style="font-weight: 600;">{{ __('kokpit.report.filters.split') }}</span>
            <div role="group" aria-label="{{ __('kokpit.report.filters.split') }}" style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                @foreach ($splits as $split)
                    <x-filament::button
                        tag="a"
                        :href="$splitLinks[$split]"
                        :color="$filters->split === $split ? 'primary' : 'gray'"
                        :aria-current="$filters->split === $split ? 'true' : null"
                    >
                        {{ __('kokpit.report.filters.split_'.$split) }}
                    </x-filament::button>
                @endforeach
            </div>
        </div>

        <p style="margin: 0; padding-bottom: 0.5rem;">{{ $rangeText }}</p>
    </div>

    @if ($filters->rangeError)
        <p role="alert" style="margin: 0; color: var(--danger-600);">{{ __('kokpit.report.filters.range_error') }}</p>
    @endif

    {{-- The summary covers the whole filtered set, never only the rows shown below. --}}
    <div {{ (new \Filament\Support\View\ComponentAttributeBag)->grid(['default' => 1, 'sm' => 2, 'lg' => 4])->style(['gap: 1rem']) }}>
        <x-filament::section :heading="__('kokpit.report.summary.worked')">
            <p style="margin: 0; font-size: 1.875rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums;">{{ $hours($summary['worked']) }}</p>
            <p style="{{ $mutedStyle }}">{{ __('kokpit.report.summary.billable_line', ['duration' => $hours($summary['billable'])]) }}</p>
            <p style="{{ $mutedStyle }}">{{ __('kokpit.report.summary.non_billable_line', ['duration' => $hours($summary['non_billable'])]) }}</p>
        </x-filament::section>

        @foreach (['unbilled' => 'info', 'billed' => 'success'] as $state => $color)
            <x-filament::section>
                <x-slot name="heading">
                    <x-filament::badge :color="$color" :icon="$state === 'billed' ? Heroicon::OutlinedLockClosed : null">
                        {{ __('kokpit.report.summary.'.$state) }}
                    </x-filament::badge>
                </x-slot>

                <p style="margin: 0; font-size: 1.875rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums;">{{ $hours($summary[$state]) }}</p>

                @forelse ($summary['amounts'][$state] as $amount)
                    <p style="{{ $mutedStyle }} font-variant-numeric: tabular-nums;">{{ $amount->format('cs') }}{{ $fixedMarker }}</p>
                @empty
                    <p style="{{ $mutedStyle }}">{{ $dash }}{{ $fixedMarker }}</p>
                @endforelse

                @if ($summary['amounts'][$state] !== [])
                    <p style="{{ $mutedStyle }} font-variant-numeric: tabular-nums;">
                        {{ __('kokpit.report.summary.czk_line', ['czk' => $money($summary['czk'][$state])]) }}
                        @if ($summary['czk_missing_by_state'][$state] > 0)
                            <x-filament::badge color="warning" :icon="Heroicon::OutlinedExclamationTriangle" size="sm">{{ __('kokpit.report.tree.missing_rate.badge') }}</x-filament::badge>
                        @endif
                    </p>
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::section>
            <x-slot name="heading">
                <x-filament::badge color="gray">{{ __('kokpit.report.summary.non_billable') }}</x-filament::badge>
            </x-slot>

            <p style="margin: 0; font-size: 1.875rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums;">{{ $hours($summary['non_billable']) }}</p>
            <p style="{{ $mutedStyle }}">{{ __('kokpit.report.summary.no_amount') }}</p>
        </x-filament::section>
    </div>

    <p style="{{ $mutedStyle }}">{{ __('kokpit.report.notes.computed') }}</p>

    @if ($report->entryCount === 0)
        <x-filament::empty-state
            :heading="__('kokpit.report.empty.heading')"
            :description="__('kokpit.report.empty.description')"
            :icon="Heroicon::OutlinedInbox"
            :contained="false"
            compact
        />
    @else
        {{-- The expand state lives here (Alpine, no server round trip): a row is shown while every row above it is open. --}}
        <div
            x-data="{
                open: {{ \Illuminate\Support\Js::from((object) $initiallyOpen) }},
                all: {{ \Illuminate\Support\Js::from($expandable) }},
                expandAll() { this.all.forEach((key) => { this.open[key] = true; }); },
                collapseAll() { this.open = {}; },
            }"
        >
            <x-filament::section :heading="__('kokpit.report.tree.heading')">
                <x-slot name="afterHeader">
                    <div style="display: flex; gap: 0.5rem;">
                        <x-filament::button type="button" color="gray" size="sm" x-on:click="expandAll()">{{ __('kokpit.report.tree.expand_all') }}</x-filament::button>
                        <x-filament::button type="button" color="gray" size="sm" x-on:click="collapseAll()">{{ __('kokpit.report.tree.collapse_all') }}</x-filament::button>
                    </div>
                </x-slot>

                {{-- The table is wider than a phone: it scrolls inside this wrapper, never the page. A fixed layout gives the
                     Název column the rest of the width (at least 20rem at the 64rem floor), so a title never wraps one word
                     per line (UI-SPEC Amendment A-1). --}}
                <div style="min-width: 0; max-width: 100%; overflow-x: auto;">
                    <table style="width: 100%; min-width: 64rem; table-layout: fixed; border-collapse: collapse;">
                        <colgroup>
                            <col>
                            @foreach ($hourColumns as $column)
                                <col style="width: 6rem;">
                            @endforeach
                            <col style="width: 10rem;">
                            <col style="width: 10rem;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col" rowspan="2" style="padding: 0.5rem 1rem; text-align: left; font-weight: 600; vertical-align: bottom;">{{ __('kokpit.report.tree.columns.name') }}</th>
                                <th scope="colgroup" colspan="4" style="padding: 0.5rem; text-align: right; font-weight: 600;">{{ __('kokpit.report.tree.groups.hours') }}</th>
                                <th scope="col" rowspan="2" style="{{ $headStyle }} {{ $groupRule }}">{{ __('kokpit.report.tree.columns.amount') }}</th>
                                <th scope="col" rowspan="2" style="{{ $headStyle }}">{{ __('kokpit.report.tree.columns.amount_czk') }}</th>
                            </tr>
                            <tr>
                                @foreach ($hourColumns as $column)
                                    <th scope="col" style="{{ $headStyle }}">{{ __('kokpit.report.tree.columns.'.$column) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $node = $row['node'];
                                    $level = $row['level'];
                                    $key = $row['key'];
                                    $seconds = $node['seconds'];
                                    $fixedTip = $node['fixed'] ? __('kokpit.report.tree.fixed_price.tooltip') : null;
                                    $name = $level === 3 ? $leafLabel($node['label']) : $node['label'];
                                    $placeholder = in_array($level, [1, 2], true) && $node['id'] === null;
                                    $isExpandable = $row['children'] !== [];
                                    $isOpen = isset($initiallyOpen[$key]);
                                    // Rows below the open depth start hidden; Alpine keeps the attribute in step afterwards.
                                    $startsHidden = $level > $openDepth;
                                    $showWhen = implode(' || ', array_map(static fn (string $above): string => '! open.'.$above, $row['ancestors']));
                                    $missingTip = $node['czk_missing'] > 0 && $node['czk_missing_currency'] !== null
                                        ? __('kokpit.report.tree.missing_rate.tooltip', ['currency' => $node['czk_missing_currency'], 'date' => $dayText($node['czk_missing_day'])])
                                        : null;
                                @endphp

                                <tr
                                    id="report-row-{{ $key }}"
                                    data-level="{{ $levelNames[$level] }}"
                                    style="{{ $ruleStyle }}"
                                    @if ($startsHidden) hidden @endif
                                    @if ($showWhen !== '') x-bind:hidden="{{ $showWhen }}" @endif
                                >
                                    <th
                                        scope="row"
                                        style="padding: 0.5rem 1rem 0.5rem calc(1rem + {{ $indents[$level] }}); text-align: left; font-weight: {{ $level < 2 ? 600 : 400 }};"
                                    >
                                        <div style="display: flex; align-items: center; gap: 0.5rem; min-height: 1.5rem;">
                                            @if ($isExpandable)
                                                <button
                                                    type="button"
                                                    aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                                    aria-controls="{{ implode(' ', array_map(static fn (string $child): string => 'report-row-'.$child, $row['children'])) }}"
                                                    aria-label="{{ __($isOpen ? 'kokpit.report.tree.collapse' : 'kokpit.report.tree.expand', ['name' => $name]) }}"
                                                    data-expand="{{ __('kokpit.report.tree.expand', ['name' => $name]) }}"
                                                    data-collapse="{{ __('kokpit.report.tree.collapse', ['name' => $name]) }}"
                                                    x-bind:aria-expanded="open.{{ $key }} ? 'true' : 'false'"
                                                    x-bind:aria-label="open.{{ $key }} ? $el.dataset.collapse : $el.dataset.expand"
                                                    x-on:click="open.{{ $key }} = ! open.{{ $key }}"
                                                    style="display: flex; align-items: center; gap: 0.25rem; flex: 1 1 auto; min-width: 0; min-height: 1.5rem; padding: 0; border: 0; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer;"
                                                >
                                                    <x-filament::icon
                                                        :icon="Heroicon::OutlinedChevronRight"
                                                        style="width: 1.25rem; height: 1.25rem; flex: none; {{ $isOpen ? 'display: none;' : '' }}"
                                                        x-show="! open.{{ $key }}"
                                                    />
                                                    <x-filament::icon
                                                        :icon="Heroicon::OutlinedChevronDown"
                                                        style="width: 1.25rem; height: 1.25rem; flex: none; {{ $isOpen ? '' : 'display: none;' }}"
                                                        x-show="open.{{ $key }}"
                                                    />
                                                    <span title="{{ $name }}" style="{{ $labelStyle }} {{ $placeholder ? 'opacity: 0.7;' : '' }}">{{ $name }}</span>
                                                </button>
                                            @else
                                                <span aria-hidden="true" style="display: inline-block; width: 1.5rem; flex: none;"></span>
                                                <span title="{{ $name }}" style="{{ $labelStyle }} {{ $placeholder ? 'opacity: 0.7;' : '' }}">{{ $name }}</span>
                                            @endif

                                            @if ($node['fixed_price'])
                                                <span style="flex: none;">
                                                    <x-filament::badge color="info" size="sm">{{ __('kokpit.report.tree.fixed_price.badge') }}</x-filament::badge>
                                                </span>
                                            @endif
                                        </div>
                                    </th>
                                    @foreach ($hourColumns as $column)
                                        <td style="{{ $cellStyle }}">{{ $hours($seconds[$column]) }}</td>
                                    @endforeach
                                    <td style="{{ $cellStyle }} {{ $groupRule }}">
                                        @forelse ($node['amounts'] as $amount)
                                            <div style="{{ $lineStyle }}">{{ $money($amount) }}</div>
                                        @empty
                                            <span @if ($fixedTip !== null) title="{{ $fixedTip }}" @endif>{{ $dash }}</span>
                                        @endforelse
                                    </td>
                                    <td style="{{ $cellStyle }}">
                                        @if ($node['czk'] !== null)
                                            <span style="{{ $lineStyle }}">{{ $money($node['czk']) }}</span>
                                        @elseif ($node['czk_missing'] === 0)
                                            <span @if ($node['amounts'] === [] && $fixedTip !== null) title="{{ $fixedTip }}" @endif>{{ $dash }}</span>
                                        @endif

                                        @if ($missingTip !== null)
                                            <x-filament::badge color="warning" size="sm" :icon="Heroicon::OutlinedExclamationTriangle" title="{{ $missingTip }}">{{ __('kokpit.report.tree.missing_rate.badge') }}</x-filament::badge>
                                        @endif
                                    </td>
                                </tr>

                                {{-- The effective hourly rate of a fixed-price project (UI-SPEC B4): always visible with its project row, never a toggle target. --}}
                                @php
                                    $effective = $level === 1 && $node['fixed_price'] && $node['id'] !== null ? ($report->fixedPrice[$node['id']] ?? null) : null;
                                @endphp
                                @if ($effective !== null)
                                    @php
                                        $isCzk = $effective['price']->currency === 'CZK';
                                        $czkLine = static fn (?Money $czk): string => __('kokpit.report.summary.czk_line', ['czk' => $money($czk)]);
                                        $blockLabel = 'margin: 0; opacity: 0.8; font-size: 0.875rem;';
                                        $blockValue = 'margin: 0; font-weight: 600; font-variant-numeric: tabular-nums;';
                                        $blockLine = 'margin: 0; opacity: 0.8; font-size: 0.875rem; font-variant-numeric: tabular-nums;';
                                    @endphp
                                    <tr
                                        data-effective-rate
                                        @if ($startsHidden) hidden @endif
                                        @if ($showWhen !== '') x-bind:hidden="{{ $showWhen }}" @endif
                                    >
                                        <td colspan="7" style="padding: 0 1rem 0.75rem calc(1rem + {{ $indents[$level] }} + 1.75rem);">
                                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem 2.5rem;">
                                                <div>
                                                    <p style="{{ $blockLabel }}">{{ __('kokpit.report.effective.fixed_price') }}</p>
                                                    <p style="{{ $blockValue }}">{{ $money($effective['price']) }}</p>
                                                    @if (! $isCzk)
                                                        <p style="{{ $blockLine }}">
                                                            {{ $czkLine($effective['price_czk']) }}
                                                            @if ($effective['czk_missing'])
                                                                <x-filament::badge color="warning" size="sm" :icon="Heroicon::OutlinedExclamationTriangle">{{ __('kokpit.report.tree.missing_rate.badge') }}</x-filament::badge>
                                                            @endif
                                                        </p>
                                                    @endif
                                                </div>

                                                <div>
                                                    <p style="{{ $blockLabel }}">{{ __('kokpit.report.effective.worked') }}</p>
                                                    <p style="{{ $blockValue }}">{{ $hours($effective['project_seconds']) }}</p>
                                                    <p style="{{ $blockLine }}">{{ __('kokpit.report.effective.whole_project') }}</p>
                                                </div>

                                                <div>
                                                    <p style="{{ $blockLabel }}">{{ __('kokpit.report.effective.rate') }}</p>
                                                    <p style="{{ $blockValue }}">
                                                        {{ $effective['effective_rate'] instanceof Money ? __('kokpit.report.effective.per_hour', ['amount' => $money($effective['effective_rate'])]) : $dash }}
                                                    </p>
                                                    @if ($effective['effective_rate'] === null)
                                                        <p style="{{ $blockLine }}">{{ __('kokpit.report.effective.no_hours') }}</p>
                                                    @elseif (! $isCzk)
                                                        <p style="{{ $blockLine }}">
                                                            @if ($effective['effective_rate_czk'] instanceof Money)
                                                                {{ __('kokpit.report.effective.czk_per_hour', ['czk' => $money($effective['effective_rate_czk'])]) }}
                                                            @else
                                                                {{ $czkLine(null) }}
                                                                <x-filament::badge color="warning" size="sm" :icon="Heroicon::OutlinedExclamationTriangle">{{ __('kokpit.report.tree.missing_rate.badge') }}</x-filament::badge>
                                                            @endif
                                                        </p>
                                                    @endif
                                                </div>
                                            </div>

                                            @if (! $isCzk && ! $effective['czk_missing'])
                                                <p style="{{ $blockLine }} margin-top: 0.25rem;">{{ __('kokpit.report.effective.rate_date', ['date' => $dayText($effective['conversion_day'])]) }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="{{ $ruleStyle }} font-weight: 600;">
                                <th scope="row" style="padding: 0.5rem 1rem; text-align: left;">{{ __('kokpit.report.tree.total') }}</th>
                                @foreach ($hourColumns as $column)
                                    <td style="{{ $cellStyle }}">{{ $hours($footer['seconds'][$column]) }}</td>
                                @endforeach
                                <td style="{{ $cellStyle }} {{ $groupRule }}">
                                    @forelse ($footer['amounts'] as $amount)
                                        <div style="{{ $lineStyle }}">{{ $amount->format('cs') }}</div>
                                    @empty
                                        {{ $dash }}
                                    @endforelse
                                </td>
                                <td style="{{ $cellStyle }}">
                                    <span style="{{ $lineStyle }}">{{ $money($footer['czk']) }}</span>
                                    @if ($footer['czk_missing'] > 0)
                                        <x-filament::badge color="warning" size="sm" :icon="Heroicon::OutlinedExclamationTriangle">{{ __('kokpit.report.tree.missing_rate.badge') }}</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- The footnotes, each only when it applies (UI-SPEC B5). --}}
                @if ($summary['fixed'] > 0 || $summary['czk_missing'] > 0 || $report->capped)
                    <div style="display: grid; gap: 0.5rem; margin-top: 1rem; font-size: 0.875rem;">
                        @if ($summary['fixed'] > 0)
                            <p style="{{ $mutedStyle }}">{{ __('kokpit.report.notes.fixed_price') }}</p>
                        @endif

                        @if ($summary['czk_missing'] > 0)
                            <p style="{{ $mutedStyle }}">{{ __('kokpit.report.notes.missing_rate', ['count' => $summary['czk_missing']]) }}</p>
                        @endif

                        @if ($report->capped)
                            <x-filament::callout
                                color="warning"
                                :icon="Heroicon::OutlinedExclamationTriangle"
                                :description="__('kokpit.report.tree.row_cap', ['count' => $capText])"
                            />
                        @endif
                    </div>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
