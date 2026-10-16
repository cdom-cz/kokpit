<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Enums\DocumentTypeGroup;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TransactionResource;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The Admin's central "Soubory" listing (DO-01, DO-02, D-04, D-07, D-09, D-21): every file with its owner, client,
 * size, type, uploader, date and flag; the filters, the sorting and the search; single download; bulk delete of a
 * selection or of a whole filter result; the upload of unattached files. Every name and content is fictional.
 */

/**
 * A client-visible project of the given client, written through the domain Action.
 */
function listingProject(Client $client, string $key = 'LST'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example listing project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * A recorded expense of the given client, with a fictional counterparty.
 */
function listingTransaction(?Client $client = null): Transaction
{
    $admin = auth()->user();
    assert($admin instanceof User);

    $parent = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->where('name', 'Software')->sole();
    $category = TransactionCategory::query()->where('parent_id', $parent->getKey())->where('name', 'Hosting')->sole();

    return app(RecordTransaction::class)->handle($admin, [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => $category->getKey(),
        'amount' => '100,00',
        'currency' => 'CZK',
        'counterparty' => implode(' ', ['Example', 'supplier']),
        'client_id' => $client?->getKey(),
    ]);
}

/**
 * Attaches one fictional file straight through the domain Action and returns its document.
 */
function listingAttach(?Model $owner, string $name = 'example-note.txt', ?string $content = null): Document
{
    $admin = auth()->user();
    assert($admin instanceof User);

    /** @var Document */
    return app(AttachDocuments::class)->handle($admin, $owner, [DocumentFixtures::upload($name, $content)])->firstOrFail();
}

/**
 * The central listing as the Admin sees it.
 */
function listingPage(): Testable
{
    return Livewire::test(ListDocuments::class);
}

/**
 * The row of the given file as the table loaded it, with the eager-loaded owner, client and uploader.
 */
function listingRow(Testable $listing, Document $document): Document
{
    foreach ($listing->instance()->getTableRecords() as $row) {
        if ($row instanceof Document && $row->getKey() === $document->getKey()) {
            return $row;
        }
    }

    throw new RuntimeException('The file is not on the loaded page of the listing.');
}

/**
 * The URL the owner column links to for the given file.
 */
function listingOwnerUrl(Testable $listing, Document $document): ?string
{
    $column = $listing->instance()->getTable()->getColumn('owner');
    assert($column !== null);

    return $column->record(listingRow($listing, $document))->getUrl();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('tracer: the Admin finds a file of every owner kind in one listing, follows the owner link and downloads one', function (): void {
    $client = Client::factory()->create(['name' => 'Example listing client']);
    $project = listingProject($client);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example listing task']);
    $comment = app(AddTaskComment::class)->handle($this->admin, $task, 'Example listing comment');
    $transaction = listingTransaction($client);

    $files = [
        'client' => listingAttach($client, 'client-example.txt'),
        'project' => listingAttach($project, 'project-example.txt'),
        'task' => listingAttach($task, 'task-example.txt'),
        'comment' => listingAttach($comment, 'comment-example.txt'),
        'transaction' => listingAttach($transaction, 'transaction-example.txt'),
    ];

    // The unattached file goes up through the header action of the listing, like the Admin does it.
    $page = listingPage()->mountAction('uploadUnattached');
    $page->upload('mountedActions.0.data.files.'.Str::uuid()->toString(), [UploadedFile::fake()->createWithContent('unattached-example.txt', 'Fictional unattached content')]);
    $page->callMountedAction()->assertHasNoFormErrors();

    $unattached = Document::query()->whereNull('owner_type')->sole();
    $all = [...array_values($files), $unattached];

    $listing = listingPage()->assertCanSeeTableRecords($all);

    $listing
        ->assertTableColumnStateSet('owner', 'Klient', $files['client'])
        ->assertTableColumnStateSet('owner', 'Projekt', $files['project'])
        ->assertTableColumnStateSet('owner', 'Úkol', $files['task'])
        ->assertTableColumnStateSet('owner', 'Komentář', $files['comment'])
        ->assertTableColumnStateSet('owner', 'Transakce', $files['transaction'])
        ->assertTableColumnStateSet('owner', 'Nepřiřazeno', $unattached)
        ->assertTableColumnStateSet('client.name', 'Example listing client', $files['project'])
        ->assertTableColumnStateSet('client.name', 'Example listing client', $files['transaction']);

    expect(listingOwnerUrl($listing, $files['client']))->toBe(ClientResource::getUrl('view', ['record' => $client]))
        ->and(listingOwnerUrl($listing, $files['project']))->toBe(ProjectResource::getUrl('view', ['record' => $project]))
        ->and(listingOwnerUrl($listing, $files['task']))->toBe(TaskResource::getUrl('view', ['record' => $task]))
        // A comment links to the page of its task.
        ->and(listingOwnerUrl($listing, $files['comment']))->toBe(TaskResource::getUrl('view', ['record' => $task]))
        ->and(listingOwnerUrl($listing, $files['transaction']))->toBe(TransactionResource::getUrl('edit', ['record' => $transaction]))
        ->and(listingOwnerUrl($listing, $unattached))->toBeNull();

    $url = data_get($listing->callAction(TestAction::make('download')->table($files['project']))->effects, 'redirect');

    expect($url)->toBeString()
        ->and($url)->toContain(route('filament.admin.files.document', ['document' => $files['project']->id], false))
        ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();
});

it('stores the file uploaded from the listing unattached, hidden and uploaded by the Admin', function (): void {
    $page = listingPage()->mountAction('uploadUnattached');
    $page->upload('mountedActions.0.data.files.'.Str::uuid()->toString(), [UploadedFile::fake()->createWithContent('loose-example.txt', 'Fictional loose content')]);
    $page->setActionData(['description' => 'Example loose note'])->callMountedAction();

    $document = Document::query()->sole();

    expect($document->owner_type)->toBeNull()
        ->and($document->owner_id)->toBeNull()
        ->and($document->client_id)->toBeNull()
        ->and($document->project_id)->toBeNull()
        ->and($document->visible_to_client)->toBeFalse()
        ->and($document->uploaded_by_id)->toBe($this->admin->id)
        ->and($document->description)->toBe('Example loose note');
});

it('offers no visibility switch in the unattached upload', function (): void {
    listingPage()
        ->mountAction('uploadUnattached')
        ->assertSchemaComponentHidden('visible_to_client', 'mountedActionSchema0')
        ->assertSchemaComponentExists('files', 'mountedActionSchema0');
});

it('loads the owners of the rows in a fixed number of queries whatever the row count', function (): void {
    $client = Client::factory()->create();
    $project = listingProject($client);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example listing task']);
    $comment = app(AddTaskComment::class)->handle($this->admin, $task, 'Example listing comment');
    $transaction = listingTransaction($client);

    $attachOne = static function (string $suffix) use ($client, $project, $task, $comment, $transaction): void {
        foreach ([$client, $project, $task, $comment, $transaction, null] as $index => $owner) {
            listingAttach($owner, "row-{$index}-{$suffix}.txt");
        }
    };

    $attachOne('a');

    // A warm-up render fills the caches that only the first render pays for.
    listingPage();

    $count = static function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        listingPage();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();

    $attachOne('b');
    $attachOne('c');
    $attachOne('d');

    expect(Document::query()->count())->toBe(24)
        ->and($count())->toBe($few);
});

it('answers a Partner with 403 on the listing and its page component', function (): void {
    $client = Client::factory()->create();
    listingAttach($client);

    $this->actingAs(Canary::partnerFor((string) $client->getKey()));

    $this->get(DocumentResource::getUrl('index'))->assertForbidden();

    Livewire::test(ListDocuments::class)->assertForbidden();
});

it('is not globally searchable and the global search of the panel finds no file, for the Admin or a Partner', function (): void {
    $client = Client::factory()->create();
    $project = listingProject($client);
    listingAttach(null, 'searchable-example.txt');
    listingAttach($project, 'searchable-project-example.txt');

    $search = static function (): array {
        $results = Filament::getCurrentOrDefaultPanel()->getGlobalSearchProvider()?->getResults('searchable');
        $titles = [];

        foreach ($results?->getCategories() ?? [] as $items) {
            foreach ($items as $item) {
                $titles[] = (string) $item->title;
            }
        }

        return $titles;
    };

    expect(DocumentResource::canGloballySearch())->toBeFalse()
        ->and($search())->toBe([]);

    $this->actingAs(Canary::partnerFor((string) $client->getKey()));

    expect($search())->toBe([]);
});

it('lists no create page and no record page, only the index', function (): void {
    expect(array_keys(DocumentResource::getPages()))->toBe(['index'])
        ->and(DocumentResource::canCreate())->toBeFalse();
});

/**
 * Moves the upload instant of a file, as an UTC instant (the filters work on Prague days).
 */
function listingCreatedAt(Document $document, string $utc): Document
{
    Document::query()->whereKey($document->getKey())->update(['created_at' => $utc.'+00']);

    return $document->refresh();
}

/**
 * A client with a project, a task, a comment, a transaction and one file on each, plus a loose file, for the
 * filters that tell the owner kinds apart.
 *
 * @return array{client: Client, project: Project, task: Task, files: array{client: Document, project: Document, task: Document, comment: Document, transaction: Document, unattached: Document}}
 */
function listingTree(User $admin, ?Client $client = null, string $key = 'LST'): array
{
    $client ??= Client::factory()->create();
    $project = listingProject($client, $key);
    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example listing task']);
    $comment = app(AddTaskComment::class)->handle($admin, $task, 'Example listing comment');
    $transaction = listingTransaction($client);

    return [
        'client' => $client,
        'project' => $project,
        'task' => $task,
        'files' => [
            'client' => listingAttach($client, 'client-example.txt'),
            'project' => listingAttach($project, 'project-example.txt'),
            'task' => listingAttach($task, 'task-example.txt'),
            'comment' => listingAttach($comment, 'comment-example.txt'),
            'transaction' => listingAttach($transaction, 'transaction-example.txt'),
            'unattached' => listingAttach(null, 'unattached-example.txt'),
        ],
    ];
}

/**
 * The listing with the default archived-owners filter switched off, so every file is a candidate.
 */
function listingAll(): Testable
{
    return listingPage()->set('tableFilters.archived_owners.value', null);
}

it('filters by owner type, each kind returning only its files and "unattached" only the loose ones', function (): void {
    $files = listingTree($this->admin)['files'];

    foreach (['client' => 'client', 'project' => 'project', 'task' => 'task', 'task_comment' => 'comment', 'transaction' => 'transaction'] as $type => $key) {
        $others = array_values(array_filter($files, static fn (Document $document, string $name): bool => $name !== $key, ARRAY_FILTER_USE_BOTH));

        listingPage()
            ->filterTable('owner_type', $type)
            ->assertCanSeeTableRecords([$files[$key]])
            ->assertCanNotSeeTableRecords($others)
            ->assertCountTableRecords(1);
    }

    listingPage()
        ->filterTable('owner_type', 'unattached')
        ->assertCanSeeTableRecords([$files['unattached']])
        ->assertCountTableRecords(1);
});

it('filters by client across every owner kind, receipts included, and by project', function (): void {
    $tree = listingTree($this->admin, null, 'AAA');
    $other = listingTree($this->admin, null, 'BBB');
    $ownFiles = array_values(array_filter($tree['files'], static fn (Document $document): bool => $document->client_id !== null));

    listingPage()
        ->filterTable('client_id', $tree['client']->getKey())
        ->assertCanSeeTableRecords($ownFiles)
        ->assertCanNotSeeTableRecords([...array_values($other['files']), $tree['files']['unattached']])
        ->assertCountTableRecords(5);

    // The project filter matches the project_id column: the project file, its task file and its comment file.
    listingPage()
        ->filterTable('project_id', $tree['project']->getKey())
        ->assertCanSeeTableRecords([$tree['files']['project'], $tree['files']['task'], $tree['files']['comment']])
        ->assertCountTableRecords(3);
});

it('offers archived clients and projects in the client and project filter', function (): void {
    $client = Client::factory()->create(['name' => 'Example archived-filter client']);
    $project = listingProject($client, 'ARC');

    $project->delete();
    $client->delete();

    $table = listingPage()->instance()->getTable();

    expect($table->getFilter('client_id')?->getOptions())->toHaveKey($client->getKey())
        ->and($table->getFilter('project_id')?->getOptions())->toHaveKey($project->getKey());
});

it('filters by the inclusive Prague days of the upload', function (): void {
    // 2026-10-01 is summer time in Prague (UTC+2): the Prague day runs from 2026-09-30 22:00 to 2026-10-01 22:00 UTC.
    $first = listingCreatedAt(listingAttach(null, 'first-instant-example.txt'), '2026-09-30 22:00:00');
    $late = listingCreatedAt(listingAttach(null, 'late-evening-example.txt'), '2026-10-01 21:30:00');
    $before = listingCreatedAt(listingAttach(null, 'day-before-example.txt'), '2026-09-30 21:59:00');
    $next = listingCreatedAt(listingAttach(null, 'next-day-example.txt'), '2026-10-01 22:10:00');

    listingPage()
        ->filterTable('created', ['from' => '2026-10-01', 'until' => '2026-10-01'])
        ->assertCanSeeTableRecords([$first, $late])
        ->assertCanNotSeeTableRecords([$before, $next]);

    listingPage()
        ->filterTable('created', ['from' => '2026-10-02', 'until' => null])
        ->assertCanSeeTableRecords([$next])
        ->assertCanNotSeeTableRecords([$first, $late, $before]);

    listingPage()
        ->filterTable('created', ['from' => null, 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$before])
        ->assertCanNotSeeTableRecords([$first, $late, $next]);
});

it('filters by file-type group', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    $documents = [
        'image' => listingAttach(null, 'picture-example.png', $png),
        'pdf' => listingAttach(null, 'paper-example.pdf', $pdf),
        'text' => listingAttach(null, 'letter-example.docx', 'Fictional letter body'),
        'spreadsheet' => listingAttach(null, 'table-example.csv', "first,second\n1,2\n"),
        'presentation' => listingAttach(null, 'slides-example.pptx', 'Fictional slides'),
        'archive' => listingAttach(null, 'bundle-example.zip', "PK\x05\x06".str_repeat("\x00", 18)),
        'other' => listingAttach(null, 'blob-example.bin', "\x00\x01\x02\x03\xff\xfe"),
        'no-extension' => listingAttach(null, 'plain-example', 'Fictional text without an extension'),
    ];

    $expected = [
        'image' => ['image'],
        'pdf' => ['pdf'],
        'text' => ['text'],
        'spreadsheet' => ['spreadsheet'],
        'presentation' => ['presentation'],
        'archive' => ['archive'],
        'other' => ['other', 'no-extension'],
    ];

    foreach ($expected as $group => $keys) {
        $inGroup = array_map(static fn (string $key): Document => $documents[$key], $keys);
        $outside = array_values(array_filter($documents, static fn (string $key): bool => ! in_array($key, $keys, true), ARRAY_FILTER_USE_KEY));

        listingPage()
            ->filterTable('type_group', $group)
            ->assertCanSeeTableRecords($inGroup)
            ->assertCanNotSeeTableRecords($outside)
            ->assertCountTableRecords(count($keys));
    }

    // The label of a file and the filter agree.
    foreach ($documents as $key => $document) {
        $group = DocumentTypeGroup::of($document);

        expect(in_array($key, $expected[$group->value], true))->toBeTrue("{$key} is in {$group->value}");
    }
});

it('filters by what the client sees', function (): void {
    $tree = listingTree($this->admin);
    $visible = array_values(array_filter($tree['files'], static fn (Document $document): bool => $document->visible_to_client));
    $hidden = array_values(array_filter($tree['files'], static fn (Document $document): bool => ! $document->visible_to_client));

    expect($visible)->not->toBeEmpty()
        ->and($hidden)->not->toBeEmpty();

    listingPage()
        ->filterTable('visible_to_client', true)
        ->assertCanSeeTableRecords($visible)
        ->assertCanNotSeeTableRecords($hidden);

    listingPage()
        ->filterTable('visible_to_client', false)
        ->assertCanSeeTableRecords($hidden)
        ->assertCanNotSeeTableRecords($visible);
});

it('sorts the names in Czech order and every other column without an error', function (): void {
    $data = listingAttach(null, 'Data.txt');
    $quarter = listingAttach(null, implode('', ['Čtvrt', 'letí']).'.txt');
    $price = listingAttach(null, 'Cena.txt');

    listingPage()
        ->sortTable('name')
        ->assertCanSeeTableRecords([$price, $quarter, $data], inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords([$data, $quarter, $price], inOrder: true);

    foreach (['owner', 'client.name', 'size_bytes', 'mime_type', 'uploader.name', 'created_at', 'visible_to_client'] as $column) {
        listingPage()
            ->sortTable($column)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$data, $quarter, $price])
            ->sortTable($column, 'desc')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$data, $quarter, $price]);
    }
});

