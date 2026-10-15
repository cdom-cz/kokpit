@php
    use App\Domain\Projects\Enums\ProjectStatus;
    use App\Filament\Partner\Resources\PartnerTaskResource;
    use App\Providers\LocalisationServiceProvider;
    use Filament\Support\Icons\Heroicon;

    // The highlight of a task waiting for the Partner is written as inline style: the build has no
    // utility class that can carry it (UI-SPEC U-11, F-2).
    $baseStyle = 'display: grid; gap: 0.5rem; padding: 1rem; border: 1px solid color-mix(in srgb, var(--gray-500) 25%, transparent); border-radius: 0.5rem;';
    $reviewStyle = 'border-left: 4px solid var(--primary-600); background: color-mix(in srgb, var(--primary-600) 8%, transparent);';
    $mutedStyle = 'color: var(--gray-500); font-size: 0.875rem;';
@endphp

<x-filament-widgets::widget>
    <x-dashboard-card
        :heading="$this->cardHeading()"
        :description="__('kokpit.dashboard.partner_tasks.description')"
        :summary="$this->collapsedSummary()"
        :collapsed="$this->collapsed"
    >
        <x-slot name="afterHeader">
            @if ($reviewCount > 0)
                <x-filament::badge color="primary">
                    {{ __('kokpit.dashboard.partner_tasks.review_count', ['count' => $reviewCount]) }}
                </x-filament::badge>
            @endif
        </x-slot>

        @if ($tasks->isEmpty())
            <x-filament::empty-state
                :heading="__('kokpit.dashboard.partner_tasks.empty_heading')"
                :description="__('kokpit.dashboard.partner_tasks.empty_description')"
                :icon="Heroicon::OutlinedClipboardDocumentList"
                :contained="false"
                compact
            />
        @else
            <ul style="display: grid; gap: 0.5rem; margin: 0; padding: 0; list-style: none;">
                @foreach ($tasks as $task)
                    @php
                        $inReview = $task->status === ProjectStatus::InReview;
                        $url = PartnerTaskResource::getUrl('view', ['record' => $task]);
                    @endphp

                    <li wire:key="partner-task-{{ $task->id }}" style="{{ $baseStyle }}{{ $inReview ? ' '.$reviewStyle : '' }}">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;">
                            <a
                                href="{{ $url }}"
                                title="{{ $task->title }}"
                                style="display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; overflow-wrap: anywhere; color: inherit;"
                            >
                                <span style="font-weight: 600;">{{ $task->reference }}</span>
                                {{ $task->title }}
                            </a>

                            <x-filament::badge
                                :color="$task->status->getColor()"
                                :icon="$inReview ? Heroicon::OutlinedEye : null"
                                style="flex-shrink: 0;"
                            >
                                {{ $task->status->getLabel() }}
                            </x-filament::badge>
                        </div>

                        <div style="display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; {{ $mutedStyle }}">
                            @if ($task->project !== null)
                                <span>{{ $task->project->name }}</span>
                            @endif

                            @if ($task->assignee !== null)
                                <span>{{ $task->assignee->name }}</span>
                            @endif

                            @if ($task->due_date !== null)
                                <span>{{ $task->due_date->format(LocalisationServiceProvider::DATE_FORMAT) }}</span>
                            @endif
                        </div>

                        @if ($inReview)
                            <div style="display: flex; justify-content: flex-end;">
                                <x-filament::button
                                    tag="a"
                                    :href="$url"
                                    size="sm"
                                    color="primary"
                                    :icon="Heroicon::OutlinedEye"
                                >
                                    {{ __('kokpit.dashboard.partner_tasks.review_button') }}
                                </x-filament::button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($openCount > $limit)
                <div style="margin-top: 1rem;">
                    <x-filament::link color="gray" :href="PartnerTaskResource::getUrl()">
                        {{ __('kokpit.dashboard.partner_tasks.all_link') }}
                    </x-filament::link>
                </div>
            @endif
        @endif
    </x-dashboard-card>
</x-filament-widgets::widget>
