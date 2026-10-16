<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * Archiving an owner hides its files, restoring brings them back, and nothing is ever deleted (DO-01, DO-03, D-21).
 * The Partner view comes from the Partner-scoped Project, Task and TaskComment queries; the default Admin listing
 * from Document::withoutArchivedOwners(). Every owner is archived and restored the way the application does it.
 * Every name and content is fictional.
 */

/**
 * A client-visible project of the given client, written through the domain Action.
 */
function archivedOwnerProject(Client $client, string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example archived-owner project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * Attaches one fictional file (visible by default where the owner can be shared) and returns the document.
 */
function archivedOwnerAttach(User $admin, ?Model $owner): Document
{
    /** @var Document */
    return app(AttachDocuments::class)->handle($admin, $owner, [DocumentFixtures::upload()])->firstOrFail();
}

/**
 * The sorted ids a query returns.
 *
 * @param  Builder<Document>  $query
 * @return list<string>
 */
function archivedOwnerIds(Builder $query): array
{
    /** @var list<string> $ids */
    $ids = $query->pluck('id')->all();
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * The sorted ids of the given documents.
 *
 * @return list<string>
 */
function archivedOwnerKeys(Document ...$documents): array
{
    $ids = array_map(static fn (Document $document): string => (string) $document->getKey(), $documents);
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * Asserts, as the Admin, that every document still has its row, exactly one media row and its stored object.
 */
function archivedOwnerAssertIntact(Document ...$documents): void
{
    foreach ($documents as $document) {
        expect(Document::query()->whereKey($document->getKey())->exists())->toBeTrue();

        $media = app(PartnerContext::class)->runAsSystem(
            static fn () => Media::query()->where('model_type', 'document')->where('model_id', $document->getKey())->get(),
        );

        expect($media)->toHaveCount(1)
            ->and(Storage::disk('local')->exists($media->first()->getPathRelativeToRoot()))->toBeTrue();
    }
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('hides the file of an archived project from the Partner and the default Admin query and brings it back on restore', function (): void {
    $client = Client::factory()->create();
    $project = archivedOwnerProject($client);
    $document = archivedOwnerAttach($this->admin, $project);
    $partner = Canary::partnerFor((string) $client->getKey());
    $expected = archivedOwnerKeys($document);

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe($expected);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe($expected)
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe([]);

    // Archived the way ProjectResource does it: a soft delete of the project.
    $project->delete();

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe([]);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe([])
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe($expected)
        ->and(archivedOwnerIds(Document::query()))->toBe($expected);
    archivedOwnerAssertIntact($document);

    $project->restore();

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe($expected);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe($expected)
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe([]);
    archivedOwnerAssertIntact($document);
});
