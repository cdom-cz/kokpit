<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\StoreArticleImage;
use App\Domain\Knowledge\Actions\StoredImage;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Images\ImagePipeline;
use App\Domain\Knowledge\Images\ImageRefusal;
use App\Domain\Knowledge\Images\ImageRefused;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\ImageFixtures;

/*
 * The Action that turns one uploaded file into a stored, pending knowledge image (IMG-01, IMG-02, D-06 to D-10,
 * R-20-05-2): the gate, the size cap, the pipeline, the object first and the row second. Every name and byte string is
 * fictional; image bytes come from ImageFixtures at runtime.
 */

/**
 * A fake upload with the given name and content.
 */
function storeArticleImageFile(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * The number of image rows, read without the Partner scope.
 */
function storeArticleImageRowCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => KnowledgeArticleImage::query()->count());
}

/**
 * The paths of the stored image objects on the default disk.
 *
 * @return list<string>
 */
function storeArticleImageObjects(): array
{
    return array_values(Storage::disk()->allFiles(KnowledgeArticleImage::STORAGE_DIRECTORY));
}

/**
 * Asserts that a refused or failed upload left neither a row nor an object.
 */
function storeArticleImageExpectNothingStored(): void
{
    expect(storeArticleImageRowCount())->toBe(0)
        ->and(storeArticleImageObjects())->toBe([]);
}

beforeEach(function (): void {
    Storage::fake();

    $this->admin = Canary::admin();
    $this->action = app(StoreArticleImage::class);
});

it('stores the re-encoded bytes privately and a pending row, and returns the reference', function (): void {
    $stored = $this->action->handle($this->admin, storeArticleImageFile('screenshot-example.png', ImageFixtures::png()));

    $row = app(PartnerContext::class)->runAsSystem(static fn (): KnowledgeArticleImage => KnowledgeArticleImage::query()->findOrFail($stored->id));
    $bytes = (string) Storage::disk()->get($row->storagePath());

    expect($stored)->toBeInstanceOf(StoredImage::class)
        ->and($stored->altText)->toBe('screenshot-example')
        ->and($stored->width)->toBe(40)
        ->and($stored->height)->toBe(20)
        ->and($stored->reference)->toBe('![screenshot-example](kokpit-attachment:'.$stored->id.')')
        ->and($row->article_id)->toBeNull()
        ->and($row->uploaded_by)->toBe($this->admin->getKey())
        ->and($row->file_name)->toBe('screenshot-example.png')
        ->and($row->mime_type)->toBe('image/png')
        ->and($row->width)->toBe(40)
        ->and($row->height)->toBe(20)
        ->and($row->size_bytes)->toBe(strlen($bytes))
        ->and($row->sha256)->toBe(hash('sha256', $bytes))
        ->and($row->storagePath())->toBe('knowledge/images/'.$stored->id.'.png')
        ->and(storeArticleImageObjects())->toBe([$row->storagePath()]);
});

it('decides the type by the content, never by the file name or the declared type', function (): void {
    $stored = $this->action->handle($this->admin, storeArticleImageFile('photo-example.jpg', ImageFixtures::png()));

    $row = app(PartnerContext::class)->runAsSystem(static fn (): KnowledgeArticleImage => KnowledgeArticleImage::query()->findOrFail($stored->id));

    expect($row->mime_type)->toBe('image/png')
        ->and($row->storagePath())->toBe('knowledge/images/'.$stored->id.'.png')
        ->and($row->file_name)->toBe('photo-example.jpg');
});

it('never lets a hostile file name break the inserted Markdown', function (): void {
    $stored = $this->action->handle($this->admin, storeArticleImageFile("a](example.com)[b\u{202E}.png", ImageFixtures::png()));

    expect($stored->altText)->not->toContain('[')
        ->and($stored->altText)->not->toContain(']')
        ->and($stored->altText)->not->toContain('(')
        ->and($stored->altText)->not->toContain(')')
        ->and($stored->reference)->toBe('![aexample.comb](kokpit-attachment:'.$stored->id.')');
});

it('refuses an upload by its content and stores nothing', function (Closure $bytes, ImageRefusal $expected): void {
    try {
        $this->action->handle($this->admin, storeArticleImageFile('screenshot-example.png', $bytes()));

        $refusal = null;
    } catch (ImageRefused $refused) {
        $refusal = $refused->refusal;
    }

    expect($refusal)->toBe($expected);

    storeArticleImageExpectNothingStored();
})->with([
    'an SVG named like a screenshot' => [static fn (): string => ImageFixtures::svg(), ImageRefusal::Svg],
    'a text file named like a screenshot' => [static fn (): string => ImageFixtures::renamedText(), ImageRefusal::NotAnImage],
    'an animated GIF' => [static fn (): string => ImageFixtures::animatedGif(), ImageRefusal::Animated],
    'one byte over the upload limit' => [static fn (): string => ImageFixtures::oversized(), ImageRefusal::TooLarge],
]);

it('refuses a file that reports more than the limit without reading it', function (): void {
    $file = UploadedFile::fake()->create('screenshot-example.png', intdiv(ImagePipeline::MAX_UPLOAD_BYTES, 1024) + 1);

    expect(fn () => $this->action->handle($this->admin, $file))
        ->toThrow(function (ImageRefused $refused): void {
            expect($refused->refusal)->toBe(ImageRefusal::TooLarge);
        });

    storeArticleImageExpectNothingStored();
});

it('refuses a Partner and stores nothing', function (): void {
    // The actor is created first: creating a user writes its own activity rows.
    $partner = Canary::partnerFor(Canary::twoClients()[0]);

    expect(fn () => $this->action->handle($partner, storeArticleImageFile('screenshot-example.png', ImageFixtures::png())))
        ->toThrow(AuthorizationException::class);

    storeArticleImageExpectNothingStored();
});

it('removes the object again and fails without the file name when the database refuses the row', function (): void {
    KnowledgeArticleImage::creating(static function (): never {
        throw new PDOException('forced failure for screenshot-example.png');
    });

    $failure = null;

    try {
        $this->action->handle($this->admin, storeArticleImageFile('screenshot-example.png', ImageFixtures::png()));
    } catch (ArticleWriteFailed $failed) {
        $failure = $failed;
    }

    expect($failure)->toBeInstanceOf(ArticleWriteFailed::class)
        ->and($failure?->getMessage())->not->toContain('screenshot-example')
        ->and($failure?->getPrevious())->toBeNull();

    storeArticleImageExpectNothingStored();
});