it('sorts by client name in Czech order and keeps the files without a client', function (): void {
    $dvorak = listingAttach(Client::factory()->create(['name' => implode(' ', ['Dvořák', 'Example'])]), 'dvorak-example.txt');
    $capek = listingAttach(Client::factory()->create(['name' => implode(' ', ['Čapek', 'Example'])]), 'capek-example.txt');
    $cihla = listingAttach(Client::factory()->create(['name' => implode(' ', ['Cihla', 'Example'])]), 'cihla-example.txt');
    $loose = listingAttach(null, 'loose-example.txt');

    listingPage()
        ->sortTable('client.name')
        ->assertCanSeeTableRecords([$cihla, $capek, $dvorak, $loose], inOrder: true)
        ->sortTable('client.name', 'desc')
        ->assertCanSeeTableRecords([$loose, $dvorak, $capek, $cihla], inOrder: true);
});

it('sorts by the uploader name in Czech order', function (): void {
    $other = Canary::admin();

    User::query()->whereKey($this->admin->getKey())->update(['name' => implode(' ', ['Zeta', 'Example'])]);
    User::query()->whereKey($other->getKey())->update(['name' => implode(' ', ['Čapek', 'Example'])]);

    $byZeta = listingAttach(null, 'by-zeta-example.txt');
    $byCapek = listingAttach(null, 'by-capek-example.txt');

    Document::query()->whereKey($byCapek->getKey())->update(['uploaded_by_id' => $other->getKey()]);

    listingPage()
        ->sortTable('uploader.name')
        ->assertCanSeeTableRecords([$byCapek, $byZeta], inOrder: true)
        ->sortTable('uploader.name', 'desc')
        ->assertCanSeeTableRecords([$byZeta, $byCapek], inOrder: true);
});

