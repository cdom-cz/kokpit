{{-- The comments of a task one under another; bodies pass through RichText::render. No avatar by design. --}}
{{-- Files are listed by name and size only: a button calls the component with the document id and the signed link is minted at click time (D-10). --}}
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

            @if ($comment->documents->isNotEmpty())
                <ul style="display:flex;flex-direction:column;gap:0.25rem;margin-top:0.75rem;list-style:none;padding:0;">
                    @foreach ($comment->documents as $document)
                        <li wire:key="task-comment-document-{{ $document->id }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;">
                            <span>{{ $document->name }}</span>
                            <span>{{ \App\Filament\Support\DocumentColumns::humanSize($document->size_bytes) }}</span>

                            <x-filament::button color="gray" size="xs" wire:click="downloadCommentDocument('{{ $document->id }}')">
                                {{ __('documents.comments.download') }}
                            </x-filament::button>

                            @if (\App\Domain\Documents\Support\DocumentDisposition::isInline($document))
                                <x-filament::button color="gray" size="xs" wire:click="previewCommentDocument('{{ $document->id }}')">
                                    {{ __('documents.comments.preview') }}
                                </x-filament::button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @empty
        <x-filament::section compact>
            <strong>{{ $emptyHeading }}</strong>
            <p>{{ $emptyDescription }}</p>
        </x-filament::section>
    @endforelse
</div>
