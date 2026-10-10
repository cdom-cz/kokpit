<x-filament-panels::page>
    @if ($browsed !== null)
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem;">
            <x-filament::button color="gray" size="sm" icon="heroicon-m-arrow-left" wire:click="goTo('')">
                {{ __('kokpit.signal.today.back_to_today') }}
            </x-filament::button>

            <div style="display: flex; gap: 0.5rem;">
                <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-left" wire:click="goTo('{{ $previous }}')">
                    {{ $previousLabel }}
                </x-filament::button>

                @if ($next !== null)
                    <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-right" icon-position="after" wire:click="goTo('{{ $next }}')">
                        {{ $nextLabel }}
                    </x-filament::button>
                @endif
            </div>
        </div>

        @livewire(\App\Livewire\Signal\SignalDay::class, ['forDate' => $browsed, 'heading' => __('kokpit.signal.today.selected_day')], key('signal-day-'.$browsed))
    @else
        <div style="display: flex; justify-content: flex-end;">
            <x-filament::button color="gray" size="sm" icon="heroicon-m-clock" wire:click="goTo('{{ $previous }}')">
                {{ __('kokpit.signal.today.previous_days') }}
            </x-filament::button>
        </div>

        @livewire(\App\Livewire\Signal\SignalDay::class, ['forDate' => $today, 'heading' => __('kokpit.signal.today.today')], key('signal-day-'.$today))
        @livewire(\App\Livewire\Signal\SignalDay::class, ['forDate' => $tomorrow, 'heading' => __('kokpit.signal.today.tomorrow')], key('signal-day-'.$tomorrow))
    @endif
</x-filament-panels::page>
