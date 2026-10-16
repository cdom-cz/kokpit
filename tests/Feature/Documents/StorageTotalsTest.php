<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Queries\StorageTotals;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageByClient;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageStats;
use App\Filament\Support\DocumentColumns;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The storage totals above the central listing (DO-02, D-11, D-21): the tiles with the number and size of every
 * stored file, and the by-client table with the row of the files that belong to no client. Every name is fictional.
 */

/**
 * Attaches one fictional file through the domain Action and stores the given size on its row.
 */
function totalsAttach(?Model $owner, int $bytes, string $name = 'totals-example.txt'): Document
{
    $admin = auth()->user();
    assert($admin instanceof User);

    /** @var Document $document */
    $document = app(AttachDocuments::class)->handle($admin, $owner, [DocumentFixtures::upload($name)])->firstOrFail();

    Document::query()->whereKey($document->getKey())->update(['size_bytes' => $bytes]);

    return $document->refresh();
}

/**
 * A client-visible project of the client.
 */
function totalsProject(Client $client, string $key = 'TOT'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example totals project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * A recorded expense, with or without a client.
 */
function totalsTransaction(?Client $client = null): Model
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
 * The tiles a stats widget would show, read through its protected getStats().
 *
 * @return list<Filament\Widgets\StatsOverviewWidget\Stat>
 */
function totalsStats(object $component): array
{
    $widget = $component->instance();
    $method = new ReflectionMethod($widget, 'getStats');

    /** @var list<Filament\Widgets\StatsOverviewWidget\Stat> $stats */
    $stats = array_values($method->invoke($widget));

    return $stats;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('tracer: the Admin opens the listing and the tiles show the number and size of all stored files', function (): void {
    $client = Client::factory()->create();
    totalsAttach($client, 1000);
    totalsAttach($client, 2000);
    totalsAttach(null, 1048576);

    $this->get(DocumentResource::getUrl('index'))->assertOk()->assertSeeLivewire(DocumentStorageStats::class);

    $stats = totalsStats(Livewire::test(DocumentStorageStats::class));

    expect($stats)->toHaveCount(2)
        ->and((string) $stats[0]->getLabel())->toBe('Souborů')
        ->and((string) $stats[0]->getValue())->toBe('3')
        ->and((string) $stats[1]->getLabel())->toBe('Celková velikost')
        ->and((string) $stats[1]->getValue())->toBe(DocumentColumns::humanSize(1000 + 2000 + 1048576))
        ->and((string) $stats[0]->getDescription())->toBe(__('documents.totals.includes_archived'))
        ->and((string) $stats[1]->getDescription())->toBe(__('documents.totals.includes_archived'));
});

it('shows zero files and zero bytes when nothing is stored', function (): void {
    $stats = totalsStats(Livewire::test(DocumentStorageStats::class));

    expect((string) $stats[0]->getValue())->toBe('0')
        ->and((string) $stats[1]->getValue())->toBe('0 B');
});

it('keeps the tiles at all files while a listing filter narrows the table', function (): void {
    $client = Client::factory()->create();
    totalsAttach($client, 1000);
    totalsAttach($client, 2000);
    totalsAttach(null, 1048576);

    Livewire::test(ListDocuments::class)
        ->filterTable('owner_type', 'unattached')
        ->assertCountTableRecords(1);

    $stats = totalsStats(Livewire::test(DocumentStorageStats::class));

    expect((string) $stats[0]->getValue())->toBe('3')
        ->and((string) $stats[1]->getValue())->toBe(DocumentColumns::humanSize(1051576));
});

it('counts the files of an archived owner, which the default listing hides', function (): void {
    $client = Client::factory()->create();
    $project = totalsProject($client);
    totalsAttach($client, 1000);
    totalsAttach($project, 2000);
    totalsAttach(null, 1048576);

    $project->delete();

    Livewire::test(ListDocuments::class)->assertCountTableRecords(2);

    $stats = totalsStats(Livewire::test(DocumentStorageStats::class));

    expect((string) $stats[0]->getValue())->toBe('3')
        ->and((string) $stats[1]->getValue())->toBe(DocumentColumns::humanSize(1051576));
});

it('returns the overall figures as plain integers', function (): void {
    totalsAttach(null, 1000);
    totalsAttach(null, 2000);

    expect(app(StorageTotals::class)->overall())->toBe(['count' => 2, 'bytes' => 3000]);
});

it('refuses a Partner the tiles and the reader', function (): void {
    $client = Client::factory()->create();
    totalsAttach($client, 1000);

    $this->actingAs(Canary::partnerFor((string) $client->getKey()));

    Livewire::test(DocumentStorageStats::class)->assertForbidden();

    expect(static fn () => app(StorageTotals::class)->overall())->toThrow(AuthorizationException::class);
});

it('lets a system run read the totals', function (): void {
    totalsAttach(null, 1000);

    auth()->logout();

    $result = app(PartnerContext::class)->runAsSystem(static fn (): array => app(StorageTotals::class)->overall());

    expect($result)->toBe(['count' => 1, 'bytes' => 1000]);
});

/* Plan 09-16 task 2: the by-client table. */

it('lists the clients with their files, rolled up from project, task and comment files, in Czech order, the no-client row last', function (): void {
    $square = Client::factory()->create(['name' => implode('', ['Čtv', 'erec'])]);
    $path = Client::factory()->create(['name' => 'Cesta']);
    $oak = Client::factory()->create(['name' => 'Dub']);
    $empty = Client::factory()->create(['name' => 'Bezsouboru']);

    $project = totalsProject($square);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example totals task']);
    $comment = app(AddTaskComment::class)->handle($this->admin, $task, 'Example totals comment');

    totalsAttach($project, 100);
    totalsAttach($task, 200);
    totalsAttach($comment, 400);
    totalsAttach($path, 1000);
    totalsAttach($oak, 2000);
    totalsAttach(null, 5000);
    totalsAttach(totalsTransaction(), 7000);

    $rows = app(StorageTotals::class)->byClient();

    expect(array_column($rows, 'name'))->toBe(['Cesta', implode('', ['Čtv', 'erec']), 'Dub', ''])
        ->and(array_column($rows, 'count'))->toBe([1, 3, 1, 2])
        ->and(array_column($rows, 'bytes'))->toBe([1000, 700, 2000, 12000])
        ->and($rows[3]['client_id'])->toBeNull()
        ->and($rows[0]['client_id'])->toBe($path->getKey())
        ->and(array_column($rows, 'client_id'))->not->toContain($empty->getKey());
});

it('counts the receipt of a transaction with a client for that client', function (): void {
    $client = Client::factory()->create();

    totalsAttach(totalsTransaction($client), 300);

    $rows = app(StorageTotals::class)->byClient();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['client_id'])->toBe($client->getKey())
        ->and($rows[0]['bytes'])->toBe(300);
});

it('lists an archived client with its files and the archived label, and drops the label on restore', function (): void {
    $client = Client::factory()->create(['name' => 'Example archived client']);
    totalsAttach($client, 1500);
    totalsAttach($client, 500);

    app(ArchiveClient::class)->handle($client);

    $rows = app(StorageTotals::class)->byClient();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['count'])->toBe(2)
        ->and($rows[0]['bytes'])->toBe(2000)
        ->and($rows[0]['archived'])->toBeTrue();

    $archivedHtml = Livewire::test(DocumentStorageByClient::class)->html();

    expect($archivedHtml)->toContain('Example archived client')->toContain(__('documents.totals.archived_client'));

    app(RestoreClient::class)->handle($client->refresh());

    expect(app(StorageTotals::class)->byClient()[0]['archived'])->toBeFalse();

    Livewire::test(DocumentStorageByClient::class)->assertDontSee(__('documents.totals.archived_client'));
});

