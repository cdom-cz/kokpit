<x-filament-panels::page>
    <x-filament::section :heading="__('kokpit.signal.settings.blocks')" :description="__('kokpit.signal.settings.blocks_hint')">
        <form wire:submit="saveBlocks" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <label style="display: grid; gap: 0.25rem;">
                <span style="font-size: 0.875rem; font-weight: 600;">{{ __('kokpit.signal.settings.weekday_blocks') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="number" min="0" max="12" wire:model="weekdayBlocks" />
                </x-filament::input.wrapper>
            </label>

            <label style="display: grid; gap: 0.25rem;">
                <span style="font-size: 0.875rem; font-weight: 600;">{{ __('kokpit.signal.settings.weekend_blocks') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="number" min="0" max="12" wire:model="weekendBlocks" />
                </x-filament::input.wrapper>
            </label>

            <x-filament::button type="submit">{{ __('kokpit.signal.day.save') }}</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section :heading="__('kokpit.signal.settings.recurring')" :description="__('kokpit.signal.settings.recurring_hint')">
        <div style="display: grid; gap: 1rem;">
            @forelse ($this->templates as $template)
                <div
                    wire:key="template-{{ $template['id'] }}"
                    style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; padding: 0.375rem 0.5rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.25); {{ $template['active'] ? '' : 'opacity: 0.55;' }}"
                >
                    <span style="flex: 1 1 12rem; overflow-wrap: anywhere;">{{ $template['title'] }}</span>
                    <x-filament::badge :color="$template['color']" size="sm">{{ $template['label'] }}</x-filament::badge>
                    <span style="font-size: 0.75rem; opacity: 0.8;">
                        @foreach ($template['weekdays'] as $weekday){{ __('kokpit.signal.settings.weekdays_short.'.$weekday) }}{{ $loop->last ? '' : ', ' }}@endforeach
                    </span>
                    <x-filament::button size="xs" color="gray" wire:click="toggleTemplate('{{ $template['id'] }}', {{ $template['active'] ? 'false' : 'true' }})">
                        {{ $template['active'] ? __('kokpit.signal.settings.deactivate') : __('kokpit.signal.settings.activate') }}
                    </x-filament::button>
                    <x-filament::icon-button icon="heroicon-m-pencil-square" size="sm" color="gray" wire:click="editTemplate('{{ $template['id'] }}')" :label="__('kokpit.signal.day.edit')" />
                    <x-filament::icon-button icon="heroicon-m-trash" size="sm" color="danger" wire:click="deleteTemplate('{{ $template['id'] }}')" wire:confirm="{{ __('kokpit.signal.settings.delete_confirm') }}" :label="__('kokpit.signal.day.delete')" />
                </div>
            @empty
                <p style="margin: 0; opacity: 0.7;">{{ __('kokpit.signal.settings.no_templates') }}</p>
            @endforelse

            <form wire:submit="saveTemplate" style="display: grid; gap: 0.75rem; padding-top: 0.5rem;">
                <div style="font-size: 0.875rem; font-weight: 600;">{{ $editingTemplateId === null ? __('kokpit.signal.settings.new_template') : __('kokpit.signal.settings.edit_template') }}</div>

                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <x-filament::input.wrapper style="flex: 1 1 14rem;">
                        <x-filament::input type="text" maxlength="255" wire:model="templateTitle" placeholder="{{ __('kokpit.signal.day.new_placeholder') }}" aria-label="{{ __('kokpit.signal.day.new_placeholder') }}" />
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="templateCategory" aria-label="{{ __('kokpit.signal.day.category') }}">
                            @foreach ($categories as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                    @foreach (range(0, 6) as $weekday)
                        <label wire:key="weekday-{{ $weekday }}" style="display: inline-flex; align-items: center; gap: 0.25rem;">
                            <x-filament::input.checkbox wire:model="templateWeekdays" value="{{ $weekday }}" />
                            <span>{{ __('kokpit.signal.settings.weekdays_short.'.$weekday) }}</span>
                        </label>
                    @endforeach
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <x-filament::button type="submit" icon="heroicon-m-plus">{{ $editingTemplateId === null ? __('kokpit.signal.day.add') : __('kokpit.signal.day.save') }}</x-filament::button>
                    @if ($editingTemplateId !== null)
                        <x-filament::button color="gray" wire:click="resetForm">{{ __('kokpit.signal.day.cancel') }}</x-filament::button>
                    @endif
                </div>
            </form>
        </div>
    </x-filament::section>
</x-filament-panels::page>
