{{--
    A cell of the table "Nevyfakturováno tento měsíc" that holds more than one piece of text: the client name with
    the "Pevná cena" badge, and the CZK amount with the "Chybí kurz" marker. The row is a computed array, not a
    model, and every value goes through {{ }}. Spacing in rem multiples of 0.25 only (UI-SPEC F-2).
--}}
@php
    use Filament\Support\Icons\Heroicon;

    $record = $getRecord();
@endphp

@if ($kind === 'client')
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; font-weight: 600; overflow-wrap: anywhere;">
        <span>{{ $record['client_name'] }}</span>

        @if ($record['fixed'])
            <x-filament::badge color="info" size="sm">
                {{ __('kokpit.dashboard.unbilled.fixed_badge') }}
            </x-filament::badge>
        @endif
    </div>
@else
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 0.5rem; padding: 0.5rem 1rem; font-variant-numeric: tabular-nums;">
        @if ($record['czk'] !== null)
            <span>{{ $record['czk']->format('cs') }}</span>
        @elseif (! $record['czk_missing'])
            <span>{{ __('kokpit.time.empty_value') }}</span>
        @endif

        @if ($record['czk_missing'])
            <x-filament::badge color="warning" size="sm" :icon="Heroicon::OutlinedExclamationTriangle">
                {{ __('kokpit.dashboard.unbilled.missing_rate') }}
            </x-filament::badge>
        @endif
    </div>
@endif
