<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Notifications\PartnerUploadedFilesNotification;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * Files on the comments of a task, for the Admin and for a Partner (DO-01, D-02, D-04, D-05, D-20): a separate
 * upload field in the comment form, the comment and its files saved together or not at all, the files listed
 * under each comment with a checked download. Every name and content is fictional.
 */

/**
 * A client-visible project of the client, written as system through the domain Action.
 */
function caProject(string $clientId): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
}

/**
 * A task of the project created by the actor, written as system.
 */
function caTask(User $actor, Project $project): Task
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): Task => app(CreateTask::class)->handle($actor, $project, ['title' => Canary::canary('task')]),
    );
}

/**
 * A fictional upload that Livewire's test upload can send.
 */
function caUpload(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, implode(' ', ['Fictional', 'file', 'content', Str::random(12)]));
}

/**
 * Puts the files into the comment form one at a time, like the browser on the private bucket does.
 *
 * @param  list<UploadedFile>  $files
 */
function caAttach(Testable $thread, array $files): Testable
{
    foreach ($files as $file) {
        $thread->upload('commentData.files.'.Str::uuid()->toString(), [$file]);
    }

    return $thread;
}

/**
 * The comment thread of the Admin's task page.
 */
function caAdminThread(Task $task): Testable
{
    return Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class]);
}

/**
 * The comment thread of the Partner's task page; the task is read through the scoped query, like the page does.
 */
function caPartnerThread(User $partner, Task $task): Testable
{
    test()->actingAs($partner);

    $seen = Task::query()->where('reference', $task->reference)->firstOrFail();

    return Livewire::test(PartnerTaskCommentsRelationManager::class, ['ownerRecord' => $seen, 'pageClass' => ViewPartnerTask::class]);
}

/**
 * Every document, read as system.
 *
 * @return Collection<int, Document>
 */
function caDocuments(): Collection
{
    return app(PartnerContext::class)->runAsSystem(static fn () => Document::query()->orderBy('name')->get());
}

/**
 * The number of comments, read as system.
 */
function caCommentCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => TaskComment::query()->count());
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partner = Canary::partnerFor($this->clientA);
    $this->project = caProject($this->clientA);
    $this->task = caTask($this->admin, $this->project);
});

it('saves the Admin comment with its file, lists the file under the comment and hands out a signed download', function (): void {
    $this->actingAs($this->admin);
    $name = implode('-', ['spec', 'example']).'.txt';

    $thread = caAdminThread($this->task)->set('commentData.body', '<p>Example remark with a file</p>');

    caAttach($thread, [caUpload($name)])
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = app(PartnerContext::class)->runAsSystem(static fn (): TaskComment => TaskComment::query()->firstOrFail());
    $documents = caDocuments();

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->owner_type)->toBe('task_comment')
        ->and($documents[0]->owner_id)->toBe($comment->id)
        ->and($documents[0]->visible_to_client)->toBeTrue()
        ->and($documents[0]->uploaded_by_id)->toBe($this->admin->id);

    // The file name is under the comment, and the page never carries a signed link.
    $html = $thread->html();

    expect($html)->toContain($name)
        ->and($html)->not->toContain('signature=');

    // The link is minted at click time and the answer is a redirect to the signed file route.
    $thread->call('downloadCommentDocument', $documents[0]->id)
        ->assertRedirectContains('signature=')
        ->assertRedirectContains($documents[0]->id);
});

it('saves neither the comment nor the file when the media write fails', function (): void {
    $this->actingAs($this->admin);

    Media::creating(static function (): void {
        throw new RuntimeException('forced media failure');
    });

    $thread = caAdminThread($this->task)->set('commentData.body', '<p>Example remark that must not stay</p>');

    caAttach($thread, [caUpload('lost-example.txt')]);

    expect(fn () => $thread->call('addComment'))->toThrow(RuntimeException::class, 'forced media failure');

    expect(caCommentCount())->toBe(0)
        ->and(caDocuments())->toHaveCount(0)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Media::query()->count()))->toBe(0);
});

it('saves a comment without files as before', function (): void {
    $this->actingAs($this->admin);

    caAdminThread($this->task)
        ->set('commentData.body', '<p>Example plain remark</p>')
        ->call('addComment')
        ->assertHasNoErrors();

    expect(caCommentCount())->toBe(1)->and(caDocuments())->toHaveCount(0);
});

