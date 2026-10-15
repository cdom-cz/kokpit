{{--
    "Odpracovaný čas": the vendor stats-overview root (poll attribute merge, fi-wi-stats-overview) with the stats grid
    inside the shared collapsible card. The poll stays on the widget root, so a collapsed card still refreshes its
    summary.
--}}
@php
    use Filament\Support\View\ComponentAttributeBag;

    $pollingInterval = $this->getPollingInterval();
@endphp

<x-filament-widgets::widget
    :attributes="
        (new ComponentAttributeBag)
            ->merge([
                'wire:poll.' . $pollingInterval => $pollingInterval ? true : null,
            ], escape: false)
            ->class([
                'fi-wi-stats-overview',
            ])
    "
>
    <x-dashboard-card
        :heading="$this->cardHeading()"
        :summary="$this->collapsedSummary()"
        :collapsed="$this->collapsed"
    >
        {{ $this->content }}
    </x-dashboard-card>
</x-filament-widgets::widget>
