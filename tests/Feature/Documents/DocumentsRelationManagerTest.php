<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\RenameDocument;
use App\Domain\Documents\Actions\SetDocumentVisibility;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentFileName;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\RelationManagers\ClientDocumentsRelationManager;
use App\Filament\RelationManagers\ProjectDocumentsRelationManager;
use App\Filament\RelationManagers\TaskDocumentsRelationManager;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Support\DocumentColumns;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The Admin's "Soubory" tab on the project, task and client detail (DO-01, DO-03, D-02, D-03): one shared
 * behaviour, three final relation managers. Every name and content is fictional.
 */

/**
 * A client-visible project of a fresh client, written through the domain Action.
 */
function tabProject(string $key = 'TAB'): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example tab project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * Mounts the Soubory tab of a project as the Admin sees it on the detail page.
 */
function projectDocumentsTab(Project $project): Testable
{
    return Livewire::test(ProjectDocumentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class]);
}

/**
 * A fictional upload that Livewire's test upload can send (it reads the name of a fake file).
 */
function tabUpload(string $name, ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? implode(' ', ['Fictional', 'file', 'content', Str::random(12)]));
}

/**
 * Opens the upload modal and sends the files one by one, like the browser does on the private bucket
 * (Livewire refuses a list of files in one call there), then submits the modal with the rest of the data.
 *
 * @param  list<UploadedFile>  $files
 * @param  array<string, mixed>  $data
 */
function uploadThroughTab(Testable $tab, array $files, array $data = []): Testable
{
    $tab->mountAction(TestAction::make('upload')->table());

    foreach ($files as $file) {
        // Filament sends each file to its own key of the field, which is the single-file path of the S3 driver.
        $tab->upload('mountedActions.0.data.files.'.Str::uuid()->toString(), [$file]);
    }

    if ($data !== []) {
        $tab->setActionData($data);
    }

    return $tab->callMountedAction();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('lists the Soubory tab among the relations of the project resource', function (): void {
    expect(ProjectResource::getRelations())->toContain(ProjectDocumentsRelationManager::class);
});

it('stores two files with a description on the project from the upload modal, visible by default', function (): void {
    $project = tabProject();

    $tab = uploadThroughTab(projectDocumentsTab($project), [
        tabUpload('first-example.txt'),
        tabUpload('second-example.txt'),
    ], ['description' => 'Example shared notes']);

    $tab->assertHasNoFormErrors();

    $documents = Document::query()->orderBy('name')->get();

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('name')->all())->toBe(['first-example.txt', 'second-example.txt'])
        ->and($documents->every(fn (Document $d): bool => $d->owner_type === 'project' && $d->owner_id === $project->id))->toBeTrue()
        ->and($documents->every(fn (Document $d): bool => $d->visible_to_client === true && $d->description === 'Example shared notes'))->toBeTrue()
        ->and($documents->every(fn (Document $d): bool => $d->uploaded_by_id === $this->admin->id))->toBeTrue();

    projectDocumentsTab($project)
        ->assertCanSeeTableRecords($documents)
        ->assertSee('first-example.txt')
        ->assertSee('second-example.txt');
});

it('lets the Admin hide a project file at upload', function (): void {
    $project = tabProject();

    uploadThroughTab(projectDocumentsTab($project), [tabUpload('hidden-example.txt')], ['visible_to_client' => false]);

    expect(Document::query()->firstOrFail()->visible_to_client)->toBeFalse();
});

it('redirects a download click to a signed link of the checked file route for the Admin', function (): void {
    $project = tabProject();
    $document = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('download-example.txt')])
        ->firstOrFail();

    $tab = projectDocumentsTab($project)
        ->callAction(TestAction::make('download')->table($document));

    $url = data_get($tab->effects, 'redirect');

    expect($url)->toBeString()
        ->and($url)->toContain(route('filament.admin.files.document', ['document' => $document->id], false));

    $request = Request::create($url);

    expect(URL::hasValidSignature($request))->toBeTrue();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['u'])->toBe($this->admin->id)
        ->and($query['mode'])->toBe('download');
});

