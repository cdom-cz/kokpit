@php
    use App\Domain\Shared\Money\Money;
    use App\Domain\TimeTracking\Support\DurationFormat;
    use Filament\Support\Icons\Heroicon;

    $dash = __('kokpit.report.tree.empty_value');
    $hours = static fn (int $seconds): string => DurationFormat::hoursMinutes($seconds);
    $money = static fn (?Money $amount): string => $amount instanceof Money ? $amount->format('cs') : $dash;

    $summary = $report->summary;
    $footer = $report->footer;
    $periods = ['this_month', 'last_month', 'custom'];

    // The tree as flat rows: client (indent 0), project (1.5rem), task (3rem).
    $rows = [];
    foreach ($report->tree as $client) {
        $rows[] = ['node' => $client, 'level' => 0];
        foreach ($client['children'] as $project) {
            $rows[] = ['node' => $project, 'level' => 1];
            foreach ($project['children'] as $task) {
                $rows[] = ['node' => $task, 'level' => 2];
            }
        }
    }
    $indents = [0 => '0', 1 => '1.5rem', 2 => '3rem'];

    $cellStyle = 'padding: 0.5rem 1rem; text-align: right; white-space: nowrap; vertical-align: top; font-variant-numeric: tabular-nums;';
    $headStyle = 'padding: 0.5rem 1rem; text-align: right; font-weight: 600; white-space: nowrap;';
    $ruleStyle = 'border-top: 1px solid color-mix(in srgb, currentColor 12%, transparent);';
    $mutedStyle = 'margin: 0; opacity: 0.8;';
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
                    <p style="{{ $mutedStyle }} font-variant-numeric: tabular-nums;">{{ $amount->format('cs') }}</p>
                @empty
                    <p style="{{ $mutedStyle }}">{{ $dash }}</p>
                @endforelse

                @if ($summary['amounts'][$state] !== [])
                    <p style="{{ $mutedStyle }} font-variant-numeric: tabular-nums;">{{ __('kokpit.report.summary.czk_line', ['czk' => $money($summary['czk'][$state])]) }}</p>
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
        <x-filament::section :heading="__('kokpit.report.tree.heading')">
            {{-- The table is wider than a phone: it scrolls inside this wrapper, never the page. --}}
            <div style="min-width: 0; max-width: 100%; overflow-x: auto;">
                <table style="width: 100%; min-width: 60rem; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th scope="col" style="padding: 0.5rem 1rem; text-align: left; font-weight: 600;">{{ __('kokpit.report.tree.columns.name') }}</th>
                            @foreach (['worked', 'billable', 'non_billable', 'billed', 'unbilled', 'amount', 'amount_czk'] as $column)
                                <th scope="col" style="{{ $headStyle }}">{{ __('kokpit.report.tree.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $node = $row['node'];
                                $seconds = $node['seconds'];
                                $fixedTip = $node['fixed'] ? __('kokpit.report.tree.fixed_price.tooltip') : null;
                            @endphp

                            <tr style="{{ $ruleStyle }}">
                                <th
                                    scope="row"
                                    style="padding: 0.5rem 1rem 0.5rem calc(1rem + {{ $indents[$row['level']] }}); text-align: left; font-weight: {{ $row['level'] < 2 ? 600 : 400 }}; overflow-wrap: anywhere;"
                                >{{ $node['label'] }}</th>
                                <td style="{{ $cellStyle }}">{{ $hours($seconds['worked']) }}</td>
                                <td style="{{ $cellStyle }}">{{ $hours($seconds['billable']) }}</td>
                                <td style="{{ $cellStyle }}">{{ $hours($seconds['non_billable']) }}</td>
                                <td style="{{ $cellStyle }}">{{ $hours($seconds['billed']) }}</td>
                                <td style="{{ $cellStyle }}">{{ $hours($seconds['unbilled']) }}</td>
                                <td style="{{ $cellStyle }}" @if ($node['amount'] === null && $fixedTip !== null) title="{{ $fixedTip }}" @endif>{{ $money($node['amount']) }}</td>
                                <td style="{{ $cellStyle }}" @if ($node['czk'] === null && $fixedTip !== null) title="{{ $fixedTip }}" @endif>{{ $money($node['czk']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="{{ $ruleStyle }} font-weight: 600;">
                            <th scope="row" style="padding: 0.5rem 1rem; text-align: left;">{{ __('kokpit.report.tree.total') }}</th>
                            <td style="{{ $cellStyle }}">{{ $hours($footer['seconds']['worked']) }}</td>
                            <td style="{{ $cellStyle }}">{{ $hours($footer['seconds']['billable']) }}</td>
                            <td style="{{ $cellStyle }}">{{ $hours($footer['seconds']['non_billable']) }}</td>
                            <td style="{{ $cellStyle }}">{{ $hours($footer['seconds']['billed']) }}</td>
                            <td style="{{ $cellStyle }}">{{ $hours($footer['seconds']['unbilled']) }}</td>
                            <td style="{{ $cellStyle }}">
                                @forelse ($footer['amounts'] as $amount)
                                    <div>{{ $amount->format('cs') }}</div>
                                @empty
                                    {{ $dash }}
                                @endforelse
                            </td>
                            <td style="{{ $cellStyle }}">{{ $money($footer['czk']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
