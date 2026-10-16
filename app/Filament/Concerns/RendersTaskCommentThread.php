<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentDisposition;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

/**
 * The comment thread of a task page: the comments one under another, oldest first,
 * and an inline form below them (TA-04, D-08).
 *
 * It replaces the table of the relation manager. The thread is append-only: the only
 * write is addComment(), which goes through the domain Action AddTaskComment, so the
 * body is cleaned and the internal flag is decided there. The view renders bodies
 * through RichText::render, so a row written around the Action cannot inject markup.
 *
 * The using manager supplies the form fields and the texts; it decides whether the
 * internal switch exists at all (the Partner form has none).
 *
 * Files (DO-01, D-02, D-05): the form carries a separate `files` field, never an editor attachment (the
 * editors keep `fileAttachments(false)`, D-22). addComment() saves the comment and its files in one
 * transaction through AddTaskComment and AttachDocuments, so one cannot exist without the other. The files
 * are listed under their comment; the thread never renders a link. A button calls a method of this
 * component with the document id, which re-reads the document through the scoped query restricted to the
 * comments of this task (so a forged id of another task, another client or an internal comment is a 404),
 * authorizes it and mints the signed link only at click time (D-10).
 *
 * @property-read Schema $commentForm The form schema Filament's InteractsWithSchemas resolves from commentForm().
 */
trait RendersTaskCommentThread
{
    /**
     * The state of the inline form.
     *
     * @var array<string, mixed>|null
     */
    public ?array $commentData = [];

    /**
     * The form fields of the inline comment form.
     *
     * @return array<int, Component>
     */
    abstract protected function commentFields(): array;

    /**
     * Whether the actor may flag a new comment as internal; only the Admin form offers it.
     */
    abstract protected function acceptsInternalFlag(): bool;

    abstract protected function emptyHeading(): string;

    abstract protected function emptyDescription(): string;

    /**
     * The tab is on the read-only task page, but the actor comments there.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Livewire's trait mount hook: the inline form starts empty.
     */
    public function mountRendersTaskCommentThread(): void
    {
        $this->commentForm->fill();
    }

    /**
     * The comments of the task, oldest first, with their authors. The relation goes
     * through the Partner-scoped model, so a Partner never gets an internal row.
     *
     * @return Collection<int, TaskComment>
     */
    public function comments(): Collection
    {
        $owner = $this->getOwnerRecord();
        assert($owner instanceof Task);

        // The files come through the scoped `documents` relation: a Partner's query never loads a hidden
        // file, and an internal comment is not part of the Partner's comments at all.
        return $owner->comments()
            ->with([
                'author',
                'documents' => static fn ($files) => $files->orderBy('documents.created_at')->orderBy('documents.id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.tasks.comment-thread')
                ->viewData(fn (): array => [
                    'comments' => $this->comments(),
                    'emptyHeading' => $this->emptyHeading(),
                    'emptyDescription' => $this->emptyDescription(),
                    'showInternal' => $this->acceptsInternalFlag(),
                ]),
            Form::make([EmbeddedSchema::make('commentForm')])
                ->livewireSubmitHandler('addComment')
                ->footer([
                    Actions::make([
                        Action::make('addComment')
                            ->label(__('kokpit.tasks.comments.actions.create_submit'))
                            ->submit('addComment'),
                    ]),
                ]),
        ]);
    }

    public function commentForm(Schema $schema): Schema
    {
        return $schema
            ->components($this->commentFields())
            ->statePath('commentData');
    }