it('shows a preview action only for a type the browser may render', function (): void {
    $project = tabProject();
    $text = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('plain-example.txt')])
        ->firstOrFail();
    $png = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('picture-example.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='))])
        ->firstOrFail();

    projectDocumentsTab($project)
        ->assertTableActionHidden('preview', $text)
        ->assertTableActionVisible('preview', $png);
});

it('renders no signed link and no bucket host into the tab', function (): void {
    $project = tabProject();
    app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('quiet-example.txt')]);

    $html = projectDocumentsTab($project)->html();

    $bucketHost = (string) parse_url((string) config('filesystems.disks.s3.endpoint'), PHP_URL_HOST);

    expect($html)->toContain('quiet-example.txt')
        ->and($html)->not->toContain('signature=')
        ->and($html)->not->toContain('/admin/files/documents/');

    if ($bucketHost !== '') {
        expect($html)->not->toContain($bucketHost);
    }
});

it('answers 403 when a Partner mounts the project Soubory tab', function (): void {
    $project = tabProject();
    $partner = Canary::partnerFor($project->client_id);

    $this->actingAs($partner);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => ProjectDocumentsRelationManager::canViewForRecord($project, ViewProject::class)))->toBeFalse();

    projectDocumentsTab($project)->assertForbidden();
});

it('shows a domain refusal next to the files field and stores nothing', function (): void {
    $project = tabProject();

    $tab = uploadThroughTab(projectDocumentsTab($project), [tabUpload('archive-example.phar')]);

    expect($tab->errors()->get('mountedActions.0.data.files'))->toBe([__('documents.errors.extension_not_allowed', ['name' => 'archive-example.phar'])])
        ->and(Document::query()->count())->toBe(0);
});

it('explains on the upload modal that the client will see a project file and its description', function (): void {
    $project = tabProject();

    projectDocumentsTab($project)
        ->mountAction(TestAction::make('upload')->table())
        ->assertSchemaComponentVisible('visible_to_client')
        ->assertSchemaComponentExists('visible_to_client', checkComponentUsing: static fn (Toggle $toggle): bool => str_contains((string) $toggle->getChildSchema(Toggle::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString(), e(__('documents.hints.visible_to_client')))
            && $toggle->getDefaultState() === true);
});

it('writes file sizes in binary units with a Czech decimal comma', function (): void {
    expect(DocumentColumns::humanSize(0))->toBe('0 B')
        ->and(DocumentColumns::humanSize(512))->toBe('512 B')
        ->and(DocumentColumns::humanSize(1024))->toBe('1 KB')
        ->and(DocumentColumns::humanSize(1536))->toBe('1,5 KB')
        ->and(DocumentColumns::humanSize(1572864))->toBe('1,5 MB')
        ->and(DocumentColumns::humanSize(1048575))->toBe('1 MB')
        ->and(DocumentColumns::humanSize(10 * 1048576))->toBe('10 MB')
        ->and(DocumentColumns::humanSize(3 * 1073741824))->toBe('3 GB');
});

it('names the admin columns the tab is built from', function (): void {
    $columns = array_map(static fn ($column): string => $column->getName(), DocumentColumns::admin(showOwner: false));

    expect(DocumentColumns::ADMIN_COLUMN_NAMES)->toBe(['name', 'owner', 'size_bytes', 'uploader.name', 'created_at', 'visible_to_client'])
        ->and($columns)->toBe(['name', 'size_bytes', 'uploader.name', 'created_at', 'visible_to_client']);
});

it('sorts the names of the files in Czech order', function (): void {
    $project = tabProject();

    foreach (['Chata-example.txt', 'Čočka-example.txt', 'Cihla-example.txt'] as $name) {
        app(AttachDocuments::class)->handle($this->admin, $project, [DocumentFixtures::upload($name)]);
    }

    $names = fn (Testable $tab): array => array_values(array_map(
        static fn (Document $document): string => $document->name,
        $tab->instance()->getTableRecords()->all(),
    ));

    expect($names(projectDocumentsTab($project)->sortTable('name')))->toBe(['Cihla-example.txt', 'Čočka-example.txt', 'Chata-example.txt'])
        ->and($names(projectDocumentsTab($project)->sortTable('name', 'desc')))->toBe(['Chata-example.txt', 'Čočka-example.txt', 'Cihla-example.txt']);
});

/**
 * A task of a fresh project, written through the domain Action.
 */
function tabTask(string $key = 'TSK'): Task
{
    return app(CreateTask::class)->handle(test()->admin, tabProject($key), ['title' => 'Example tab task']);
}

/**
 * Mounts the Soubory tab of a task as the Admin sees it on the detail page.
 */
function taskDocumentsTab(Task $task): Testable
{
    return Livewire::test(TaskDocumentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class]);
}

