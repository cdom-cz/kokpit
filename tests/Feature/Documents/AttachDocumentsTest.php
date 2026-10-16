<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The single upload entry point (DO-01, D-01, D-03): one documents row with exactly one media row on the
 * private disk, the owner's client and project resolved on the server. Every name and content is fictional.
 */

/**
 * A client-visible project ABC of a fresh client, written through the domain Action.
 */
function documentProject(?Client $client = null): Project
{
    return app(CreateProject::class)->handle($client ?? Client::factory()->create(), [
        'name' => 'Example document project',
        'key' => 'ABC',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('stores a file of a project as one document with one private object', function (): void {
    $project = documentProject();
    $file = DocumentFixtures::upload();
    $size = $file->getSize();

    $documents = app(AttachDocuments::class)->handle($this->admin, $project, [$file]);

    expect($documents)->toHaveCount(1);

    /** @var Document $document */
    $document = $documents->first();

    expect($document->owner_type)->toBe('project')
        ->and($document->owner_id)->toBe($project->getKey())
        ->and($document->client_id)->toBe($project->client_id)
        ->and($document->project_id)->toBe($project->getKey())
        ->and($document->uploaded_by_id)->toBe($this->admin->getKey())
        ->and($document->visible_to_client)->toBeTrue()
        ->and($document->mime_type)->toBe('text/plain')
        ->and($document->size_bytes)->toBe($size)
        ->and($document->extension)->toBe('txt');

    $media = app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->where('model_type', 'document')->get());

    expect($media)->toHaveCount(1)
        ->and($media->first()->model_id)->toBe($document->getKey())
        ->and($media->first()->collection_name)->toBe(Document::MEDIA_COLLECTION)
        ->and($media->first()->file_name)->toBe($document->getKey().'.txt')
        ->and(Storage::disk('local')->exists($media->first()->getPathRelativeToRoot()))->toBeTrue();

    expect(Document::query()->count())->toBe(1);
});

it('never carries user text in the stored object name', function (): void {
    $file = DocumentFixtures::upload(implode('', ['..', '/', 'Fictional Name', '.', 'TXT']));

    /** @var Document $document */
    $document = app(AttachDocuments::class)->handle($this->admin, documentProject(), [$file])->first();
    $media = app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->where('model_id', $document->getKey())->firstOrFail());

    expect($media->file_name)->toBe($document->getKey().'.txt')
        ->and($document->extension)->toBe('txt');
});

it('refuses a Partner and stores nothing', function (): void {
    $project = documentProject();
    $partner = Canary::partnerFor($project->client_id);
    $this->actingAs($partner);

    expect(fn () => app(AttachDocuments::class)->handle($partner, $project, [DocumentFixtures::upload()]))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($this->admin);

    expect(Document::query()->count())->toBe(0)
        ->and(app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->count()))->toBe(0);
});
