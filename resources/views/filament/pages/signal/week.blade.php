<x-filament-panels::page>
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem;">
        <div>
            <div style="font-weight: 600;">{{ $isCurrent ? __('kokpit.signal.week.this_week') : __('kokpit.signal.week.week') }}</div>
            <div style="font-size: 0.875rem; opacity: 0.7;">{{ $label }}</div>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-left" wire:click="goToWeek('{{ $previous }}')">{{ __('kokpit.signal.week.previous') }}</x-filament::button>
            @unless ($isCurrent)
                <x-filament::button color="gray" size="sm" wire:click="goToWeek('{{ $current }}')">{{ __('kokpit.signal.week.this_week') }}</x-filament::button>
            @endunless
            <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-right" icon-position="after" wire:click="goToWeek('{{ $next }}')">{{ __('kokpit.signal.week.next') }}</x-filament::button>
        </div>
    </div>

    <x-filament::section :heading="__('kokpit.signal.week.goals')">
        <div style="display: grid; gap: 0.75rem;">
            @if (count($this->goals) === 0)
                <p style="margin: 0; opacity: 0.7;">{{ __('kokpit.signal.week.no_goals') }}</p>
            @endif

            <ul wire:sort="reorderGoals" style="display: grid; gap: 0.25rem; margin: 0; padding: 0; list-style: none;">
                @foreach ($this->goals as $goal)
                    <li
                        wire:key="goal-{{ $goal['id'] }}"
                        wire:sort:item="{{ $goal['id'] }}"
                        style="display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.5rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.25);"
                    >
                        <span title="{{ __('kokpit.signal.day.drag') }}" style="cursor: grab; opacity: 0.5; display: inline-flex;">
                            <x-filament::icon icon="heroicon-m-bars-3" style="width: 1rem; height: 1rem;" />
                        </span>

                        <button
                            type="button"
                            wire:click="toggleGoal('{{ $goal['id'] }}', {{ $goal['done'] ? 'false' : 'true' }})"
                            title="{{ $goal['done'] ? __('kokpit.signal.day.undo') : __('kokpit.signal.day.done') }}"
                            aria-label="{{ $goal['done'] ? __('kokpit.signal.day.undo') : __('kokpit.signal.day.done') }}"
                            aria-pressed="{{ $goal['done'] ? 'true' : 'false' }}"
                            style="flex: 0 0 auto; width: 1.25rem; height: 1.25rem; border-radius: 9999px; border: 2px solid var(--danger-600); background: {{ $goal['done'] ? 'var(--danger-600)' : 'transparent' }};"
                        ></button>

                        @if ($editingGoalId === $goal['id'])
                            <div wire:sort:ignore style="display: flex; flex: 1 1 auto; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                                <x-filament::input.wrapper style="flex: 1 1 12rem;">
                                    <x-filament::input type="text" maxlength="255" wire:model="editGoalTitle" wire:keydown.enter="saveGoalEdit" wire:keydown.escape="cancelGoalEdit" autofocus />
                                </x-filament::input.wrapper>
                                <x-filament::button size="xs" wire:click="saveGoalEdit">{{ __('kokpit.signal.day.save') }}</x-filament::button>
                                <x-filament::button size="xs" color="gray" wire:click="cancelGoalEdit">{{ __('kokpit.signal.day.cancel') }}</x-filament::button>
                            </div>
                        @else
                            <span style="flex: 1 1 auto; overflow-wrap: anywhere; {{ $goal['done'] ? 'text-decoration: line-through; opacity: 0.6;' : '' }}">{{ $goal['title'] }}</span>
                            <div wire:sort:ignore style="display: flex; gap: 0.25rem;">
                                <x-filament::icon-button icon="heroicon-m-pencil-square" size="sm" color="gray" wire:click="startGoalEdit('{{ $goal['id'] }}')" :label="__('kokpit.signal.day.edit')" />
                                <x-filament::icon-button icon="heroicon-m-trash" size="sm" color="danger" wire:click="deleteGoal('{{ $goal['id'] }}')" wire:confirm="{{ __('kokpit.signal.week.delete_confirm') }}" :label="__('kokpit.signal.day.delete')" />
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if (count($this->goals) < 3)
                <form wire:submit="addGoal" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                    <x-filament::input.wrapper style="flex: 1 1 14rem;">
                        <x-filament::input type="text" maxlength="255" wire:model="newGoal" placeholder="{{ __('kokpit.signal.week.new_goal') }}" aria-label="{{ __('kokpit.signal.week.new_goal') }}" />
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit" icon="heroicon-m-plus">{{ __('kokpit.signal.day.add') }}</x-filament::button>
                </form>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('kokpit.signal.week.recap')">
        <form wire:submit="saveRecap" style="display: grid; gap: 0.75rem;">
            <label style="display: grid; gap: 0.25rem;">
                <span style="font-size: 0.875rem; font-weight: 600;">{{ __('kokpit.signal.week.went_well') }}</span>
                <x-filament::input.wrapper>
                    <textarea wire:model="wentWell" rows="4" class="fi-input" style="width: 100%; padding: 0.5rem 0.75rem; background: transparent;"></textarea>
                </x-filament::input.wrapper>
            </label>

            <label style="display: grid; gap: 0.25rem;">
                <span style="font-size: 0.875rem; font-weight: 600;">{{ __('kokpit.signal.week.to_change') }}</span>
                <x-filament::input.wrapper>
                    <textarea wire:model="toChange" rows="4" class="fi-input" style="width: 100%; padding: 0.5rem 0.75rem; background: transparent;"></textarea>
                </x-filament::input.wrapper>
            </label>

            <div>
                <x-filament::button type="submit">{{ __('kokpit.signal.week.save_recap') }}</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
