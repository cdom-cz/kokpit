{{-- The comments of a task one under another; bodies pass through RichText::render. No avatar by design. --}}
{{-- Files are listed by name and size. A button calls the component with the document id and the signed link is minted at click time (D-10); the one exception is the new-tab link of a PDF. --}}
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

                            @if (\App\Domain\Documents\Support\DocumentDisposition::isInlineImage($document))
                                {{-- An image opens in the single-image lightbox (D-08); its link is minted when the modal renders. --}}
                                <x-filament::button color="gray" size="xs" wire:click="mountAction('previewCommentImage', { document: '{{ $document->id }}' })">
                                    {{ __('documents.comments.preview') }}
                                </x-filament::button>
                            @elseif (isset($previewUrls[$document->id]))
                                {{-- A PDF opens in a new tab from a real link bound to the viewer (the one link this thread renders). --}}
                                <x-filament::button tag="a" href="{{ $previewUrls[$document->id] }}" target="_blank" rel="noopener noreferrer" color="gray" size="xs">
                                    {{ __('documents.comments.preview') }}
                                </x-filament::button>
                            @endif

                            {{-- Shown only where the policy grants the delete (the Admin always, a Partner for an own file of an open task). --}}
                            @can('delete', $document)
                                <x-filament::button color="danger" size="xs" wire:click="mountAction('deleteCommentDocument', { document: '{{ $document->id }}' })">
                                    {{ __('documents.comments.delete') }}
                                </x-filament::button>
                            @endcan
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

{{-- The confirmation dialog of the delete action (the component is not a page, so it renders its own modals). --}}
<x-filament-actions::modals />
