<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

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

        return $owner->comments()->with('author')->orderBy('created_at')->orderBy('id')->get();
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
     * Writes the comment through the Action. Only the body (and, for the Admin, the
     * internal flag) is read from the form state: a forged flag never reaches the
     * Action for a Partner.
     */
    public function addComment(): void
    {
        $data = $this->commentForm->getState();

        $owner = $this->getOwnerRecord();
        assert($owner instanceof Task);

        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            app(AddTaskComment::class)->handle(
                $actor,
                $owner,
                (string) ($data['body'] ?? ''),
                $this->acceptsInternalFlag() && (bool) ($data['is_internal'] ?? false),
            );
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
}
