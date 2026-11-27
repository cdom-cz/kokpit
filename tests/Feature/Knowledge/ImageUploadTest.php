<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Canary;
use Tests\Support\ImageFixtures;
use Tests\Support\KnowledgeFixtures;

/*
 * The server side of the editor upload (IMG-01, IMG-02, D-01 to D-03, D-05, D-08): the create and the edit screen
 * take a Livewire temporary upload, store only the re-encoded bytes as a pending image, answer with the reference to
 * insert, and always delete the temporary original. Every name and byte string is fictional; image bytes come from
 * ImageFixtures at runtime.
 */

/**
 * A fake upload with the given name and content, the way a browser would send it.
 */
function imageUploadFile(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * Calls the upload method of the component and returns what it answered.
 *
 * @return array<string, mixed>
 */
function imageUploadAnswer(Testable $component): array
{
    $answer = null;

    $component->call('storeImageUpload')->assertReturned(static function (mixed $value) use (&$answer): bool {
        $answer = $value;

        return true;
    });

    assert(is_array($answer));

    return $answer;
}

/**
 * Whether the object of a temporary upload is still on Livewire's temporary disk.
 */
function imageUploadTemporaryExists(TemporaryUploadedFile $file): bool
{
    return FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($file->getFilename(), false));
}

/**
 * The temporary upload the component holds right now.
 */
function imageUploadHeld(Testable $component): TemporaryUploadedFile
{
    $held = $component->instance()->imageUpload;

    assert($held instanceof TemporaryUploadedFile);

    return $held;
}

/**
 * Every stored image row, read without the Partner scope.
 *
 * @return list<KnowledgeArticleImage>
 */
function imageUploadRows(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => KnowledgeArticleImage::query()->orderBy('created_at')->get()->all());
}

/**
 * The paths of the stored image objects on the default disk.
 *
 * @return list<string>
 */
function imageUploadObjects(): array
{
    return array_values(Storage::disk()->allFiles(KnowledgeArticleImage::STORAGE_DIRECTORY));
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('stores an uploaded PNG as a pending image and hands the create screen the reference to insert', function (): void {
    $component = Livewire::test(CreateKnowledgeArticle::class)
        ->set('imageUpload', imageUploadFile('screenshot-example.png', ImageFixtures::png()));

    $temporary = imageUploadHeld($component);

    expect(imageUploadTemporaryExists($temporary))->toBeTrue();

    $answer = imageUploadAnswer($component);
    $rows = imageUploadRows();

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect($answer['ok'])->toBeTrue()
        ->and($answer['id'])->toBe($row->id)
        ->and($answer['id'])->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
        ->and($answer['alt'])->toBe('screenshot-example')
        ->and($answer['width'])->toBe(40)
        ->and($answer['height'])->toBe(20)
        ->and($answer['reference'])->toBe('![screenshot-example](kokpit-attachment:'.$row->id.')')
        ->and($row->article_id)->toBeNull()
        ->and($row->uploaded_by)->toBe($this->admin->getKey())
        ->and($row->mime_type)->toBe('image/png')
        ->and($row->file_name)->toBe('screenshot-example.png')
        ->and(imageUploadObjects())->toBe([$row->storagePath()])
        ->and($row->sha256)->toBe(hash('sha256', (string) Storage::disk()->get($row->storagePath())))
        ->and(imageUploadTemporaryExists($temporary))->toBeFalse();

    $component->assertSet('imageNoticeVisible', true)->assertSet('imageUpload', null);
});

it('stores an uploaded image from the edit screen of an article the same way', function (): void {
    $article = KnowledgeFixtures::article();

    $component = Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->set('imageUpload', imageUploadFile('screenshot-example.png', ImageFixtures::png()));

    $temporary = imageUploadHeld($component);
    $answer = imageUploadAnswer($component);
    $rows = imageUploadRows();

    expect($rows)->toHaveCount(1)
        ->and($answer['ok'])->toBeTrue()
        ->and($answer['reference'])->toBe('![screenshot-example](kokpit-attachment:'.$rows[0]->id.')')
        ->and($rows[0]->article_id)->toBeNull()
        ->and(imageUploadObjects())->toBe([$rows[0]->storagePath()])
        ->and(imageUploadTemporaryExists($temporary))->toBeFalse();

    $component->assertSet('imageNoticeVisible', true);
});

it('keeps no metadata of a photo: no Exif segment and no marker of the original reach the stored object', function (): void {
    $sentinel = ImageFixtures::sentinel();
    $original = ImageFixtures::gpsJpeg(1, $sentinel);

    expect(str_contains($original, "Exif\0\0"))->toBeTrue()
        ->and(str_contains($original, $sentinel))->toBeTrue();

    $component = Livewire::test(CreateKnowledgeArticle::class)
        ->set('imageUpload', imageUploadFile('photo-example.jpg', $original));

    $answer = imageUploadAnswer($component);
    $rows = imageUploadRows();

    expect($answer['ok'])->toBeTrue()
        ->and($answer['alt'])->toBe('photo-example')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->mime_type)->toBe('image/jpeg');

    $stored = (string) Storage::disk()->get($rows[0]->storagePath());

    expect(str_ends_with($rows[0]->storagePath(), '.jpg'))->toBeTrue()
        ->and(str_contains($stored, "Exif\0\0"))->toBeFalse()
        ->and(str_contains($stored, $sentinel))->toBeFalse();
});