    /**
     * Writes the comment through the Action, and its files through AttachDocuments, in one transaction.
     * Only the body, the files (as Livewire temporary uploads) and, for the Admin, the internal flag are
     * read from the form state: a forged flag never reaches the Action for a Partner, and the visibility
     * of a file is decided by AttachDocuments (an internal comment's files are stored hidden).
     */
    public function addComment(): void
    {
        $data = $this->commentForm->getState();

        $owner = $this->getOwnerRecord();
        assert($owner instanceof Task);

        $actor = auth()->user();
        assert($actor instanceof User);

        $files = array_values(array_filter(
            is_array($data['files'] ?? null) ? $data['files'] : [],
            static fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
        ));

        try {
            DB::transaction(function () use ($actor, $owner, $data, $files): void {
                $comment = app(AddTaskComment::class)->handle(
                    $actor,
                    $owner,
                    (string) ($data['body'] ?? ''),
                    $this->acceptsInternalFlag() && (bool) ($data['is_internal'] ?? false),
                );

                if ($files === []) {
                    return;
                }

                try {
                    app(AttachDocuments::class)->handle($actor, $comment, $files);
                } catch (AuthorizationException) {
                    throw ValidationException::withMessages(['files' => __('documents.errors.partner_not_allowed')]);
                }
            });
        } catch (ValidationException $e) {
            $mapped = [];

            foreach ($e->errors() as $key => $messages) {
                $path = "commentData.{$key}";
                $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($mapped);
        }

        $this->commentForm->fill();
    }

    /**
     * Redirects to the signed download of a file of a comment of this task. The id is client-controlled:
     * it must be a UUID of a file of this task's comments that the actor may view, or the answer is 404.
     */
    public function downloadCommentDocument(string $documentId): void
    {
        $document = $this->commentDocument($documentId);
        $actor = $this->commentActor();

        Gate::forUser($actor)->authorize('view', $document);

        $this->redirect(DocumentUrl::download($document, $actor));
    }

    /**
     * Redirects to the signed inline preview; only a type on the inline allowlist has one.
     */
    public function previewCommentDocument(string $documentId): void
    {
        $document = $this->commentDocument($documentId);
        $actor = $this->commentActor();

        Gate::forUser($actor)->authorize('view', $document);
        abort_unless(DocumentDisposition::isInline($document), 404);

        $this->redirect(DocumentUrl::preview($document, $actor));
    }

    /**
     * Deletes a file of a comment after a confirmation (D-09, D-19). The comment stays (comments are
     * append-only). DeleteDocument checks the `delete` ability again, so a forged call is refused: the
     * Admin may delete any file, a Partner only an own upload while the task is open.
     */
    public function deleteCommentDocumentAction(): Action
    {
        return Action::make('deleteCommentDocument')
            ->label(__('documents.comments.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('documents.comments.delete_heading'))
            ->modalDescription(fn (array $arguments): string => __('documents.comments.delete_description', [
                'name' => $this->findCommentDocument(is_string($arguments['document'] ?? null) ? $arguments['document'] : '')->name ?? '',
            ]))
            ->modalSubmitActionLabel(__('documents.comments.delete'))
            ->action(function (array $arguments): void {
                $document = $this->commentDocument(is_string($arguments['document'] ?? null) ? $arguments['document'] : '');

                try {
                    app(DeleteDocument::class)->handle($this->commentActor(), $document);
                } catch (AuthorizationException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    report(new RuntimeException('A comment file could not be deleted ('.$e::class.').', previous: $e));

                    Notification::make()->danger()->title(__('documents.notifications.delete_failed'))->send();

                    return;
                }

                Notification::make()->success()->title(__('documents.notifications.deleted'))->send();
            });
    }

    /**
     * The file of a comment of this task, or 404. The lookup is the scoped Document query restricted to the
     * comments of this task (also scoped), so another task's file, another client's file, a hidden file and a
     * file of an internal comment all resolve to nothing for a Partner, exactly like an unknown id.
     */
    private function commentDocument(string $documentId): Document
    {
        return $this->findCommentDocument($documentId) ?? abort(404);
    }

    private function findCommentDocument(string $documentId): ?Document
    {
        if (! Str::isUuid($documentId)) {
            return null;
        }

        $owner = $this->getOwnerRecord();
        assert($owner instanceof Task);

        return Document::query()
            ->whereKey($documentId)
            ->where('documents.owner_type', DocumentOwnerType::TaskComment->value)
            ->whereIn('documents.owner_id', TaskComment::query()->where('task_comments.task_id', $owner->getKey())->select('task_comments.id'))
            ->first();
    }

    private function commentActor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }
}
