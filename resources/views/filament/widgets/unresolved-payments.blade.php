{{--
    "Nevyřešené platby": the stats card of the hours widget, but only while something waits for the Admin. At zero
    unresolved records the card is gone (no tile, no placeholder) and only an empty root stays, which keeps polling:
    the card is itself the alert (UI-SPEC Surface J, U-9).
--}}
@if ($this->hasUnresolved())
    @include('filament.widgets.hours-stats')
@else
    <div wire:poll.{{ $this->getPollingInterval() }}></div>
@endif