it('refuses an SVG with the Czech message, stores nothing and deletes the temporary original', function (): void {
    $component = Livewire::test(CreateKnowledgeArticle::class)
        ->set('imageUpload', imageUploadFile('screenshot-example.png', ImageFixtures::svg()));

    $temporary = imageUploadHeld($component);

    expect(imageUploadAnswer($component))->toBe([
        'ok' => false,
        'title' => 'Obrázek nebyl nahrán',
        'body' => 'SVG se nenahrává, protože může obsahovat skripty. Použijte PNG, JPEG, WebP nebo GIF.',
    ])
        ->and(imageUploadRows())->toBe([])
        ->and(imageUploadObjects())->toBe([])
        ->and(imageUploadTemporaryExists($temporary))->toBeFalse();

    $component->assertSet('imageNoticeVisible', false);
});

it('refuses a file that is not an image with its own message', function (): void {
    $component = Livewire::test(CreateKnowledgeArticle::class)
        ->set('imageUpload', imageUploadFile('screenshot-example.png', ImageFixtures::renamedText()));

    expect(imageUploadAnswer($component))->toBe([
        'ok' => false,
        'title' => 'Obrázek nebyl nahrán',
        'body' => 'Soubor není podporovaný obrázek. Použijte PNG, JPEG, WebP nebo GIF.',
    ])
        ->and(imageUploadRows())->toBe([]);
});

it('refuses a file over the Livewire upload limit with an error on the property and stores nothing', function (): void {
    $component = Livewire::test(CreateKnowledgeArticle::class)
        ->set('imageUpload', UploadedFile::fake()->create('screenshot-example.png', 10241));

    $component->assertHasErrors('imageUpload');

    expect($component->instance()->imageUpload)->toBeNull()
        ->and(imageUploadRows())->toBe([])
        ->and(imageUploadObjects())->toBe([]);

    // A call without any upload is a failure to process, never a stored image.
    expect(imageUploadAnswer($component))->toBe([
        'ok' => false,
        'title' => 'Obrázek nebyl nahrán',
        'body' => 'Obrázek se nepodařilo zpracovat. Zkuste to znovu.',
    ])
        ->and(imageUploadRows())->toBe([]);

    $component->assertSet('imageNoticeVisible', false);
});

it('answers the failed message and still deletes the temporary original when the storage refuses the object', function (): void {
    Exceptions::fake();

    $component = Livewire::test(CreateKnowledgeArticle::class)
        ->set('imageUpload', imageUploadFile('screenshot-example.png', ImageFixtures::png()));

    $temporary = imageUploadHeld($component);

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('put')->andThrow(UnableToWriteFile::atLocation('knowledge/images/example.png', 'forced'));
    Storage::set((string) config('filesystems.default'), $failing);

    expect(imageUploadAnswer($component))->toBe([
        'ok' => false,
        'title' => 'Obrázek nebyl nahrán',
        'body' => 'Obrázek se nepodařilo zpracovat. Zkuste to znovu.',
    ])
        ->and(imageUploadRows())->toBe([])
        ->and(imageUploadTemporaryExists($temporary))->toBeFalse();

    Exceptions::assertReported(UnableToWriteFile::class);

    $component->assertSet('imageNoticeVisible', false);
});

it('refuses a Partner on the create screen with 403', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $this->actingAs($partner);

    $status = null;

    try {
        Livewire::test(CreateKnowledgeArticle::class);
    } catch (HttpExceptionInterface $refused) {
        $status = $refused->getStatusCode();
    }

    expect($status)->toBe(403)
        ->and(imageUploadRows())->toBe([]);
});