it('stores a task file with the project and client of the task, visible by default', function (): void {
    $task = tabTask();
    $project = Project::query()->findOrFail($task->project_id);

    uploadThroughTab(taskDocumentsTab($task), [tabUpload('task-example.txt')], ['description' => 'Example task notes'])
        ->assertHasNoFormErrors();

    $document = Document::query()->firstOrFail();

    expect($document->owner_type)->toBe('task')
        ->and($document->owner_id)->toBe($task->id)
        ->and($document->project_id)->toBe($project->id)
        ->and($document->client_id)->toBe($project->client_id)
        ->and($document->visible_to_client)->toBeTrue()
        ->and($document->description)->toBe('Example task notes');

    taskDocumentsTab($task)->assertCanSeeTableRecords([$document]);
});

it('stores a task file hidden when the Admin turns the switch off at upload', function (): void {
    $task = tabTask();

    uploadThroughTab(taskDocumentsTab($task), [tabUpload('hidden-task-example.txt')], ['visible_to_client' => false]);

    expect(Document::query()->firstOrFail()->visible_to_client)->toBeFalse();
});

it('lists the files of a task only on that task and not the files of the project or another task', function (): void {
    $task = tabTask();
    $other = app(CreateTask::class)->handle($this->admin, Project::query()->findOrFail($task->project_id), ['title' => 'Example other task']);
    $own = app(AttachDocuments::class)->handle($this->admin, $task, [DocumentFixtures::upload('own-task-example.txt')])->firstOrFail();
    $foreign = app(AttachDocuments::class)->handle($this->admin, $other, [DocumentFixtures::upload('other-task-example.txt')])->firstOrFail();
    $onProject = app(AttachDocuments::class)->handle($this->admin, Project::query()->findOrFail($task->project_id), [DocumentFixtures::upload('project-example.txt')])->firstOrFail();

    taskDocumentsTab($task)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign, $onProject]);
});

it('lists the files of an archived task for the Admin and refuses a new upload with the archived error', function (): void {
    $task = tabTask();
    $document = app(AttachDocuments::class)->handle($this->admin, $task, [DocumentFixtures::upload('kept-example.txt')])->firstOrFail();

    app(ArchiveTask::class)->handle($this->admin, $task);

    $archived = Task::query()->onlyTrashed()->findOrFail($task->id);

    $tab = taskDocumentsTab($archived)->assertCanSeeTableRecords([$document]);

    uploadThroughTab($tab, [tabUpload('late-example.txt')]);

    expect($tab->errors()->get('mountedActions.0.data.files'))->toBe([__('documents.errors.owner_archived')])
        ->and(Document::query()->count())->toBe(1);
});

it('answers 403 when a Partner mounts the task Soubory tab', function (): void {
    $task = tabTask();
    $this->actingAs(Canary::partnerFor(Project::query()->findOrFail($task->project_id)->client_id));

    taskDocumentsTab($task)->assertForbidden();
});

/**
 * Mounts the Soubory tab of a client as the Admin sees it on the detail page.
 */
