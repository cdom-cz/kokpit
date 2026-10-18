{{--
    "Nevyřešené platby": the stats card of the hours widget, but only while something waits for the Admin. At zero
    unresolved records the card is gone (no tile, no placeholder) and only an empty root stays, which keeps polling:
    the card is itself the alert (UI-SPEC Surface J, U-9). The root element is outside the condition, because Livewire
    needs one at the start of the markup.
--}}
<div @if (! $this->hasUnresolved()) wire:poll.{{ $this->getPollingInterval() }} @endif>
    @if ($this->hasUnresolved())
        @include('filament.widgets.hours-stats')
    @endif
</div>
