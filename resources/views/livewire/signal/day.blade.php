@php($day = $this->day)
<x-filament::section>
    <x-slot name="heading">
        {{ $heading !== '' ? $heading.' · ' : '' }}{{ $day['date_label'] }}
    </x-slot>

    <x-slot name="afterHeader">
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <x-filament::badge color="gray" size="sm">{{ $day['done'] }} / {{ $day['total'] }}</x-filament::badge>

            @if ($day['locked'])
                <x-filament::badge color="warning" size="sm" icon="heroicon-m-lock-closed">{{ __('kokpit.signal.day.locked') }}</x-filament::badge>
                <x-filament::button
                    size="xs"
                    color="gray"
                    wire:click="unlock"
                    wire:confirm="{{ __('kokpit.signal.day.unlock_confirm') }}"
                >{{ __('kokpit.signal.day.unlock') }}</x-filament::button>
            @elseif ($day['unlocked'])
                <x-filament::badge color="info" size="sm" icon="heroicon-m-lock-open">{{ __('kokpit.signal.day.unlocked') }}</x-filament::badge>
                <x-filament::button
                    size="xs"
                    color="gray"
                    wire:click="lock"
                    wire:confirm="{{ __('kokpit.signal.day.lock_confirm') }}"
                >{{ __('kokpit.signal.day.lock') }}</x-filament::button>
            @endif
        </div>
    </x-slot>

    <div style="display: grid; gap: 1rem;">
        {{-- Deep-work blocks: one dot per planned block; a click completes up to it, the last one takes it back. --}}
        @if ($day['blocks']['planned'] > 0 && $day['can_toggle'])
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span style="font-size: 0.875rem; font-weight: 600;">{{ __('kokpit.signal.day.blocks') }}</span>
                @for ($block = 1; $block <= $day['blocks']['planned']; $block++)
                    <button
                        type="button"
                        wire:click="setBlocks({{ $block }})"
                        wire:key="block-{{ $block }}"
                        title="{{ __('kokpit.signal.day.block_title', ['number' => $block]) }}"
                        aria-label="{{ __('kokpit.signal.day.block_title', ['number' => $block]) }}"
                        aria-pressed="{{ $block <= $day['blocks']['completed'] ? 'true' : 'false' }}"
                        style="width: 1.5rem; height: 1.5rem; border-radius: 0.375rem; border: 2px solid var(--primary-600); background: {{ $block <= $day['blocks']['completed'] ? 'var(--primary-600)' : 'transparent' }};"
                    ></button>
                @endfor
                <span style="font-size: 0.75rem; opacity: 0.7;">{{ $day['blocks']['completed'] }} / {{ $day['blocks']['planned'] }}</span>
            </div>
        @endif

        @forelse ($day['groups'] as $group)
            <div wire:key="group-{{ $group['category'] }}" style="display: grid; gap: 0.25rem;">
                <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--{{ $group['color'] }}-600);">
                    {{ $group['label'] }}
                    @if ($group['limit'] !== null)
                        <span style="opacity: 0.7;">· {{ count($group['tasks']) }}</span>
                    @endif
                </div>

                <ul
                    wire:sort="reorder"
                    wire:sort:group-id="{{ $group['category'] }}"
                    style="display: grid; gap: 0.25rem; margin: 0; padding: 0; list-style: none;"
                >
                    @foreach ($group['tasks'] as $task)
                        <li
                            wire:key="task-{{ $task['id'] }}"
                            wire:sort:item="{{ $task['id'] }}"
                            style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.5rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.25);"
                        >
                            @if ($day['can_edit'])
                                <span title="{{ __('kokpit.signal.day.drag') }}" style="cursor: grab; opacity: 0.5; display: inline-flex;">
                                    <x-filament::icon icon="heroicon-m-bars-3" style="width: 1rem; height: 1rem;" />
                                </span>
                            @endif

                            <button
                                type="button"
                                wire:click="toggleDone('{{ $task['id'] }}', {{ $task['done'] ? 'false' : 'true' }})"
                                @disabled(! $day['can_toggle'])
                                title="{{ $task['done'] ? __('kokpit.signal.day.undo') : __('kokpit.signal.day.done') }}"
                                aria-label="{{ $task['done'] ? __('kokpit.signal.day.undo') : __('kokpit.signal.day.done') }}"
                                aria-pressed="{{ $task['done'] ? 'true' : 'false' }}"
                                style="flex: 0 0 auto; width: 1.25rem; height: 1.25rem; border-radius: 9999px; border: 2px solid var(--{{ $group['color'] }}-600); background: {{ $task['done'] ? 'var(--'.$group['color'].'-600)' : 'transparent' }}; {{ $day['can_toggle'] ? '' : 'opacity: 0.4; cursor: not-allowed;' }}"
                            ></button>

                            @if ($editingId === $task['id'])
                                <div wire:sort:ignore style="display: flex; flex: 1 1 auto; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                                    <x-filament::input.wrapper style="flex: 1 1 12rem;">
                                        <x-filament::input
                                            type="text"
                                            maxlength="255"
                                            wire:model="editTitle"
                                            wire:keydown.enter="saveEdit"
                                            wire:keydown.escape="cancelEdit"
                                            autofocus
                                        />
                                    </x-filament::input.wrapper>

                                    @if ($group['category'] !== 'extra')
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model="editCategory">
                                                @foreach ($day['options'] as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    @endif

                                    <x-filament::button size="xs" wire:click="saveEdit">{{ __('kokpit.signal.day.save') }}</x-filament::button>
                                    <x-filament::button size="xs" color="gray" wire:click="cancelEdit">{{ __('kokpit.signal.day.cancel') }}</x-filament::button>
                                </div>
                            @else
                                <span style="flex: 1 1 auto; overflow-wrap: anywhere; {{ $task['done'] ? 'text-decoration: line-through; opacity: 0.6;' : '' }}">
                                    {{ $task['title'] }}
                                </span>

                                @if ($task['recurring'])
                                    <span title="{{ __('kokpit.signal.day.recurring') }}" style="opacity: 0.6; display: inline-flex;">
                                        <x-filament::icon icon="heroicon-m-arrow-path" style="width: 1rem; height: 1rem;" />
                                    </span>
                                @endif

                                @if ($day['can_edit'])
                                    <div wire:sort:ignore style="display: flex; gap: 0.25rem;">
                                        <x-filament::icon-button
                                            icon="heroicon-m-pencil-square"
                                            size="sm"
                                            color="gray"
                                            wire:click="startEdit('{{ $task['id'] }}')"
                                            :label="__('kokpit.signal.day.edit')"
                                        />
                                        <x-filament::icon-button
                                            icon="heroicon-m-trash"
                                            size="sm"
                                            color="danger"
                                            wire:click="deleteTask('{{ $task['id'] }}')"
                                            wire:confirm="{{ __('kokpit.signal.day.delete_confirm') }}"
                                            :label="__('kokpit.signal.day.delete')"
                                        />
                                    </div>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            @if (count($day['shadows']) === 0)
                <p style="margin: 0; opacity: 0.7;">{{ __('kokpit.signal.day.empty') }}</p>
            @endif
        @endforelse

        {{-- Recurring templates of a later day: read-only, they become real tasks when the day is today. --}}
        @if (count($day['shadows']) > 0)
            <div style="display: grid; gap: 0.25rem;">
                <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7;">{{ __('kokpit.signal.day.shadows') }}</div>
                @foreach ($day['shadows'] as $shadow)
                    <div
                        wire:key="shadow-{{ $shadow['id'] }}"
                        style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.5rem; border-radius: 0.5rem; border: 1px dashed rgba(127, 127, 127, 0.4); opacity: 0.8;"
                    >
                        <span style="flex: 0 0 auto; width: 1.25rem; height: 1.25rem; border-radius: 9999px; border: 2px dashed var(--{{ $shadow['color'] }}-600);"></span>
                        <span style="flex: 1 1 auto; overflow-wrap: anywhere;">{{ $shadow['title'] }}</span>
                        <x-filament::badge :color="$shadow['color']" size="sm">{{ $shadow['label'] }}</x-filament::badge>
                        <x-filament::icon icon="heroicon-m-arrow-path" style="width: 1rem; height: 1rem; opacity: 0.6;" />
                    </div>
                @endforeach
            </div>
        @endif

        @if ($day['can_edit'])
            <form wire:submit="addTask" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                <x-filament::input.wrapper style="flex: 1 1 14rem;">
                    <x-filament::input
                        type="text"
                        maxlength="255"
                        wire:model="newTitle"
                        placeholder="{{ __('kokpit.signal.day.new_placeholder') }}"
                        aria-label="{{ __('kokpit.signal.day.new_placeholder') }}"
                    />
                </x-filament::input.wrapper>

                {{-- Planned ahead: pick the colour. Added during the day: always grey, no choice. --}}
                @if (! $day['adds_extra'])
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="newCategory" aria-label="{{ __('kokpit.signal.day.category') }}">
                            @foreach ($day['options'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @else
                    <x-filament::badge color="gray" size="sm">{{ __('kokpit.signal.day.adds_extra') }}</x-filament::badge>
                @endif

                <x-filament::button type="submit" icon="heroicon-m-plus">{{ __('kokpit.signal.day.add') }}</x-filament::button>
            </form>
        @endif
    </div>
</x-filament::section>