function clientDocumentsTab(Client $client): Testable
{
    return Livewire::test(ClientDocumentsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * One fictional file on each kind of owner of a client: the client, a project, a task and a comment.
 *
 * @return array{client: Client, project: Project, task: Task, files: array<string, Document>}
 */
function clientWithFilesOfEveryOwner(string $key = 'CLD'): array
{
    $project = tabProject($key);
    $client = Client::query()->findOrFail($project->client_id);
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example client tab task']);
    $comment = app(AddTaskComment::class)->handle(test()->admin, $task, 'Example comment body');
    $attach = static fn (object $owner, string $name): Document => app(AttachDocuments::class)
        ->handle(test()->admin, $owner, [DocumentFixtures::upload($name)])
        ->firstOrFail();

    return [
        'client' => $client,
        'project' => $project,
        'task' => $task,
        'files' => [
            'client' => $attach($client, 'client-example.txt'),
            'project' => $attach($project, 'project-example.txt'),
            'task' => $attach($task, 'task-example.txt'),
            'task_comment' => $attach($comment, 'comment-example.txt'),
        ],
    ];
}

it('lists the Soubory tab among the relations of the client resource', function (): void {
    expect(ClientResource::getRelations())->toContain(ClientDocumentsRelationManager::class);
});

it('has Czech labels for the owner kinds', function (): void {
    expect(array_map(static fn (DocumentOwnerType $type): string => $type->getLabel(), DocumentOwnerType::cases()))
        ->toBe(['Klient', 'Projekt', 'Úkol', 'Komentář', 'Transakce', 'Doklad']);
});

it('lists every file of the client with the label and name of its owner', function (): void {
    $set = clientWithFilesOfEveryOwner();
    $files = $set['files'];

    clientDocumentsTab($set['client'])
        ->assertCanSeeTableRecords(array_values($files))
        ->assertTableColumnStateSet('owner', 'Klient', $files['client'])
        ->assertTableColumnStateSet('owner', 'Projekt', $files['project'])
        ->assertTableColumnStateSet('owner', 'Úkol', $files['task'])
        ->assertTableColumnStateSet('owner', 'Komentář', $files['task_comment']);

    $ownerName = static fn (Document $document): ?string => DocumentColumns::ownerName(Document::query()->with('owner')->findOrFail($document->id));

    expect($ownerName($files['client']))->toBe($set['client']->name)
        ->and($ownerName($files['project']))->toBe($set['project']->name)
        ->and($ownerName($files['task']))->toBe($set['task']->reference)
        ->and($ownerName($files['task_comment']))->toBe($set['task']->reference);
});

it('never lists a file of another client', function (): void {
    $mine = clientWithFilesOfEveryOwner('MIN');
    $theirs = clientWithFilesOfEveryOwner('THR');

    clientDocumentsTab($mine['client'])
        ->assertCanSeeTableRecords(array_values($mine['files']))
        ->assertCanNotSeeTableRecords(array_values($theirs['files']));
});

it('keeps listing the files of an archived project and task of the client with their owner', function (): void {
    $set = clientWithFilesOfEveryOwner();

    app(ArchiveTask::class)->handle($this->admin, $set['task']);
    $set['project']->delete();

    clientDocumentsTab($set['client'])
        ->assertCanSeeTableRecords(array_values($set['files']))
        ->assertTableColumnStateSet('owner', 'Projekt', $set['files']['project'])
        ->assertTableColumnStateSet('owner', 'Úkol', $set['files']['task'])
        ->assertTableColumnStateSet('owner', 'Komentář', $set['files']['task_comment']);

    $archived = DocumentColumns::withOwners(Document::query())->findOrFail($set['files']['project']->id);
    $comment = DocumentColumns::withOwners(Document::query())->findOrFail($set['files']['task_comment']->id);

    expect(DocumentColumns::ownerName($archived))->toBe($set['project']->name)
        ->and(DocumentColumns::ownerName($comment))->toBe($set['task']->reference);
});

it('loads the owners of the rows without one query per row', function (): void {
    $few = clientWithFilesOfEveryOwner('FEW');
    $queries = static function (Client $client): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $documents = DocumentColumns::withOwners(Document::query()->where('client_id', $client->id))->get();
        $documents->each(static fn (Document $document): ?string => DocumentColumns::ownerName($document));

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $baseline = $queries($few['client']);

    // Four more files on the same kinds of owner: the count of queries must not grow.
    foreach (['project', 'task'] as $kind) {
        $owner = $kind === 'project' ? $few['project'] : $few['task'];

        foreach (['more-one', 'more-two'] as $name) {
            app(AttachDocuments::class)->handle($this->admin, $owner, [DocumentFixtures::upload($name.'-'.$kind.'.txt')]);
        }
    }

    expect(Document::query()->where('client_id', $few['client']->id)->count())->toBe(8)
        ->and($queries($few['client']))->toBe($baseline);
});

it('stores an upload on the client tab as a client file that is hidden, with no visibility switch', function (): void {
    $client = Client::factory()->create();

    $tab = clientDocumentsTab($client)->mountAction(TestAction::make('upload')->table());

    $tab->assertSchemaComponentHidden('visible_to_client');

    uploadThroughTab(clientDocumentsTab($client), [tabUpload('client-upload-example.txt')], ['visible_to_client' => true])
        ->assertHasNoFormErrors();

    $document = Document::query()->firstOrFail();

    expect($document->owner_type)->toBe('client')
        ->and($document->owner_id)->toBe($client->id)
        ->and($document->client_id)->toBe($client->id)
        ->and($document->project_id)->toBeNull()
        ->and($document->visible_to_client)->toBeFalse();
});

it('answers 403 when a Partner mounts the client Soubory tab', function (): void {
    $client = Client::factory()->create();
    $this->actingAs(Canary::partnerFor($client->id));

    clientDocumentsTab($client)->assertForbidden();
});

/*
 * Rename, describe and show or hide a file for the client (DO-03, D-02, D-03, D-20).
 */

/**
 * A fictional file on an owner, written through the domain Action.
 */
function managedFile(?object $owner, string $name = 'managed-example.txt', ?bool $visible = null, ?string $description = null): Document
{
    return app(AttachDocuments::class)
        ->handle(test()->admin, $owner, [DocumentFixtures::upload($name)], $visible, $description)
        ->firstOrFail();
}

it('renames a file with a cleaned name and a new description from the table action', function (): void {
    $project = tabProject('REN');
    $document = managedFile($project, 'before-example.txt', null, 'Old example description');
    // A right-to-left override and a path separator, assembled when the test runs.
    $hostile = '../'.mb_chr(0x202E).'after/example.txt ';

    projectDocumentsTab($project)
        ->callAction(TestAction::make('rename')->table($document), ['name' => $hostile, 'description' => '  New example description  '])
        ->assertHasNoFormErrors();

    $document->refresh();

    expect($document->name)->toBe(DocumentFileName::displayName($hostile))
        ->and($document->name)->not->toContain('/')
        ->and($document->name)->not->toContain(mb_chr(0x202E))
        ->and($document->description)->toBe('New example description');
});

it('refuses a blank name under the name field and keeps the old one', function (): void {
    $project = tabProject('BLK');
    $document = managedFile($project, 'keep-example.txt');

    projectDocumentsTab($project)
        ->callAction(TestAction::make('rename')->table($document), ['name' => '   ', 'description' => null])
        ->assertHasActionErrors(['name' => 'required']);

    expect($document->refresh()->name)->toBe('keep-example.txt');

    $refusal = fn () => app(RenameDocument::class)->handle($this->admin, $document, '   ', null);

    expect($refusal)->toThrow(ValidationException::class);

    try {
        $refusal();
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['name']);
    }
});

it('clears the description when it is sent empty', function (): void {
    $project = tabProject('CLR');
    $document = managedFile($project, 'described-example.txt', null, 'Example description to clear');

    app(RenameDocument::class)->handle($this->admin, $document, 'described-example.txt', '   ');

    expect($document->refresh()->description)->toBeNull();
});

it('writes one activity row for a rename and none for a description-only change', function (): void {
    $project = tabProject('ACT');
    $document = managedFile($project, 'old-name-example.txt');

    app(RenameDocument::class)->handle($this->admin, $document, 'new-name-example.txt', 'First example description');
    app(RenameDocument::class)->handle($this->admin, $document, 'new-name-example.txt', 'Second example description');

    $rows = Activity::query()->forSubject($document)->forEvent('updated')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->attribute_changes->get('old'))->toBe(['name' => 'old-name-example.txt'])
        ->and($rows->first()?->attribute_changes->get('attributes'))->toBe(['name' => 'new-name-example.txt'])
        ->and(json_encode($rows->first()?->getAttributes()))->not->toContain('example description');
});