/**
 * The upload bells of the user, read as system.
 *
 * @return list<DatabaseNotification>
 */
function caBells(User $user): array
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->where('type', PartnerUploadedFilesNotification::class)
        ->orderBy('created_at')
        ->get()
        ->all();
}

/**
 * A comment of the author on the task, written through the domain Action, with one file stored through the
 * upload Action (visible or hidden by the owner's own rule).
 *
 * @return array{0: TaskComment, 1: Document}
 */
function caCommentWithFile(User $author, Task $task, string $fileName, bool $internal = false): array
{
    test()->actingAs($author);

    $comment = app(AddTaskComment::class)->handle($author, $task, '<p>Example remark</p>', $internal);
    $document = app(AttachDocuments::class)->handle($author, $comment, [DocumentFixtures::upload($fileName)])->firstOrFail();

    return [$comment, $document];
}

it('lets a Partner comment with a file: not internal, visible, uploaded by the Partner, one bell for the Admin', function (): void {
    $task = caTask($this->partner, $this->project);
    $name = implode('-', ['partner', 'example']).'.txt';

    $thread = caPartnerThread($this->partner, $task)->set('commentData.body', '<p>Example remark from the client</p>');

    caAttach($thread, [caUpload($name)])
        ->call('addComment')
        ->assertHasNoErrors();

    $comment = app(PartnerContext::class)->runAsSystem(static fn (): TaskComment => TaskComment::query()->firstOrFail());
    $documents = caDocuments();

    expect($comment->is_internal)->toBeFalse()
        ->and($comment->author_id)->toBe($this->partner->id)
        ->and($documents)->toHaveCount(1)
        ->and($documents[0]->owner_id)->toBe($comment->id)
        ->and($documents[0]->visible_to_client)->toBeTrue()
        ->and($documents[0]->uploaded_by_id)->toBe($this->partner->id);

    // The Partner sees the file under the own comment and can fetch it.
    expect($thread->html())->toContain($name);
    $thread->call('downloadCommentDocument', $documents[0]->id)->assertRedirectContains('signature=');

    // One upload bell with the count, besides whatever the comment itself announced.
    $bells = caBells($this->admin);

    expect($bells)->toHaveCount(1)
        ->and(json_encode($bells[0]->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->toContain($task->reference)->not->toContain($name)
        ->and(caBells($this->partner))->toBe([]);
});

it('stores the file of an internal Admin comment hidden and shows a Partner neither the comment nor the file', function (): void {
    $task = caTask($this->partner, $this->project);
    $this->actingAs($this->admin);
    $secret = implode('-', ['internal', 'example']).'.txt';
    $open = implode('-', ['shared', 'example']).'.txt';

    $thread = caAdminThread($task)
        ->set('commentData.body', '<p>Example internal remark</p>')
        ->set('commentData.is_internal', true);

    caAttach($thread, [caUpload($secret)])->call('addComment')->assertHasNoErrors();

    $thread->set('commentData.body', '<p>Example shared remark</p>')->set('commentData.is_internal', false);
    caAttach($thread, [caUpload($open)])->call('addComment')->assertHasNoErrors();

    $documents = caDocuments()->keyBy('name');

    expect($documents)->toHaveCount(2)
        ->and($documents[$secret]->visible_to_client)->toBeFalse()
        ->and($documents[$open]->visible_to_client)->toBeTrue();

    // The Admin sees both files and the internal badge.
    $adminHtml = caAdminThread($task)->html();

    expect($adminHtml)->toContain($secret)->toContain($open)->toContain(__('kokpit.tasks.comments.badges.internal'));

    // The Partner sees the shared one only: neither the internal comment nor its file name is anywhere.
    $partnerThread = caPartnerThread($this->partner, $task);

    expect($partnerThread->html())->toContain($open)->not->toContain($secret)->not->toContain('internal remark');

    // A refusal ends the component's round trip, so every call gets its own thread.
    caPartnerThread($this->partner, $task)->call('downloadCommentDocument', $documents[$secret]->id)->assertNotFound();
    caPartnerThread($this->partner, $task)->mountAction('previewCommentImage', ['document' => $documents[$secret]->id])->assertNotFound();
});

it('answers 404 to a Partner for a visible file of another task, of another client and for a malformed id', function (): void {
    $task = caTask($this->partner, $this->project);
    $otherTask = caTask($this->admin, $this->project);
    $projectB = caProject($this->clientB);
    $taskB = caTask($this->admin, $projectB);

    [, $ownTaskFile] = caCommentWithFile($this->admin, $task, 'mine-example.txt');
    [, $otherTaskFile] = caCommentWithFile($this->admin, $otherTask, 'other-task-example.txt');
    [, $otherClientFile] = caCommentWithFile($this->admin, $taskB, 'other-client-example.txt');

    expect($otherTaskFile->visible_to_client)->toBeTrue()->and($otherClientFile->visible_to_client)->toBeTrue();

    // The control: the own task's file works, so the 404s below are about the id, not the setup.
    caPartnerThread($this->partner, $task)->call('downloadCommentDocument', $ownTaskFile->id)->assertRedirectContains('signature=');

    // A refusal ends the component's round trip, so every call gets its own thread.
    foreach ([$otherTaskFile->id, $otherClientFile->id, 'not-a-uuid', '../../etc/passwd', Str::uuid()->toString()] as $forged) {
        caPartnerThread($this->partner, $task)->call('downloadCommentDocument', $forged)->assertNotFound();
        caPartnerThread($this->partner, $task)->mountAction('previewCommentImage', ['document' => $forged])->assertNotFound();
    }
});

/**
 * A comment of the author on the task with one file of the given bytes (so its stored type is detected from them).
 *
 * @return array{0: TaskComment, 1: Document}
 */
function caCommentWithBytes(User $author, Task $task, string $fileName, string $content, bool $internal = false): array
{
    test()->actingAs($author);

    $comment = app(AddTaskComment::class)->handle($author, $task, '<p>Example remark</p>', $internal);
    $document = app(AttachDocuments::class)->handle($author, $comment, [DocumentFixtures::upload($fileName, $content)])->firstOrFail();

    return [$comment, $document];
}

function caPngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
}

function caPdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

/**
 * The query of the link a rendered thread holds for the document, or null when it holds none.
 *
 * @return array<string, string>|null
 */
function caLinkQuery(string $html, string $documentId): ?array
{
    if (preg_match('/(?:href|src)="([^"]*\/files\/documents\/'.preg_quote($documentId, '/').'[^"]*)"/', $html, $match) !== 1) {
        return null;
    }

    parse_str((string) parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

it('opens a comment PNG in the lightbox minted for the Admin and gives a comment PDF a new-tab link, a TXT nothing', function (): void {
    $task = caTask($this->admin, $this->project);
    [, $png] = caCommentWithBytes($this->admin, $task, 'picture-example.png', caPngBytes());
    [, $pdf] = caCommentWithBytes($this->admin, $task, 'paper-example.pdf', caPdfBytes());
    [, $txt] = caCommentWithBytes($this->admin, $task, 'note-example.txt', 'Fictional note');

    $thread = caAdminThread($task);
    $html = $thread->html();

    // The PNG has a button that mounts the lightbox and no link; the PDF has the only signed link; the TXT has no control.
    expect($html)->toContain("mountAction('previewCommentImage', { document: '{$png->id}' })")
        ->and(caLinkQuery($html, $png->id))->toBeNull()
        ->and(substr_count($html, 'signature='))->toBe(1)
        ->and($html)->toContain('target="_blank"')
        ->and(caLinkQuery($html, $pdf->id))->toMatchArray(['mode' => 'inline', 'u' => $this->admin->id])
        ->and(caLinkQuery($html, $txt->id))->toBeNull()
        ->and($html)->not->toContain("previewCommentImage', { document: '{$txt->id}'")
        ->and($html)->not->toContain("previewCommentImage', { document: '{$pdf->id}'");

    $thread->mountAction('previewCommentImage', ['document' => $png->id]);

    $mounted = $thread->instance()->getMountedAction();

    expect((string) $mounted?->getModalHeading())->toContain('picture-example.png')
        ->and($mounted?->getModalSubmitAction())->toBeFalsy()
        ->and(caLinkQuery($thread->html(), $png->id))->toMatchArray(['mode' => 'inline', 'u' => $this->admin->id]);
});

it('gives a Partner the lightbox and the new-tab link only for visible files of the own task', function (): void {
    $task = caTask($this->partner, $this->project);
    [, $png] = caCommentWithBytes($this->admin, $task, 'picture-example.png', caPngBytes());
    [, $pdf] = caCommentWithBytes($this->admin, $task, 'paper-example.pdf', caPdfBytes());
    [, $secretPdf] = caCommentWithBytes($this->admin, $task, 'internal-paper-example.pdf', caPdfBytes(), internal: true);
    [, $hiddenPdf] = caCommentWithBytes($this->admin, $task, 'hidden-paper-example.pdf', caPdfBytes());
    app(PartnerContext::class)->runAsSystem(static function () use ($hiddenPdf): void {
        Document::query()->whereKey($hiddenPdf->id)->update(['visible_to_client' => false]);
    });

    $thread = caPartnerThread($this->partner, $task);
    $html = $thread->html();

    expect($html)->toContain("mountAction('previewCommentImage', { document: '{$png->id}' })")
        ->and(caLinkQuery($html, $pdf->id))->toMatchArray(['mode' => 'inline', 'u' => $this->partner->id])
        ->and(substr_count($html, 'signature='))->toBe(1)
        ->and($html)->not->toContain($secretPdf->id)
        ->and($html)->not->toContain($hiddenPdf->id)
        ->and($html)->not->toContain('internal-paper-example.pdf')
        ->and($html)->not->toContain('hidden-paper-example.pdf');

    $thread->mountAction('previewCommentImage', ['document' => $png->id]);

    expect(caLinkQuery($thread->html(), $png->id))->toMatchArray(['mode' => 'inline', 'u' => $this->partner->id]);

    // The hidden and the internal file answer 404 even for a type that would qualify.
    foreach ([$secretPdf->id, $hiddenPdf->id] as $forged) {
        caPartnerThread($this->partner, $task)->mountAction('previewCommentImage', ['document' => $forged])->assertNotFound();
    }
});

it('answers 404 to the lightbox for a document that is not an image', function (): void {
    $task = caTask($this->admin, $this->project);
    [, $pdf] = caCommentWithBytes($this->admin, $task, 'paper-example.pdf', caPdfBytes());
    [, $txt] = caCommentWithBytes($this->admin, $task, 'note-example.txt', 'Fictional note');

    $this->actingAs($this->admin);

    foreach ([$pdf->id, $txt->id] as $notImage) {
        caAdminThread($task)->mountAction('previewCommentImage', ['document' => $notImage])->assertNotFound();
    }
});

it('keeps the file fields off the escalation and description editors of the Partner task page', function (): void {
    $task = caTask($this->partner, $this->project);
    $this->actingAs($this->partner);

    foreach (['escalate' => 'comment', 'editDescription' => 'description'] as $action => $editor) {
        Livewire::test(ViewPartnerTask::class, ['record' => $task->reference])
            ->mountAction($action)
            ->assertSchemaComponentExists($editor, 'mountedActionSchema0')
            ->assertSchemaComponentDoesNotExist('files', 'mountedActionSchema0');
    }
});

/**
 * The media rows of a document, read as system because Media is closed to Partners.
 *
 * @return list<string>
 */
function caMedia(string $documentId): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => Media::query()->where('model_id', $documentId)->pluck('id')->map(strval(...))->all(),
    );
}

/**
 * The delete button the thread shows for a file; the thread renders it as a plain mount of the confirmation action.
 */
function caDeleteButton(string $documentId): string
{
    return "mountAction('deleteCommentDocument', { document: '{$documentId}' })";
}

it('lets the Admin delete a comment file of a Partner after the confirmation, object included, and keeps the comment', function (): void {
    $task = caTask($this->partner, $this->project);
    [$comment, $document] = caCommentWithFile($this->partner, $task, 'to-remove-example.txt');

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    $this->actingAs($this->admin);
    $thread = caAdminThread($task);

    expect($thread->html())->toContain(caDeleteButton($document->id));

    $thread->mountAction('deleteCommentDocument', ['document' => $document->id]);

    // The dialog is rendered by the thread's own modal outlet (the component is not a page) and names the file.
    $thread->assertMountedActionModalSee([__('documents.comments.delete_heading'), 'to-remove-example.txt']);

    // Opening the dialog deletes nothing, and the dialog names the file.
    expect((string) $thread->instance()->getMountedAction()?->getModalDescription())->toBe(__('documents.comments.delete_description', ['name' => 'to-remove-example.txt']))
        ->and((string) $thread->instance()->getMountedAction()?->getModalHeading())->toBe(__('documents.comments.delete_heading'))
        ->and($thread->instance()->getMountedAction()?->isConfirmationRequired())->toBeTrue()
        ->and(caDocuments())->toHaveCount(1);

    $thread->callMountedAction();

    expect(caDocuments())->toHaveCount(0)
        ->and(caMedia($document->id))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): bool => TaskComment::query()->whereKey($comment->id)->exists()))->toBeTrue();

    $thread->assertNotified(__('documents.notifications.deleted'));
});

