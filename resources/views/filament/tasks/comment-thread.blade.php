{{-- The comments of a task one under another; bodies pass through RichText::render. No avatar by design. --}}
<div style="display:flex;flex-direction:column;gap:0.75rem;">
    @forelse ($comments as $comment)
        <x-filament::section compact wire:key="task-comment-{{ $comment->id }}">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                <strong>{{ $comment->author?->name }}</strong>
                <span>{{ $comment->created_at?->isoFormat('L LT') }}</span>

                @if ($showInternal && $comment->is_internal)
                    <x-filament::badge color="warning">{{ __('kokpit.tasks.comments.badges.internal') }}</x-filament::badge>
                @endif

                @if ($comment->is_escalation)
                    <x-filament::badge color="danger">{{ __('kokpit.tasks.comments.badges.escalation') }}</x-filament::badge>
                @endif
            </div>

            <div class="fi-prose">{{ \App\Domain\Shared\Text\RichText::render($comment->body) }}</div>
        </x-filament::section>
    @empty
        <x-filament::section compact>
            <strong>{{ $emptyHeading }}</strong>
            <p>{{ $emptyDescription }}</p>
        </x-filament::section>
    @endforelse
</div>