it('finds a file by a part of its name, whatever the letter case', function (): void {
    $contract = listingAttach(null, implode(' ', ['Smlouva', 'o', 'dílo']).'.pdf');
    $other = listingAttach(null, 'invoice-example.txt');

    listingPage()
        ->searchTable('smlouva')
        ->assertCanSeeTableRecords([$contract])
        ->assertCanNotSeeTableRecords([$other]);
});

it('falls back to no filter for a forged filter value', function (): void {
    $tree = listingTree($this->admin);
    $all = array_values($tree['files']);

    $listing = listingAll();

    foreach ([
        'owner_type' => 'not-an-owner-type',
        'client_id' => 'not-a-uuid',
        'project_id' => "'; drop table documents; --",
        'type_group' => 'not-a-group',
        'visible_to_client' => 'maybe',
    ] as $name => $value) {
        $listing->set("tableFilters.{$name}.value", $value)->assertSuccessful()->assertCanSeeTableRecords($all);
        $listing->set("tableFilters.{$name}.value", null);
    }

    $listing
        ->set('tableFilters.created.from', 'garbage')
        ->set('tableFilters.created.until', '2026-13-45')
        ->assertSuccessful()
        ->assertCanSeeTableRecords($all);

    $listing
        ->set('tableFilters.archived_owners.value', 'garbage')
        ->assertSuccessful()
        ->assertCanSeeTableRecords($all);
});