it('lets a Partner delete only an own comment file of an open task, with the button, and refuses a forged call', function (): void {
    $task = caTask($this->partner, $this->project);
    $other = Canary::partnerFor($this->clientA);

    [, $mine] = caCommentWithFile($this->partner, $task, 'mine-example.txt');
    [, $fromAdmin] = caCommentWithFile($this->admin, $task, 'admin-example.txt');
    [, $fromOther] = caCommentWithFile($this->admin, $task, 'other-partner-example.txt');
    app(PartnerContext::class)->runAsSystem(static function () use ($fromOther, $other): void {
        Document::query()->whereKey($fromOther->id)->firstOrFail()->forceFill(['uploaded_by_id' => $other->id])->save();
    });

    $html = caPartnerThread($this->partner, $task)->html();

    expect($html)->toContain(caDeleteButton($mine->id))
        ->not->toContain(caDeleteButton($fromAdmin->id))
        ->not->toContain(caDeleteButton($fromOther->id));

    // A forged call for a file the Partner may read but not delete is refused and deletes nothing.
    foreach ([$fromAdmin, $fromOther] as $foreign) {
        caPartnerThread($this->partner, $task)
            ->mountAction('deleteCommentDocument', ['document' => $foreign->id])
            ->callMountedAction()
            ->assertForbidden();
    }

    expect(caDocuments())->toHaveCount(3);

    // The own file goes after the confirmation.
    $thread = caPartnerThread($this->partner, $task)->mountAction('deleteCommentDocument', ['document' => $mine->id]);

    expect(caDocuments())->toHaveCount(3);

    $thread->callMountedAction();

    expect(caDocuments()->pluck('id')->all())->not->toContain($mine->id)
        ->and(caDocuments())->toHaveCount(2)
        ->and(caMedia($mine->id))->toBe([]);
});

