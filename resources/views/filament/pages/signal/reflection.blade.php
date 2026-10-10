<x-filament-panels::page>
    <x-filament::section>
        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <label style="display: grid; gap: 0.25rem;">
                <span style="font-size: 0.875rem; font-weight: 600;">{{ __('kokpit.signal.reflection.from') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="from" :value="$from" />
                </x-filament::input.wrapper>
            </label>

            <label style="display: grid; gap: 0.25rem;">
                <span style="font-size: 0.875rem; font-weight: 600;">{{ __('kokpit.signal.reflection.to') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="to" :value="$to" />
                </x-filament::input.wrapper>
            </label>

            <x-filament::button color="gray" wire:click="resetRange">{{ __('kokpit.signal.reflection.reset') }}</x-filament::button>
        </div>
    </x-filament::section>

    <div style="display: flex; justify-content: space-between; font-size: 0.875rem; opacity: 0.7;">
        <span>{{ $label }}</span>
        <span>{{ trans_choice('kokpit.signal.reflection.count', count($recaps), ['count' => count($recaps)]) }}</span>
    </div>

    @forelse ($recaps as $recap)
        <x-filament::section wire:key="recap-{{ $recap['id'] }}">
            <x-slot name="heading">{{ __('kokpit.signal.dashboard.week_of', ['date' => $recap['label']]) }}</x-slot>
            <x-slot name="afterHeader">
                <x-filament::link :href="$recap['url']" size="sm">{{ __('kokpit.signal.reflection.edit') }}</x-filament::link>
            </x-slot>

            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));">
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--success-600);">{{ __('kokpit.signal.week.went_well') }}</div>
                    <p style="margin: 0.25rem 0 0; white-space: pre-wrap;">{{ $recap['well'] !== '' ? $recap['well'] : '—' }}</p>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--info-600);">{{ __('kokpit.signal.week.to_change') }}</div>
                    <p style="margin: 0.25rem 0 0; white-space: pre-wrap;">{{ $recap['change'] !== '' ? $recap['change'] : '—' }}</p>
                </div>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p style="margin: 0; opacity: 0.7;">{{ __('kokpit.signal.reflection.empty') }}</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