it('hides and shows a project file for the client and logs the flag', function (): void {
    $project = tabProject('VIS');
    $document = managedFile($project, 'shared-example.txt');

    expect($document->visible_to_client)->toBeTrue();

    $tab = projectDocumentsTab($project)->callAction(TestAction::make('toggleVisibility')->table($document));

    expect($document->refresh()->visible_to_client)->toBeFalse();

    $tab->assertNotified(__('documents.notifications.hidden'));

    projectDocumentsTab($project)->callAction(TestAction::make('toggleVisibility')->table($document))
        ->assertNotified(__('documents.notifications.shown'));

    $changes = Activity::query()->forSubject($document)->forEvent('updated')->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (Activity $row): mixed => $row->attribute_changes->get('attributes'))
        ->all();

    expect($document->refresh()->visible_to_client)->toBeTrue()
        ->and($changes)->toHaveCount(2)
        ->and(collect($changes)->pluck('visible_to_client')->sort()->values()->all())->toBe([false, true]);
});

it('labels the toggle by what it does', function (): void {
    $project = tabProject('LBL');
    $document = managedFile($project, 'label-example.txt');

    $label = fn (): string => (string) projectDocumentsTab($project)->instance()->getTable()->getAction('toggleVisibility')?->record($document->refresh())->getLabel();

    expect($label())->toBe(__('documents.actions.hide_from_client'));

    app(SetDocumentVisibility::class)->handle($this->admin, $document, false);

    expect($label())->toBe(__('documents.actions.show_to_client'));
});

