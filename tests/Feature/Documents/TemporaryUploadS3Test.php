<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\S3TestDisk;

/*
 * A temporary upload held on the real S3-compatible bucket (D-18): the browser sets the Content-Type of
 * the object itself, so the stored MIME type must come from the bytes. Runs against the dedicated test
 * bucket, never skipped. Every name and content is fictional.
 */
pest()->group('s3');

beforeEach(function (): void {
    S3TestDisk::use();
    config(['media-library.disk_name' => 's3']);

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->bucketPrefixes = ['livewire-tmp/'];
});

afterEach(function (): void {
    foreach ($this->bucketPrefixes as $prefix) {
        Storage::disk('s3')->deleteDirectory($prefix);
    }
});

it('stores a temporary upload held on the bucket with the type of its bytes and removes the temporary object', function (): void {
    $content = implode(' ', ['Fictional', 'bucket', 'text', Str::random(10)]);
    $name = implode('-', ['bucket', 'note', Str::lower(Str::random(5))]).'.png';

    $stored = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded(DocumentFixtures::upload($name, $content));
    $key = FileUploadConfiguration::path($stored, false);

    S3TestDisk::client()->putObject([
        'Bucket' => S3TestDisk::BUCKET,
        'Key' => $key,
        'Body' => $content,
        'ContentType' => 'image/png',
    ]);

    expect(S3TestDisk::objects('livewire-tmp/'))->toBe([$key]);

    $project = app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example bucket project',
        'key' => 'BKT',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);

    $file = new TemporaryUploadedFile($stored, 's3');

    /** @var Document $document */
    $document = app(AttachDocuments::class)->handle($this->admin, $project, [$file])->firstOrFail();

    /** @var Media $media */
    $media = app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->where('model_id', $document->getKey())->firstOrFail());
    $this->bucketPrefixes[] = dirname($media->getPathRelativeToRoot()).'/';

    expect($document->mime_type)->toBe('text/plain')
        ->and($document->size_bytes)->toBe(strlen($content))
        ->and($document->name)->toBe($name)
        ->and(Storage::disk('s3')->exists($media->getPathRelativeToRoot()))->toBeTrue()
        ->and(S3TestDisk::objects('livewire-tmp/'))->toBe([]);
});
