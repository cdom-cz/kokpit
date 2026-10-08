@php
    use App\Domain\Operations\Health\HealthSlot;
    use App\Providers\LocalisationServiceProvider;

    $results = $this->results;
@endphp

<x-filament-panels::page>
    <div wire:poll.30s>
        <x-filament::section :heading="__('kokpit.system.heading')" :description="__('kokpit.system.description')">
            <dl style="display: grid; gap: 1rem;">
                @foreach (HealthSlot::cases() as $slot)
                    @php($result = $results[$slot->value])

                    <div
                        wire:key="health-slot-{{ $slot->value }}"
                        style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.5rem 1.5rem;"
                    >
                        <dt style="min-width: 16rem; font-weight: 600;">{{ $slot->getLabel() }}</dt>
                        <dd style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.5rem 1rem; margin: 0;">
                            <x-filament::badge :color="$result->status->getColor()">
                                {{ $result->status->getLabel() }}
                            </x-filament::badge>

                            @if (filled($result->value))
                                <span>{{ $result->value }}</span>
                            @endif

                            @if (filled($result->detail))
                                <span style="opacity: 0.7;">{{ $result->detail }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p style="margin-top: 1.5rem; opacity: 0.7;">
                {{ __('kokpit.system.checked_at', ['time' => $this->checkedAt->format(LocalisationServiceProvider::DATE_TIME_SECONDS_FORMAT)]) }}
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