it('hides the files of an archived project by default, shows only them under the archived filter and brings them back on restore', function (): void {
    $tree = listingTree($this->admin);
    $files = $tree['files'];
    $projectFiles = [$files['project'], $files['task'], $files['comment']];
    $rest = [$files['client'], $files['transaction'], $files['unattached']];

    $tree['project']->delete();

    listingPage()
        ->assertCanSeeTableRecords($rest)
        ->assertCanNotSeeTableRecords($projectFiles);

    listingPage()
        ->filterTable('archived_owners', true)
        ->assertCanSeeTableRecords($projectFiles)
        ->assertCanNotSeeTableRecords($rest);

    listingAll()->assertCanSeeTableRecords([...$rest, ...$projectFiles]);

    $tree['project']->restore();

    listingPage()->assertCanSeeTableRecords([...$rest, ...$projectFiles]);
});

it('hides the files of an archived task and of its comments, keeping the project file', function (): void {
    $tree = listingTree($this->admin);
    $files = $tree['files'];

    app(ArchiveTask::class)->handle($this->admin, $tree['task']);

    listingPage()
        ->assertCanSeeTableRecords([$files['client'], $files['project'], $files['transaction'], $files['unattached']])
        ->assertCanNotSeeTableRecords([$files['task'], $files['comment']]);

    listingPage()
        ->filterTable('archived_owners', true)
        ->assertCanSeeTableRecords([$files['task'], $files['comment']])
        ->assertCountTableRecords(2);

    app(RestoreTask::class)->handle($this->admin, $tree['task']);

    listingPage()->assertCanSeeTableRecords(array_values($files));
});

