@php($overview = $this->overview)
<x-filament-panels::page>
    @livewire(\App\Livewire\Signal\SignalDay::class, ['forDate' => $overview['today'], 'heading' => __('kokpit.signal.dashboard.today_tasks')], key('signal-day-'.$overview['today']))

    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));">
        <x-filament::section :heading="__('kokpit.signal.dashboard.progress')">
            <div style="display: grid; gap: 0.75rem;">
                @foreach ($overview['progress'] as $row)
                    <div wire:key="progress-{{ $loop->index }}" style="display: grid; gap: 0.25rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.875rem;">
                            <span>{{ $row['label'] }}</span>
                            <span style="opacity: 0.7;">{{ $row['done'] }} / {{ $row['total'] }}</span>
                        </div>
                        <div style="height: 0.5rem; border-radius: 9999px; background: rgba(127, 127, 127, 0.15); overflow: hidden;">
                            <div style="height: 100%; width: {{ $row['total'] > 0 ? round($row['done'] / $row['total'] * 100) : 0 }}%; background: var(--{{ $row['color'] }}-600);"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('kokpit.signal.dashboard.goals')">
            <x-slot name="afterHeader">
                <x-filament::link :href="\App\Filament\Pages\Signal\SignalWeekPage::getUrl()" size="sm">{{ __('kokpit.signal.dashboard.open_week') }}</x-filament::link>
            </x-slot>

            @if (count($overview['goals']) === 0)
                <p style="margin: 0; opacity: 0.7;">{{ __('kokpit.signal.dashboard.no_goals') }}</p>
            @else
                <div style="display: grid; gap: 0.5rem;">
                    <span style="font-size: 0.875rem; opacity: 0.7;">{{ __('kokpit.signal.dashboard.goals_done', ['done' => $overview['goalsDone'], 'total' => count($overview['goals'])]) }}</span>
                    @foreach ($overview['goals'] as $goal)
                        <div wire:key="goal-{{ $loop->index }}" style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="flex: 0 0 auto; width: 1rem; height: 1rem; border-radius: 9999px; border: 2px solid var(--danger-600); background: {{ $goal['done'] ? 'var(--danger-600)' : 'transparent' }};"></span>
                            <span style="{{ $goal['done'] ? 'text-decoration: line-through; opacity: 0.6;' : '' }}">{{ $goal['title'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('kokpit.signal.dashboard.trend7')">
            @include('filament.pages.signal.partials.trend', ['data' => $overview['trend7'], 'label' => __('kokpit.signal.dashboard.trend7')])
        </x-filament::section>

        <x-filament::section :heading="__('kokpit.signal.dashboard.trend30')">
            @include('filament.pages.signal.partials.trend', ['data' => $overview['trend30'], 'label' => __('kokpit.signal.dashboard.trend30')])
        </x-filament::section>

        <x-filament::section :heading="__('kokpit.signal.dashboard.blocks30')">
            @include('filament.pages.signal.partials.trend', ['data' => $overview['blocks30'], 'label' => __('kokpit.signal.dashboard.blocks30')])
            <p style="margin: 0.5rem 0 0; font-size: 0.875rem; opacity: 0.7;">{{ __('kokpit.signal.dashboard.blocks_done', ['count' => $overview['blocksDone']]) }}</p>
        </x-filament::section>

        <x-filament::section :heading="__('kokpit.signal.dashboard.split')">
            @if ($overview['split']['total'] === 0)
                <p style="margin: 0; opacity: 0.7;">{{ __('kokpit.signal.dashboard.no_tasks') }}</p>
            @else
                <div style="display: grid; gap: 0.5rem;">
                    <div style="font-size: 1.5rem; font-weight: 600;">{{ round($overview['split']['extra'] / $overview['split']['total'] * 100) }} %</div>
                    <div style="font-size: 0.875rem; opacity: 0.7;">{{ __('kokpit.signal.dashboard.split_text', ['planned' => $overview['split']['planned'], 'extra' => $overview['split']['extra']]) }}</div>
                    <div style="display: flex; height: 0.75rem; border-radius: 9999px; overflow: hidden;">
                        <div style="width: {{ $overview['split']['planned'] / $overview['split']['total'] * 100 }}%; background: var(--info-600);"></div>
                        <div style="width: {{ $overview['split']['extra'] / $overview['split']['total'] * 100 }}%; background: var(--gray-500);"></div>
                    </div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('kokpit.signal.dashboard.streak')">
            <div style="font-size: 1.5rem; font-weight: 600;">{{ trans_choice('kokpit.signal.dashboard.streak_days', $overview['streak'], ['count' => $overview['streak']]) }}</div>
            @if ($overview['streak'] === 0)
                <p style="margin: 0.25rem 0 0; font-size: 0.875rem; opacity: 0.7;">{{ __('kokpit.signal.dashboard.streak_hint') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('kokpit.signal.dashboard.recaps')">
            <x-slot name="afterHeader">
                <x-filament::link :href="\App\Filament\Pages\Signal\SignalReflectionPage::getUrl()" size="sm">{{ __('kokpit.signal.dashboard.all_recaps') }}</x-filament::link>
            </x-slot>

            @forelse ($overview['recaps'] as $recap)
                <a wire:key="recap-{{ $recap['week'] }}" href="{{ \App\Filament\Pages\Signal\SignalWeekPage::getUrl(['week' => $recap['week']]) }}" style="display: block; padding: 0.25rem 0;">
                    <span style="font-size: 0.75rem; font-weight: 600; opacity: 0.7;">{{ __('kokpit.signal.dashboard.week_of', ['date' => $recap['label']]) }}</span>
                    @if ($recap['text'] !== '')
                        <span style="display: block; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">{{ $recap['text'] }}</span>
                    @endif
                </a>
            @empty
                <p style="margin: 0; opacity: 0.7;">{{ __('kokpit.signal.dashboard.no_recaps') }}</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
