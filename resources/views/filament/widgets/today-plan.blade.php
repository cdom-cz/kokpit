{{--
    "Plán na dnešek": the Admin's own Signal plan for today, read only. The first three items are always visible; the
    rest sits in a region that is hidden on every load and opened by one text button (aria-expanded, aria-controls,
    UI-SPEC U-9). The only form control is that button, the only link is "Otevřít plán". A task that is done is struck
    through like in the planner and carries the label "Splněno", so the state is not carried by styling alone. Every
    value goes through {{ }}; the dot colour is a closed set from SignalCategory::getColor(). Spacing in rem multiples
    of 0.25 only (UI-SPEC F-2); the category dot is 0.5rem.
--}}
@php
    use Filament\Support\Icons\Heroicon;

    $regionId = 'today-plan-more';
    $groups = [
        ['id' => null, 'items' => $visibleItems],
        ['id' => $regionId, 'items' => $moreItems],
    ];
    $showLabel = __('kokpit.dashboard.plan.show_more', ['count' => count($moreItems)]);
    $hideLabel = __('kokpit.dashboard.plan.hide_more', ['count' => count($moreItems)]);
    $mutedStyle = 'font-size: 0.875rem; color: var(--gray-500);';
@endphp

<x-filament-widgets::widget>
    <x-filament::section :description="$dateLabel">
        <x-slot name="heading">
            {{ __('kokpit.dashboard.plan.heading') }}
        </x-slot>

        <x-slot name="afterHeader">
            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem 1rem;">
                <x-filament::badge color="gray" size="sm">
                    {{ trans_choice('kokpit.dashboard.plan.count', $count, ['count' => $count]) }}
                </x-filament::badge>

                <x-filament::link :href="$planUrl" size="sm">
                    {{ __('kokpit.dashboard.plan.open_link') }}
                </x-filament::link>
            </div>
        </x-slot>

        @if ($count === 0)
            <x-filament::empty-state
                :heading="__('kokpit.dashboard.plan.empty_heading')"
                :description="__('kokpit.dashboard.plan.empty_description')"
                :icon="Heroicon::OutlinedCalendarDays"
                :contained="false"
                compact
            />
        @else
            <div x-data="{ open: false }">
                @foreach ($groups as $group)
                    @if ($group['items'] !== [])
                        <ul
                            @if ($group['id'] !== null)
                                id="{{ $group['id'] }}"
                                hidden
                                x-bind:hidden="! open"
                            @endif
                            style="margin: 0; padding: 0; list-style: none;"
                        >
                            @foreach ($group['items'] as $item)
                                <li style="display: flex; align-items: center; gap: 0.5rem; min-width: 0; margin: 0 0 0.5rem 0;">
                                    <span
                                        aria-hidden="true"
                                        style="flex: 0 0 auto; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: var(--{{ $item['category_colour'] }}-600);"
                                    ></span>

                                    <span
                                        title="{{ $item['title'] }}"
                                        style="flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; overflow-wrap: anywhere;{{ $item['done'] ? ' text-decoration: line-through; opacity: 0.6;' : '' }}"
                                    >{{ $item['title'] }}</span>

                                    @if ($item['done'])
                                        <span style="flex: 0 0 auto; {{ $mutedStyle }}">{{ __('kokpit.dashboard.plan.done') }}</span>
                                    @endif

                                    @if ($item['recurring'])
                                        <span style="flex: 0 0 auto; display: inline-flex; align-items: center; gap: 0.25rem; {{ $mutedStyle }}">
                                            <x-filament::icon :icon="Heroicon::OutlinedArrowPath" style="width: 1rem; height: 1rem;" />
                                            {{ __('kokpit.dashboard.plan.recurring') }}
                                        </span>
                                    @endif

                                    <span style="flex: 0 0 auto; {{ $mutedStyle }}">{{ $item['category_label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach

                @if ($moreItems !== [])
                    <x-filament::link
                        tag="button"
                        type="button"
                        size="sm"
                        aria-expanded="false"
                        :aria-controls="$regionId"
                        x-bind:aria-expanded="open.toString()"
                        x-on:click="open = ! open"
                    >
                        <span x-text="open ? @js($hideLabel) : @js($showLabel)">{{ $showLabel }}</span>
                    </x-filament::link>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