it('sums the rows to the overall tile values', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    totalsAttach($client, 111);
    totalsAttach($other, 222);
    totalsAttach(null, 333);
    totalsAttach(totalsTransaction(), 444);

    $totals = app(StorageTotals::class);
    $rows = $totals->byClient();
    $overall = $totals->overall();

    expect(array_sum(array_column($rows, 'count')))->toBe($overall['count'])
        ->and(array_sum(array_column($rows, 'bytes')))->toBe($overall['bytes']);
});

it('renders the by-client table with the no-client label and the formatted sizes', function (): void {
    $client = Client::factory()->create(['name' => 'Example rendered client']);
    totalsAttach($client, 2048);
    totalsAttach(null, 1048576);

    $this->get(DocumentResource::getUrl('index'))->assertOk()->assertSeeLivewire(DocumentStorageByClient::class);

    Livewire::test(DocumentStorageByClient::class)
        ->assertSee(__('documents.totals.by_client_heading'))
        ->assertSee('Example rendered client')
        ->assertSee(DocumentColumns::humanSize(2048))
        ->assertSee(__('documents.totals.no_client'))
        ->assertSee(DocumentColumns::humanSize(1048576));
});

it('refuses a Partner the by-client table and the by-client reader', function (): void {
    $client = Client::factory()->create();
    totalsAttach($client, 1000);

    $this->actingAs(Canary::partnerFor((string) $client->getKey()));

    Livewire::test(DocumentStorageByClient::class)->assertForbidden();

    expect(static fn () => app(StorageTotals::class)->byClient())->toThrow(AuthorizationException::class);
});