it('hides the files of an archived client but never a receipt or a loose file', function (): void {
    $tree = listingTree($this->admin);
    $files = $tree['files'];
    $archived = [$files['client'], $files['project'], $files['task'], $files['comment']];

    app(ArchiveClient::class)->handle($tree['client']);

    // The receipt of a transaction of the archived client is a finance record and stays findable.
    listingPage()
        ->assertCanSeeTableRecords([$files['transaction'], $files['unattached']])
        ->assertCanNotSeeTableRecords($archived);

    listingPage()
        ->filterTable('archived_owners', true)
        ->assertCanSeeTableRecords($archived)
        ->assertCanNotSeeTableRecords([$files['transaction'], $files['unattached']]);

    app(RestoreClient::class)->handle($tree['client']);

    listingPage()->assertCanSeeTableRecords(array_values($files));
});

it('shows archived owners by name and link, and the archived client by name, without lazy-loading a relation', function (): void {
    $client = Client::factory()->create(['name' => 'Example archived client']);
    $tree = listingTree($this->admin, $client, 'ARC');
    $files = $tree['files'];

    $tree['project']->delete();
    $client->delete();

    // A relation loaded on its own for a row would throw here instead of costing a query per row.
    Model::preventLazyLoading();

    try {
        $listing = listingAll()->assertCanSeeTableRecords(array_values($files));

        expect(listingOwnerUrl($listing, $files['client']))->toBe(ClientResource::getUrl('view', ['record' => $client]))
            ->and(listingOwnerUrl($listing, $files['project']))->toBe(ProjectResource::getUrl('view', ['record' => $tree['project']]))
            ->and(listingOwnerUrl($listing, $files['task']))->toBe(TaskResource::getUrl('view', ['record' => $tree['task']]))
            ->and(listingOwnerUrl($listing, $files['comment']))->toBe(TaskResource::getUrl('view', ['record' => $tree['task']]));

        $listing
            ->assertTableColumnHasDescription('owner', 'Example archived client', $files['client'])
            ->assertTableColumnHasDescription('owner', $tree['project']->name, $files['project'])
            ->assertTableColumnHasDescription('owner', $tree['task']->reference, $files['task'])
            ->assertTableColumnHasDescription('owner', $tree['task']->reference, $files['comment'])
            ->assertTableColumnStateSet('client.name', 'Example archived client', $files['project']);
    } finally {
        Model::preventLazyLoading(false);
    }
});

