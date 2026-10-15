{{--
    A dashboard table widget inside the shared collapsible card. The vendor table-widget root (fi-wi-table and the two
    render hooks) stays, the table is the flush body of the card, and the card carries the only heading. A link of the
    widget (headerLink) stays in the header, so it is visible while the card is collapsed.
--}}
@php
    use Filament\Support\Facades\FilamentView;
    use Filament\Widgets\View\WidgetsRenderHook;

    $headerLink = $this->headerLink();
@endphp

<x-filament-widgets::widget class="fi-wi-table">
    {{ FilamentView::renderHook(WidgetsRenderHook::TABLE_WIDGET_START, scopes: static::class) }}

    <x-dashboard-card
        flush
        :heading="$this->cardHeading()"
        :summary="$this->collapsedSummary()"
        :collapsed="$this->collapsed"
    >
        <x-slot name="afterHeader">
            @if ($headerLink !== null)
                <x-filament::link :href="$headerLink['url']" size="sm">
                    {{ $headerLink['label'] }}
                </x-filament::link>
            @endif
        </x-slot>

        {{ $this->table ?? null }}
    </x-dashboard-card>

    {{ FilamentView::renderHook(WidgetsRenderHook::TABLE_WIDGET_END, scopes: static::class) }}
</x-filament-widgets::widget>
