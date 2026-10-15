{{--
    The one collapsible card of every dashboard widget (quick task 261015-cv5). The widget view keeps its own
    x-filament-widgets::widget root (polling, column span); this component renders only the card.

    The header is the Filament section header with its chevron button (aria-expanded, aria-controls). While the card is
    collapsed the body is gone and the header shows the title, the one key figure ($summary) and whatever the caller
    put into afterHeader. The server state is applied in the first render ($collapsed): the body starts cloaked and the
    summary hidden or shown accordingly, so nothing flashes open. A toggle is written in the background through the
    widget's renderless storeCollapsedState(); the card goes back to the state the server returns when the write
    failed. Every value goes through {{ }}. Spacing in rem multiples of 0.25 only (UI-SPEC F-2).

    $flush: the body is a Filament table that draws its own rounded card; the rule below lets it read as the body of
    this one card (no second ring and shadow, only the bottom corners rounded).
--}}
@props([
    'heading',
    'summary' => null,
    'collapsed' => false,
    'flush' => false,
    'description' => null,
])

@php
    $mutedStyle = 'font-size: 0.875rem; color: var(--gray-500);';
@endphp

@if ($flush)
    <style>
        .kokpit-dashboard-card .fi-ta-ctn {
            box-shadow: none;
            border-radius: 0 0 0.75rem 0.75rem;
        }
    </style>
@endif

<x-filament::section
    collapsible
    :collapsed="$collapsed"
    :heading="$heading"
    :has-content-el="! $flush"
    class="kokpit-dashboard-card"
    x-init="
        let reverting = false

        $watch('isCollapsed', async (value) => {
            if (reverting) {
                reverting = false

                return
            }

            let stored = ! value

            try {
                stored = await $wire.storeCollapsedState(value)
            } catch (error) {
                stored = ! value
            }

            if (stored !== value) {
                reverting = true
                isCollapsed = stored
            }
        })
    "
>
    <x-slot name="afterHeader">
        <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem 1rem;">
            @if (filled($summary))
                <span
                    x-show="isCollapsed"
                    x-on:click="isCollapsed = false"
                    @if (! $collapsed) style="display: none; {{ $mutedStyle }}" @else style="{{ $mutedStyle }}" @endif
                >{{ $summary }}</span>
            @endif

            @isset($afterHeader)
                {{ $afterHeader }}
            @endisset
        </div>
    </x-slot>

    @if (filled($description))
        <p style="margin: 0 0 0.75rem 0; {{ $mutedStyle }}">{{ $description }}</p>
    @endif

    {{ $slot }}
</x-filament::section>