it('renames a file and shows or hides it for the client from a listing row, as on the tabs', function (): void {
    $tree = listingTree($this->admin);
    $project = $tree['files']['project'];

    listingPage()
        ->callAction(TestAction::make('rename')->table($project), ['name' => 'Renamed example.txt', 'description' => 'Example description'])
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->name)->toBe('Renamed example.txt')
        ->and($project->description)->toBe('Example description')
        ->and($project->visible_to_client)->toBeTrue();

    listingPage()->callAction(TestAction::make('toggleVisibility')->table($project));

    expect($project->refresh()->visible_to_client)->toBeFalse();

    listingPage()->callAction(TestAction::make('toggleVisibility')->table($project));

    expect($project->refresh()->visible_to_client)->toBeTrue();
});

it('offers the visibility switch for project, task and comment files only', function (): void {
    $files = listingTree($this->admin)['files'];
    $listing = listingPage();

    foreach (['project', 'task', 'comment'] as $key) {
        $listing->assertActionVisible(TestAction::make('toggleVisibility')->table($files[$key]));
    }

    foreach (['client', 'transaction', 'unattached'] as $key) {
        $listing->assertActionHidden(TestAction::make('toggleVisibility')->table($files[$key]));
    }
});

it('deletes one file from a listing row after a confirmation that names it, with its object', function (): void {
    $document = listingAttach(null, 'doomed-example.txt');
    $path = Document::query()->whereKey($document->getKey())->sole()->getFirstMedia(Document::MEDIA_COLLECTION)?->getPathRelativeToRoot();

    expect($path)->toBeString()
        ->and(Storage::disk('local')->exists((string) $path))->toBeTrue();

    $listing = listingPage()
        ->assertActionExists(TestAction::make('delete')->table($document), static fn (Action $action): bool => $action->isConfirmationRequired())
        ->mountAction(TestAction::make('delete')->table($document));

    expect((string) $listing->instance()->getMountedAction()?->getModalDescription())->toContain('doomed-example.txt');

    $listing->callMountedAction();

    expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists((string) $path))->toBeFalse();
});

