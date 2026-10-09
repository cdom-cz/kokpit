<x-filament-panels::page>
    {{-- The controls are links to this page with a new view or date, so the table is built once per request. --}}
    <div
        role="group"
        aria-label="{{ __('kokpit.time.timesheet.controls') }}"
        style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem;"
    >
        <div role="group" aria-label="{{ __('kokpit.time.timesheet.view_switch') }}" style="display: flex; gap: 0.5rem;">
            <x-filament::button
                tag="a"
                :href="$dayUrl"
                :color="$mode === 'day' ? 'primary' : 'gray'"
                :aria-current="$mode === 'day' ? 'true' : null"
            >
                {{ __('kokpit.time.timesheet.day') }}
            </x-filament::button>
            <x-filament::button
                tag="a"
                :href="$weekUrl"
                :color="$mode === 'week' ? 'primary' : 'gray'"
                :aria-current="$mode === 'week' ? 'true' : null"
            >
                {{ __('kokpit.time.timesheet.week') }}
            </x-filament::button>
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <x-filament::icon-button
                tag="a"
                color="gray"
                :href="$previousUrl"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronLeft"
                :label="$previousLabel"
                :tooltip="$previousLabel"
            />
            <x-filament::icon-button
                tag="a"
                color="gray"
                :href="$nextUrl"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronRight"
                :label="$nextLabel"
                :tooltip="$nextLabel"
            />
            <x-filament::button
                tag="a"
                color="gray"
                :href="$currentUrl"
                :aria-current="$isCurrent ? 'date' : null"
            >
                {{ $currentLabel }}
            </x-filament::button>
        </div>

        <form method="get" action="{{ $actionUrl }}" x-data x-on:change="$el.requestSubmit()">
            <input type="hidden" name="view" value="{{ $mode }}">
            <label style="display: grid; gap: 0.25rem;">
                <span style="font-weight: 600;">{{ __('kokpit.time.timesheet.date') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" name="date" :value="$date" required />
                </x-filament::input.wrapper>
            </label>
        </form>
    </div>

    <p style="margin: 0;">{{ $summaryLine }}</p>

    @if ($weekHeading !== null)
        <h2 style="margin: 0; font-size: 1rem; font-weight: 600;">{{ $weekHeading }}</h2>
    @endif

    {{-- The grid is wider than a phone: it scrolls inside this wrapper, never the page. --}}
    <div style="min-width: 0; max-width: 100%; overflow-x: auto;">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
