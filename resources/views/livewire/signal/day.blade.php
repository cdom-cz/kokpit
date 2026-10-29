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
                                            maxlength="{{ \App\Domain\Signal\Support\SignalRules::MAX_TITLE_LENGTH }}"
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
                                        @if ($task['deletable'])
                                            <x-filament::icon-button
                                                icon="heroicon-m-trash"
                                                size="sm"
                                                color="danger"
                                                wire:click="deleteTask('{{ $task['id'] }}')"
                                                wire:confirm="{{ __('kokpit.signal.day.delete_confirm') }}"
                                                :label="__('kokpit.signal.day.delete')"
                                            />
                                        @endif
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
                {{--
                    Type-ahead over unfinished Kokpit tasks (D-03). Alpine only keeps the open flag and the active
                    row; the suggestions, the pick and the stored text are the server's (pickTask / addTask).
                    Enter picks the active row, otherwise it submits the form as free text.
                --}}
                @php($listId = 'signal-suggestions-'.$forDate)
                @php($showList = $pickedTaskId === null && count($this->taskSuggestions) > 0)
                <div
                    x-data="{ open: true, active: -1, options() { return this.$root.querySelectorAll('[role=option]'); } }"
                    x-on:click.outside="open = false"
                    style="position: relative; flex: 1 1 14rem;"
                >
                    <x-filament::input.wrapper
                        :prefix-icon="$pickedTaskId !== null ? 'heroicon-m-link' : null"
                        :inline-prefix="true"
                    >
                        <x-filament::input
                            type="text"
                            maxlength="{{ \App\Domain\Signal\Support\SignalRules::MAX_TITLE_LENGTH }}"
                            wire:model.live.debounce.250ms="newTitle"
                            placeholder="{{ __('kokpit.signal.day.new_placeholder') }}"
                            aria-label="{{ __('kokpit.signal.day.new_placeholder') }}"
                            autocomplete="off"
                            role="combobox"
                            aria-autocomplete="list"
                            :aria-controls="$listId"
                            :readonly="$pickedTaskId !== null"
                            x-bind:aria-expanded="open && {{ $showList ? 'true' : 'false' }} ? 'true' : 'false'"
                            x-bind:aria-activedescendant="open && active >= 0 ? 'signal-suggestion-{{ $forDate }}-' + active : null"
                            style="padding-inline-end: 2.25rem;"
                            x-on:input="open = true; active = -1"
                            x-on:keydown.arrow-down.prevent="if (options().length) { open = true; active = (active + 1) % options().length; options()[active].scrollIntoView({ block: 'nearest' }); }"
                            x-on:keydown.arrow-up.prevent="if (options().length) { open = true; active = active <= 0 ? options().length - 1 : active - 1; options()[active].scrollIntoView({ block: 'nearest' }); }"
                            x-on:keydown.enter="if (open && active >= 0 && options()[active]) { $event.preventDefault(); options()[active].click(); }"
                            x-on:keydown.escape="open = false; active = -1"
                            x-on:keydown.tab="open = false"
                        />
                    </x-filament::input.wrapper>

                    {{-- The suffix sits over the right edge of the field: the spinner while searching, the cross once picked. --}}
                    <div style="position: absolute; top: 0; bottom: 0; right: 0.375rem; display: flex; align-items: center;">
                        @if ($pickedTaskId === null)
                            <x-filament::loading-indicator
                                wire:loading
                                wire:target="newTitle"
                                style="width: 1rem; height: 1rem; margin-right: 0.375rem;"
                            />
                        @else
                            <x-filament::icon-button
                                icon="heroicon-m-x-mark"
                                size="sm"
                                color="gray"
                                :label="__('kokpit.signal.day.clear_pick')"
                                :tooltip="__('kokpit.signal.day.clear_pick')"
                                wire:click="clearPick"
                                x-on:click="open = true; active = -1; $nextTick(() => $root.querySelector('input').focus())"
                            />
                        @endif
                    </div>

                    @if ($showList)
                        <div
                            x-show="open"
                            id="{{ $listId }}"
                            role="listbox"
                            class="fi-dropdown-panel"
                            style="position: absolute; top: 100%; left: 0; width: 100%; margin-top: 0.25rem; z-index: 20; max-height: 25rem; overflow-y: auto; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.25);"
                        >
                            <div class="fi-dropdown-list">
                                @foreach ($this->taskSuggestions as $suggestion)
                                    <button
                                        type="button"
                                        role="option"
                                        id="signal-suggestion-{{ $forDate }}-{{ $loop->index }}"
                                        wire:key="suggestion-{{ $suggestion['id'] }}"
                                        wire:click="pickTask('{{ $suggestion['id'] }}')"
                                        x-on:mouseenter="active = {{ $loop->index }}"
                                        x-on:click="open = false; active = -1"
                                        x-bind:aria-selected="active === {{ $loop->index }} ? 'true' : 'false'"
                                        x-bind:style="active === {{ $loop->index }} ? 'background: rgba(127, 127, 127, 0.15);' : ''"
                                        class="fi-dropdown-list-item"
                                        style="display: flex; flex-direction: column; align-items: flex-start; justify-content: center; gap: 0.125rem; width: 100%; min-height: 2.5rem; padding: 0.25rem 0.5rem; text-align: left;"
                                    >
                                        <span style="display: block; max-width: 100%; font-size: 0.875rem; font-weight: 400; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $suggestion['title'] }}</span>
                                        <span style="display: block; max-width: 100%; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <span style="font-weight: 600;">{{ $suggestion['reference'] }}</span>
                                            <span style="opacity: 0.7;"> · {{ $suggestion['project'] }}</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Planned ahead: pick the colour. Added during the day: always grey, no choice. --}}
                @if (! $day['adds_extra'])
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="newCategory" aria-label="{{ __('kokpit.signal.day.category') }}">
                            @foreach ($day['options'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif

                <x-filament::button type="submit" icon="heroicon-m-plus">{{ __('kokpit.signal.day.add') }}</x-filament::button>
            </form>

            @if ($day['adds_extra'])
                <div style="margin-top: 0.5rem;">
                    <x-filament::badge color="gray" size="sm">{{ __('kokpit.signal.day.adds_extra') }}</x-filament::badge>
                </div>
            @endif
        @endif
    </div>
</x-filament::section>
