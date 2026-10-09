@php
    use App\Domain\TimeTracking\Support\DurationFormat;
    use App\Filament\Resources\ClientResource;
    use App\Filament\Resources\TimeEntryResource;
    use Filament\Support\Icons\Heroicon;

    $running = $this->running;
    $groups = $this->clientGroups;
    $recent = $this->recent;
    $hasClients = $groups['recent'] !== [] || $groups['all'] !== [];

    // Normal running is the warning role; too long (D-07) is the danger role.
    $tooLong = $running !== null && $running['long_running'];
    $runningColor = $tooLong ? 'danger' : 'warning';
@endphp

{{-- The Livewire root is layout-neutral, so the aside is the flex child of the panel layout. --}}
<div class="kokpit-panel-root" wire:poll.visible.60s="refreshState">
    {{-- Only the spacing tokens of the design contract (0.25, 0.5, 1, 1.5, 2 rem) and the colour roles of the panel. --}}
    <style>
        .kokpit-panel-root { display: contents; }
        .kokpit-panel { display: none; flex-direction: column; width: min(20rem, 100vw); background-color: var(--color-white); color: inherit; box-shadow: inset 1px 0 0 color-mix(in oklab, currentColor 12%, transparent); overflow-y: auto; overscroll-behavior: contain; }
        .dark .kokpit-panel { background-color: var(--gray-900); }
        /* Docked from 80rem (xl): a flex child under the top bar that shrinks the main content. */
        @media (min-width: 80rem) {
            .kokpit-panel { display: flex; flex: 0 0 20rem; position: sticky; top: var(--topbar-height, 4rem); height: calc(100dvh - var(--topbar-height, 4rem)); }
        }
        .kokpit-panel-header { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 1rem; }
        .kokpit-panel-heading { font-size: 1rem; line-height: 1.5rem; font-weight: 600; margin: 0; }
        .kokpit-panel-timer { display: grid; gap: 1rem; padding: 0 1rem 1rem; }
        .kokpit-panel-field { display: grid; gap: 0.25rem; font-size: 0.875rem; line-height: 1.25rem; font-weight: 600; }
        .kokpit-panel-readout { font-size: 1.875rem; line-height: 2.25rem; font-weight: 600; font-variant-numeric: tabular-nums; min-width: 9ch; white-space: nowrap; }
        .kokpit-panel-readout.fi-color-gray, .kokpit-panel-readout.fi-color-warning, .kokpit-panel-readout.fi-color-danger { color: var(--color-600); }
        .dark .kokpit-panel-readout.fi-color-gray, .dark .kokpit-panel-readout.fi-color-warning, .dark .kokpit-panel-readout.fi-color-danger { color: var(--color-400); }
        .kokpit-panel-line { margin: 0; font-size: 0.875rem; line-height: 1.25rem; overflow-wrap: anywhere; }
        .kokpit-panel-clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .kokpit-panel-link { color: var(--primary-600); font-weight: 600; text-decoration: underline; }
        .dark .kokpit-panel-link { color: var(--primary-400); }
        .kokpit-panel-actions { display: flex; align-items: center; gap: 0.5rem; }
        .kokpit-panel-actions > :first-child { flex: 1; }
        .kokpit-panel-muted.fi-color-gray { color: var(--color-500); }
        .dark .kokpit-panel-muted.fi-color-gray { color: var(--color-400); }
        .kokpit-panel-list { display: grid; gap: 1rem; padding: 1rem; border-top: 1px solid color-mix(in oklab, currentColor 12%, transparent); }
        .kokpit-panel-day { display: grid; gap: 0.5rem; }
        .kokpit-panel-day-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; margin: 0; font-size: 0.875rem; line-height: 1.25rem; font-weight: 600; }
        .kokpit-panel-row { position: relative; display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; padding: 0.25rem 0; }
        .kokpit-panel-row-text { display: grid; min-width: 0; font-size: 0.875rem; line-height: 1.25rem; }
        .kokpit-panel-row-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .kokpit-panel-row-open { position: absolute; inset: 0; }
        .kokpit-panel-row-title a { position: relative; z-index: 1; }
        .kokpit-panel-row-duration { font-size: 0.875rem; line-height: 1.25rem; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .kokpit-panel-footer { display: grid; gap: 0.5rem; justify-items: start; padding: 0 1rem 1rem; }
        .kokpit-panel-empty { display: grid; gap: 0.25rem; }
    </style>

    <aside class="kokpit-panel" aria-label="{{ __('kokpit.time.panel.heading') }}">
        <div class="kokpit-panel-header">
            <h2 class="kokpit-panel-heading">{{ __('kokpit.time.panel.heading') }}</h2>
        </div>

        {{-- 1. The timer block: the same single running entry as the bar. --}}
        <div class="kokpit-panel-timer">
            @if ($running === null)
                @if ($hasClients)
                    <form class="kokpit-panel-timer" style="padding: 0;" wire:submit="start">
                        <label class="kokpit-panel-field">
                            <span>{{ __('kokpit.time.timer.client') }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="clientId">
                                    <option value="">{{ __('kokpit.time.timer.client_placeholder') }}</option>
                                    @if ($groups['recent'] !== [])
                                        <optgroup label="{{ __('kokpit.time.timer.group_recent') }}">
                                            @foreach ($groups['recent'] as $id => $name)
                                                <option value="{{ $id }}">{{ $name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if ($groups['all'] !== [])
                                        <optgroup label="{{ __('kokpit.time.timer.group_all') }}">
                                            @foreach ($groups['all'] as $id => $name)
                                                <option value="{{ $id }}">{{ $name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>

                        <label class="kokpit-panel-field">
                            <span>{{ __('kokpit.time.timer.description') }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text"
                                    wire:model="description"
                                    maxlength="1000"
                                    :placeholder="__('kokpit.time.timer.description_placeholder')"
                                />
                            </x-filament::input.wrapper>
                        </label>

                        <div class="kokpit-panel-readout fi-color-gray" role="timer" aria-live="off" aria-label="{{ __('kokpit.time.timer.elapsed') }}">0:00:00</div>

                        <x-filament::button type="submit" :icon="Heroicon::OutlinedPlay" style="width: 100%;">
                            {{ __('kokpit.time.timer.start') }}
                        </x-filament::button>
                    </form>
                @else
                    <p class="kokpit-panel-line">
                        {{ __('kokpit.time.timer.no_client') }}
                        <a class="kokpit-panel-link" href="{{ ClientResource::getUrl() }}" wire:navigate>{{ __('kokpit.time.timer.no_client_link') }}</a>
                    </p>
                @endif
            @else
                <div wire:key="panel-running-{{ $running['id'] }}" style="display: grid; gap: 1rem;">
                    @if ($tooLong)
                        <x-filament::callout
                            color="danger"
                            :icon="Heroicon::OutlinedExclamationTriangle"
                            :heading="__('kokpit.time.panel.callout_heading')"
                            :description="__('kokpit.time.panel.callout_body', ['hours' => $running['hours']])"
                        />
                    @endif

                    <div style="display: grid; gap: 0.25rem;">
                        <p class="kokpit-panel-line" style="font-weight: 600;" title="{{ $running['task_url'] !== null ? $running['task_reference'].' · '.$running['task_title'] : $running['client'] }}">
                            @if ($running['task_url'] !== null)
                                <a class="kokpit-panel-link" href="{{ $running['task_url'] }}" wire:navigate>{{ $running['task_reference'] }} · {{ $running['task_title'] }}</a>
                            @else
                                {{ $running['client'] }}
                            @endif
                        </p>
                        <p class="kokpit-panel-line kokpit-panel-muted fi-color-gray kokpit-panel-clamp" @if (filled($running['description'])) title="{{ $running['description'] }}" @endif>{{ filled($running['description']) ? $running['description'] : __('kokpit.time.empty_value') }}</p>
                    </div>

                    <div
                        role="timer"
                        aria-live="off"
                        aria-label="{{ __('kokpit.time.timer.elapsed') }}"
                        class="kokpit-panel-readout fi-color-{{ $runningColor }}"
                        data-state="{{ $tooLong ? 'too-long' : 'running' }}"
                        x-data="{
                            started: Date.parse(@js($running['started_at'])),
                            skew: Date.parse(@js($running['now'])) - Date.now(),
                            text: @js($running['elapsed_text']),
                            handle: null,
                            tick() {
                                const total = Math.max(0, Math.floor((Date.now() + this.skew - this.started) / 1000))
                                const pad = (value) => String(value).padStart(2, '0')
                                this.text = Math.floor(total / 3600) + ':' + pad(Math.floor((total % 3600) / 60)) + ':' + pad(total % 60)
                            },
                            init() {
                                this.tick()
                                this.handle = setInterval(() => this.tick(), 1000)
                            },
                            destroy() {
                                clearInterval(this.handle)
                            },
                        }"
                        x-text="text"
                    >{{ $running['elapsed_text'] }}</div>

                    <div class="kokpit-panel-actions">
                        <x-filament::button :color="$runningColor" :icon="Heroicon::OutlinedStop" style="width: 100%;" wire:click="stop('{{ $running['id'] }}')">
                            {{ __('kokpit.time.timer.stop') }}
                        </x-filament::button>
                        <x-filament::icon-button
                            color="gray"
                            :icon="Heroicon::OutlinedPencilSquare"
                            :label="__('kokpit.time.timer.complete')"
                            :tooltip="__('kokpit.time.timer.complete')"
                            wire:click="mountAction('completeRunningEntry')"
                        />
                    </div>
                </div>
            @endif
        </div>

        {{-- 2. The finished entries by Prague day, newest first. The running entry is not repeated here. --}}
        <div class="kokpit-panel-list">
            @forelse ($recent['days'] as $day)
                <section class="kokpit-panel-day" wire:key="panel-day-{{ $day['date'] }}">
                    <h3 class="kokpit-panel-day-head">
                        <span>{{ $day['label'] }}</span>
                        <span style="font-variant-numeric: tabular-nums;">{{ DurationFormat::hoursMinutes($day['total_seconds']) }}</span>
                    </h3>

                    @foreach ($day['entries'] as $entry)
                        <div class="kokpit-panel-row" wire:key="panel-entry-{{ $entry['id'] }}">
                            <a class="kokpit-panel-row-open" href="{{ $entry['view_url'] }}" wire:navigate aria-label="{{ $entry['title'] }}"></a>
                            <div class="kokpit-panel-row-text">
                                <span class="kokpit-panel-row-title" title="{{ $entry['title'] }}">
                                    @if ($entry['task_url'] !== null)
                                        <a class="kokpit-panel-link" href="{{ $entry['task_url'] }}" wire:navigate>{{ $entry['title'] }}</a>
                                    @else
                                        {{ $entry['title'] }}
                                    @endif
                                </span>
                                @if (filled($entry['second']))
                                    <span class="kokpit-panel-muted fi-color-gray kokpit-panel-clamp" title="{{ $entry['second'] }}">{{ $entry['second'] }}</span>
                                @endif
                            </div>
                            <span class="kokpit-panel-row-duration">{{ DurationFormat::hoursMinutes($entry['duration_seconds']) }}</span>
                        </div>
                    @endforeach
                </section>
            @empty
                <div class="kokpit-panel-empty">
                    <h3 class="kokpit-panel-heading">{{ __('kokpit.time.panel.empty_heading') }}</h3>
                    <p class="kokpit-panel-line kokpit-panel-muted fi-color-gray">{{ __('kokpit.time.panel.empty_body') }}</p>
                </div>
            @endforelse
        </div>

        <div class="kokpit-panel-footer">
            @if ($recent['has_more'])
                <x-filament::button color="gray" size="sm" wire:click="loadOlder">
                    {{ __('kokpit.time.panel.load_older') }}
                </x-filament::button>
            @endif
            <x-filament::link :href="TimeEntryResource::getUrl('index')" wire:navigate>
                {{ __('kokpit.time.panel.show_all') }}
            </x-filament::link>
        </div>
    </aside>

    <x-filament-actions::modals />
</div>