it('takes the delete button away from the Partner once the task is done, and keeps it for the Admin', function (): void {
    $task = caTask($this->partner, $this->project);
    [, $document] = caCommentWithFile($this->partner, $task, 'finished-example.txt');

    expect(caPartnerThread($this->partner, $task)->html())->toContain(caDeleteButton($document->id));

    $this->actingAs($this->admin);
    app(UpdateTask::class)->handle($this->admin, $task, ['status' => ProjectStatus::Done->value]);

    expect(caPartnerThread($this->partner, $task)->html())->not->toContain(caDeleteButton($document->id));

    caPartnerThread($this->partner, $task)
        ->mountAction('deleteCommentDocument', ['document' => $document->id])
        ->callMountedAction()
        ->assertForbidden();

    expect(caDocuments())->toHaveCount(1);

    $this->actingAs($this->admin);
    expect(caAdminThread($task)->html())->toContain(caDeleteButton($document->id));
});

it('answers 404 to a forged delete call with a file of another task or another client, and deletes nothing', function (): void {
    $task = caTask($this->partner, $this->project);
    $otherTask = caTask($this->admin, $this->project);
    $taskB = caTask($this->admin, caProject($this->clientB));

    [, $otherTaskFile] = caCommentWithFile($this->admin, $otherTask, 'other-task-example.txt');
    [, $otherClientFile] = caCommentWithFile($this->admin, $taskB, 'other-client-example.txt');

    foreach ([$otherTaskFile->id, $otherClientFile->id, 'not-a-uuid'] as $forged) {
        caPartnerThread($this->partner, $task)
            ->mountAction('deleteCommentDocument', ['document' => $forged])
            ->callMountedAction()
            ->assertNotFound();
    }

    $this->actingAs($this->admin);

    caAdminThread($task)
        ->mountAction('deleteCommentDocument', ['document' => $otherTaskFile->id])
        ->callMountedAction()
        ->assertNotFound();

    expect(caDocuments())->toHaveCount(2);
});
