<x-filament-panels::page>
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
        @foreach ([
            'clientFilter' => ['client', $this->filterOptions['clients']],
            'assigneeFilter' => ['assignee', $this->filterOptions['assignees']],
            'tagFilter' => ['tag', $this->filterOptions['tags']],
            'priorityFilter' => ['priority', $this->filterOptions['priorities']],
        ] as $property => [$name, $options])
            <label wire:key="filter-{{ $property }}" style="display: grid; gap: 0.25rem; min-width: 12rem;">
                <span style="font-size: 0.8rem; font-weight: 600;">{{ __('kokpit.task_board.filters.'.$name) }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="{{ $property }}">
                        <option value="">{{ __('kokpit.task_board.filters.all') }}</option>
                        @foreach ($options as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endforeach

        @if ($this->hasFilters)
            <x-filament::button color="gray" size="sm" wire:click="resetFilters">
                {{ __('kokpit.task_board.filters.reset') }}
            </x-filament::button>
        @endif
    </div>

    <div style="display: flex; gap: 1rem; align-items: flex-start; overflow-x: auto; padding-bottom: 1rem;">
        @foreach ($this->columns as $status => $column)
            <section
                wire:key="column-{{ $status }}"
                style="flex: 0 0 17rem; min-width: 17rem; padding: 0.75rem; border-radius: 0.75rem; background: rgba(127, 127, 127, 0.1);"
            >
                <h3 style="display: flex; justify-content: space-between; margin: 0 0 0.75rem; font-weight: 600;">
                    <span>{{ $column['label'] }}</span>
                    <span style="opacity: 0.7;">{{ $column['total'] > $column['shown'] ? $column['shown'].' / '.$column['total'] : $column['shown'] }}</span>
                </h3>

                <div
                    wire:sort="moveCard"
                    wire:sort:group="board"
                    wire:sort:group-id="{{ $status }}"
                    style="display: grid; gap: 0.5rem; min-height: 4rem; align-content: start;"
                >
                    @foreach ($column['cards'] as $card)
                        <article
                            wire:key="card-{{ $card['id'] }}"
                            wire:sort:item="{{ $card['id'] }}"
                            style="padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.3); background: rgba(255, 255, 255, 0.06); cursor: grab;"
                        >
                            <div style="font-size: 0.75rem; opacity: 0.7;">{{ $card['reference'] }}</div>
                            <a href="{{ $card['url'] }}" style="font-weight: 600;">{{ $card['title'] }}</a>
                            <div style="font-size: 0.75rem; opacity: 0.7;">{{ $card['priority_label'] }}</div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
