@php
    use App\Filament\Resources\ClientResource;
    use Filament\Support\Icons\Heroicon;

    $running = $this->running;
    $groups = $this->clientGroups;
    $hasClients = $groups['recent'] !== [] || $groups['all'] !== [];

    // Normal running is the warning role; too long (D-07) is the danger role with the triangle and a text.
    $tooLong = $running !== null && $running['long_running'];
    $runningColor = $tooLong ? 'danger' : 'warning';
    $runningTooltip = $tooLong ? __('kokpit.time.timer.long_running_tooltip', ['hours' => $running['hours']]) : null;

    // The state of the side panel as the server knows it (never chosen counts as open, the default
    // from 80rem up); the panel reports its real state in the browser and the label follows it.
    $panelOpen = auth()->user()?->time_panel_open !== false;
    $panelHide = __('kokpit.time.panel.hide');
    $panelShow = __('kokpit.time.panel.show');
@endphp

{{-- The refresh of a persisted region: the poll runs only while the tab is visible. --}}
<div class="kokpit-timer-bar" wire:poll.visible.60s="refreshState">
    {{-- Only the spacing tokens of the design contract (0.25, 0.5, 1, 1.5, 2 rem) and the colour roles of the panel. --}}
    <style>
        .kokpit-timer-bar { display: flex; align-items: center; gap: 0.5rem; }
        .kokpit-timer-pill { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.5rem; border-radius: 0.5rem; background-color: var(--color-50); color: var(--color-700); box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--color-600) 20%, transparent); }
        .dark .kokpit-timer-pill { background-color: color-mix(in oklab, var(--color-400) 10%, transparent); color: var(--color-400); box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--color-400) 30%, transparent); }
        .kokpit-timer-trigger { display: inline-flex; align-items: center; gap: 0.5rem; background: none; border: 0; padding: 0; color: inherit; cursor: pointer; font-size: 0.875rem; line-height: 1.25rem; }
        .kokpit-timer-trigger .fi-icon { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
        .kokpit-timer-readout { font-weight: 600; font-variant-numeric: tabular-nums; min-width: 7ch; white-space: nowrap; text-align: end; }
        .kokpit-timer-description { display: none; max-width: 12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .kokpit-timer-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .kokpit-timer-popover { position: relative; display: inline-flex; }
        .kokpit-timer-panel { position: absolute; top: calc(100% + 0.5rem); right: 0; z-index: 40; width: min(20rem, calc(100vw - 2rem)); border-radius: 0.75rem; background-color: var(--color-white); color: var(--gray-950); box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1), 0 0 0 1px color-mix(in oklab, var(--gray-950) 5%, transparent); }
        .dark .kokpit-timer-panel { background-color: var(--gray-900); color: var(--color-white); box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 0 0 1px color-mix(in oklab, var(--color-white) 10%, transparent); }
        .kokpit-timer-form, .kokpit-timer-detail { display: grid; gap: 1rem; padding: 1rem; }
        .kokpit-timer-heading { font-size: 1rem; line-height: 1.5rem; font-weight: 600; margin: 0; }
        .kokpit-timer-field { display: grid; gap: 0.25rem; font-size: 0.875rem; line-height: 1.25rem; font-weight: 600; }
        .kokpit-timer-lines { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; margin: 0; font-size: 0.875rem; line-height: 1.25rem; }
        .kokpit-timer-lines dt { font-weight: 600; }
        .kokpit-timer-lines dd { margin: 0; overflow-wrap: anywhere; }
        .kokpit-timer-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .kokpit-timer-link { color: var(--primary-600); font-weight: 600; text-decoration: underline; }
        .dark .kokpit-timer-link { color: var(--primary-400); }
        @media (min-width: 1024px) { .kokpit-timer-description { display: inline-block; } }
        @media (max-width: 639px) {
            .kokpit-timer-label { display: none; }
            /* On a phone the panel spans the screen under the top bar instead of hanging off its trigger. */
            .kokpit-timer-panel { position: fixed; top: calc(var(--topbar-height, 4rem) + 0.5rem); left: 1rem; right: 1rem; width: auto; }
        }
    </style>

    {{--
        Both popovers below are plain Alpine, not <x-filament::dropdown>: that one toggles on
        mousedown and keeps its open state on the panel element, so its form flashed and closed
        again. Here the state lives in x-data, which a Livewire re-render keeps, and a click
        toggles it; a click outside or Escape closes it.
    --}}
    @if ($running === null)
        {{-- 1. Idle: the quick start. Starting needs only a client (D-02). --}}
        <div
            class="kokpit-timer-popover"
            wire:key="timer-idle"
            x-data="{ open: false }"
            x-on:click.outside="open = false"
            x-on:keydown.escape.window="open = false"
        >
            <x-filament::button
                color="gray"
                :icon="Heroicon::OutlinedPlay"
                size="sm"
                :aria-label="__('kokpit.time.timer.start')"
                aria-haspopup="dialog"
                x-bind:aria-expanded="open"
                x-on:click="open = ! open"
            >
                <span class="kokpit-timer-label">{{ __('kokpit.time.timer.start') }}</span>
            </x-filament::button>

            <form class="kokpit-timer-panel kokpit-timer-form" x-cloak x-show="open" x-transition.opacity wire:submit="start">
                <h2 class="kokpit-timer-heading">{{ __('kokpit.time.timer.start') }}</h2>

                @if ($hasClients)
                    <label class="kokpit-timer-field">
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

                    <label class="kokpit-timer-field">
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

                    <x-filament::button type="submit" :icon="Heroicon::OutlinedPlay">
                        {{ __('kokpit.time.timer.start') }}
                    </x-filament::button>
                @else
                    <p>
                        {{ __('kokpit.time.timer.no_client') }}
                        <a class="kokpit-timer-link" href="{{ ClientResource::getUrl() }}" wire:navigate>{{ __('kokpit.time.timer.no_client_link') }}</a>
                    </p>
                @endif
            </form>
        </div>
    @else
        {{-- 2. Running: one pill with the clock, the description and the stop button. --}}
        <div
            class="kokpit-timer-popover"
            wire:key="running-{{ $running['id'] }}"
            x-data="{ open: false }"
            x-on:click.outside="open = false"
            x-on:keydown.escape.window="open = false"
        >
            <div class="kokpit-timer-pill fi-color-{{ $runningColor }}" data-state="{{ $tooLong ? 'too-long' : 'running' }}">
                <button
                    type="button"
                    class="kokpit-timer-trigger"
                    aria-haspopup="dialog"
                    x-bind:aria-expanded="open"
                    x-on:click="open = ! open"
                    @if ($tooLong) title="{{ $runningTooltip }}" @endif
                >
                    <x-filament::icon :icon="$tooLong ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedClock" />
                    <span
                        role="timer"
                        aria-live="off"
                        aria-label="{{ __('kokpit.time.timer.elapsed') }}"
                        class="kokpit-timer-readout"
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
                    >{{ $running['elapsed_text'] }}</span>
                    @if ($tooLong)
                        <span class="kokpit-timer-sr">{{ $runningTooltip }}</span>
                    @endif
                    @if (filled($running['description']))
                        <span class="kokpit-timer-description" title="{{ $running['description'] }}">{{ $running['description'] }}</span>
                    @endif
                </button>

                <x-filament::icon-button
                    :color="$runningColor"
                    size="sm"
                    :icon="Heroicon::OutlinedStop"
                    :label="__('kokpit.time.timer.stop')"
                    :tooltip="__('kokpit.time.timer.stop')"
                    wire:click="stop('{{ $running['id'] }}')"
                />
            </div>

            {{-- The detail of the running entry; a missing part reads as a dash. Outside the pill, so it keeps the panel colours. --}}
            <div class="kokpit-timer-panel kokpit-timer-detail" x-cloak x-show="open" x-transition.opacity>
                <h2 class="kokpit-timer-heading">
                    @if ($running['task_url'] !== null)
                        <a class="kokpit-timer-link" href="{{ $running['task_url'] }}" wire:navigate>{{ $running['task_reference'] }} · {{ $running['task_title'] }}</a>
                    @else
                        {{ $running['client'] }}
                    @endif
                </h2>

                @if ($tooLong)
                    <p class="kokpit-timer-heading fi-color-danger" style="color: var(--color-600);">{{ $runningTooltip }}</p>
                @endif

                <dl class="kokpit-timer-lines">
                    <dt>{{ __('kokpit.time.fields.client') }}</dt>
                    <dd>{{ $running['client'] }}</dd>
                    <dt>{{ __('kokpit.time.fields.started_at') }}</dt>
                    <dd>{{ $running['started_text'] }}</dd>
                    <dt>{{ __('kokpit.time.fields.description') }}</dt>
                    <dd>{{ filled($running['description']) ? $running['description'] : __('kokpit.time.empty_value') }}</dd>
                </dl>

                <div class="kokpit-timer-actions">
                    <x-filament::button color="gray" size="sm" :icon="Heroicon::OutlinedPencilSquare" x-on:click="open = false" wire:click="mountAction('completeRunningEntry')">
                        {{ __('kokpit.time.timer.complete') }}
                    </x-filament::button>
                    <x-filament::button color="gray" size="sm" :icon="Heroicon::OutlinedStop" wire:click="stop('{{ $running['id'] }}')">
                        {{ __('kokpit.time.timer.stop') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    {{-- 3. The toggle of the side panel "Poslední záznamy". The panel owns the state; the label follows its report. --}}
    <span
        class="kokpit-timer-toggle"
        x-data="{ open: @js($panelOpen), hide: @js($panelHide), show: @js($panelShow) }"
        x-on:kokpit-time-panel-state.window="open = $event.detail.open"
    >
        <x-filament::icon-button
            color="gray"
            size="sm"
            :icon="Heroicon::OutlinedBars3BottomRight"
            :label="$panelOpen ? $panelHide : $panelShow"
            x-bind:aria-label="open ? hide : show"
            x-bind:title="open ? hide : show"
            x-bind:aria-expanded="open"
            x-on:click="$dispatch('kokpit-time-panel-toggle')"
        />
    </span>

    <x-filament-actions::modals />
</div>