it('offers a preview only for images and PDF', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    $image = listingAttach(null, 'picture-example.png', $png);
    $paper = listingAttach(null, 'paper-example.pdf', $pdf);
    $text = listingAttach(null, 'note-example.txt');
    $archive = listingAttach(null, 'bundle-example.zip', "PK\x05\x06".str_repeat("\x00", 18));

    listingPage()
        ->assertActionVisible(TestAction::make('preview')->table($image))
        ->assertActionVisible(TestAction::make('preview')->table($paper))
        ->assertActionHidden(TestAction::make('preview')->table($text))
        ->assertActionHidden(TestAction::make('preview')->table($archive));
});

it('deletes every file of a filter result across two pages after one confirmation, and no other file', function (): void {
    $project = listingProject(Client::factory()->create(), 'BLK');
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example bulk task']);

    $targets = [];

    for ($i = 1; $i <= 7; $i++) {
        $targets[] = listingAttach($task, "task-target-{$i}-example.txt");
    }

    $bystanders = [listingAttach($project, 'project-bystander-example.txt'), listingAttach(null, 'loose-bystander-example.txt')];

    $listing = listingPage()
        ->filterTable('owner_type', 'task')
        ->set('tableRecordsPerPage', 5)
        // Seven files in the filter result, five on the first page.
        ->assertCountTableRecords(7)
        // "Select all" of the filter result, as the select-all link does: every row of every page.
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', [])
        ->mountAction(TestAction::make('bulkDelete')->table()->bulk());

    expect(count($listing->instance()->getTableRecords()))->toBe(5)
        ->and((string) $listing->instance()->getMountedAction()?->getModalDescription())->toBe(trans_choice('documents.actions.bulk_delete_description', 7, ['count' => 7]));

    $listing->callMountedAction()
        ->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 7, 'failed' => 0]));

    expect(Document::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect($bystanders)->pluck('id')->sort()->values()->all());

    expect(Document::query()->whereKey(collect($targets)->pluck('id')->all())->exists())->toBeFalse();

    // Nothing but the bystanders' objects is left on the disk.
    expect(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('deletes only the matching files when a search narrows the selected-all result', function (): void {
    $hit = [listingAttach(null, 'invoice-one-example.txt'), listingAttach(null, 'invoice-two-example.txt')];
    $other = [listingAttach(null, 'contract-example.txt'), listingAttach(null, 'photo-example.txt')];

    listingPage()
        ->searchTable('invoice')
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', [])
        ->mountAction(TestAction::make('bulkDelete')->table()->bulk())
        ->callMountedAction()
        ->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 2, 'failed' => 0]));

    expect(Document::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect($other)->pluck('id')->sort()->values()->all())
        ->and(Document::query()->whereKey(collect($hit)->pluck('id')->all())->exists())->toBeFalse();
});

