<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Queries\StorageTotals;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
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