it('offers the toggle only for a file the client can ever see', function (): void {
    $set = clientWithFilesOfEveryOwner('TGL');
    $internal = app(AddTaskComment::class)->handle($this->admin, $set['task'], 'Example internal note', internal: true);
    $internalFile = managedFile($internal, 'internal-example.txt');

    $tab = clientDocumentsTab($set['client']);

    $tab->assertTableActionHidden('toggleVisibility', $set['files']['client'])
        ->assertTableActionHidden('toggleVisibility', $internalFile)
        ->assertTableActionVisible('toggleVisibility', $set['files']['project'])
        ->assertTableActionVisible('toggleVisibility', $set['files']['task'])
        ->assertTableActionVisible('toggleVisibility', $set['files']['task_comment']);
});

it('refuses to share a client file, an unattached file and a file of an internal comment', function (): void {
    $set = clientWithFilesOfEveryOwner('REF');
    $internal = app(AddTaskComment::class)->handle($this->admin, $set['task'], 'Example internal note', internal: true);
    $internalFile = managedFile($internal, 'internal-example.txt');
    $unattached = managedFile(null, 'unattached-example.txt');

    foreach ([$set['files']['client'], $internalFile, $unattached] as $document) {
        try {
            app(SetDocumentVisibility::class)->handle($this->admin, $document, true);

            $this->fail('A file that cannot be shared was shared.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['visible_to_client' => [__('documents.errors.not_shareable')]]);
        }

        expect($document->refresh()->visible_to_client)->toBeFalse();
    }

    // Hiding is always allowed and changes nothing for a file that is hidden already.
    app(SetDocumentVisibility::class)->handle($this->admin, $set['files']['client'], false);

    expect($set['files']['client']->refresh()->visible_to_client)->toBeFalse();
});

it('refuses a Partner in both Actions', function (): void {
    $project = tabProject('PAR');
    $document = managedFile($project, 'partner-example.txt');
    $partner = Canary::partnerFor($project->client_id);

    expect(fn () => app(RenameDocument::class)->handle($partner, $document, 'taken-example.txt', null))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetDocumentVisibility::class)->handle($partner, $document, false))->toThrow(AuthorizationException::class);

    $document->refresh();

    expect($document->name)->toBe('partner-example.txt')
        ->and($document->visible_to_client)->toBeTrue();
});