it('leaves a deselected file and the files of archived owners alone when everything is selected under the default filter', function (): void {
    $archivedProject = listingProject(Client::factory()->create(), 'ARC');
    $archivedFile = listingAttach($archivedProject, 'archived-owner-example.txt');
    $archivedProject->delete();

    $keep = listingAttach(null, 'kept-example.txt');
    $gone = [listingAttach(null, 'gone-one-example.txt'), listingAttach(null, 'gone-two-example.txt')];

    listingPage()
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', [$keep->getKey()])
        ->mountAction(TestAction::make('bulkDelete')->table()->bulk())
        ->callMountedAction()
        ->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 2, 'failed' => 0]));

    expect(Document::query()->whereKey(collect($gone)->pluck('id')->all())->exists())->toBeFalse()
        ->and(Document::query()->whereKey([$keep->getKey(), $archivedFile->getKey()])->count())->toBe(2);
});

it('deletes a hand-picked selection and reports the files that could not go', function (): void {
    $documents = [listingAttach(null, 'pick-one-example.txt'), listingAttach(null, 'pick-two-example.txt'), listingAttach(null, 'pick-three-example.txt')];
    $untouched = listingAttach(null, 'untouched-example.txt');

    $listing = listingPage()->mountTableBulkAction('bulkDelete', [$documents[0]->getKey(), $documents[1]->getKey()]);

    // One of the two files disappears between the selection and the confirmation.
    Document::query()->whereKey($documents[1]->getKey())->first()?->delete();

    $listing->callMountedTableBulkAction()
        ->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 1, 'failed' => 1]));

    expect(Document::query()->whereKey($documents[2]->getKey())->exists())->toBeTrue()
        ->and(Document::query()->whereKey($untouched->getKey())->exists())->toBeTrue()
        ->and(Document::query()->whereKey($documents[0]->getKey())->exists())->toBeFalse();
});

it('refuses the bulk delete to a Partner', function (): void {
    $client = Client::factory()->create();
    $document = listingAttach($client, 'guarded-example.txt');

    $this->actingAs(Canary::partnerFor((string) $client->getKey()));

    Livewire::test(ListDocuments::class)->assertForbidden();

    expect(Document::query()->count())->toBe(0);

    $this->actingAs($this->admin);

    expect(Document::query()->whereKey($document->getKey())->exists())->toBeTrue();
});
