<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\DeleteImageForGood;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Http\Controllers\KnowledgeAttachmentController;
use Filament\Facades\Filament;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * Deleting an image for good (Phase 20, plan 20-10; IMG-03, D-10, D-19): the object, the row and every reference of
 * every version go in one transaction, the history holds one metadata-only row, and a bad reason, a Partner or a
 * failure leaves everything as it was. Every text is fictional.
 */

const DELETE_IMAGE_FOR_GOOD_REASON = 'Example reason: a screenshot showed a key';

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function deleteImageForGoodSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

function deleteImageForGoodImageCount(): int
{
    return deleteImageForGoodSystem(static fn (): int => KnowledgeArticleImage::query()->count());
}

function deleteImageForGoodReferenceCount(): int
{
    return deleteImageForGoodSystem(static fn (): int => KnowledgeArticleVersionImage::query()->count());
}

/**
 * The activity rows of an article with the given event, oldest first.
 *
 * @return list<Activity>
 */
function deleteImageForGoodActivity(string $articleId, string $event): array
{
    return deleteImageForGoodSystem(static fn (): array => Activity::query()
        ->where('subject_id', $articleId)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * Every activity row as one JSON text, to search it for texts that must never be there.
 */
function deleteImageForGoodActivityText(): string
{
    return (string) json_encode(DB::table('activity_log')->get()->all());
}

/**
 * An article with two versions that both reference one image (the object is stored).
 *
 * @return array{KnowledgeArticle, KnowledgeArticleImage}
 */
function deleteImageForGoodSetup(User $uploader, bool $archived = false): array
{
    $article = $archived ? KnowledgeFixtures::archived(['version_no' => 2]) : KnowledgeFixtures::article(['version_no' => 2]);
    $image = KnowledgeFixtures::image($article, $uploader);

    $versions = deleteImageForGoodSystem(static fn (): array => KnowledgeArticleVersion::query()
        ->where('article_id', $article->getKey())
        ->orderBy('version_no')
        ->get()
        ->all());

    foreach ($versions as $version) {
        KnowledgeFixtures::reference($version, $image);
    }

    return [$article, $image];
}

/**
 * The messages of the field errors a callback throws, or an empty list.
 *
 * @param  Closure(): mixed  $callback
 * @return array<string, array<int, string>>
 */
function deleteImageForGoodErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * Starts the live capture of every log write and proves it with a probe entry.
 */
function deleteImageForGoodCapturedLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
        $logs->append((string) $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
    });

    Log::info('deleteImageForGood capture probe');

    expect(implode("\n", $logs->getArrayCopy()))->toContain('deleteImageForGood capture probe');

    $logs->exchangeArray([]);

    return $logs;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->delete = app(DeleteImageForGood::class);
    $this->logs = deleteImageForGoodCapturedLogs();
});

it('deletes the object, the row and every reference of every version and writes one metadata-only history row', function (): void {
    [$article, $image] = deleteImageForGoodSetup($this->admin);
    $path = $image->storagePath();
    $fileName = $image->file_name;

    expect(Storage::disk()->exists($path))->toBeTrue()
        ->and(deleteImageForGoodReferenceCount())->toBe(2);

    $this->delete->handle($this->admin, $image, '  '.DELETE_IMAGE_FOR_GOOD_REASON.'  ');

    $events = deleteImageForGoodActivity($article->id, 'image_deleted');

    expect(Storage::disk()->exists($path))->toBeFalse()
        ->and(deleteImageForGoodImageCount())->toBe(0)
        ->and(deleteImageForGoodReferenceCount())->toBe(0)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->causer_id)->toBe($this->admin->id)
        ->and($events[0]->properties->toArray())->toBe(['reason_recorded' => true])
        ->and(__('kokpit.activity.events.image_deleted'))->toBe('Obrázek smazán navždy');

    $this->get(KnowledgeAttachmentController::path($image->getKey()))->assertNotFound();

    $stored = deleteImageForGoodActivityText();
    $logged = implode("\n", $this->logs->getArrayCopy());

    foreach ([DELETE_IMAGE_FOR_GOOD_REASON, $fileName] as $text) {
        expect($stored)->not->toContain($text)
            ->and($logged)->not->toContain($text);
    }
});

it('deletes the image of an archived article the same way', function (): void {
    [$article, $image] = deleteImageForGoodSetup($this->admin, archived: true);

    $this->delete->handle($this->admin, $image, DELETE_IMAGE_FOR_GOOD_REASON);

    expect(Storage::disk()->exists($image->storagePath()))->toBeFalse()
        ->and(deleteImageForGoodImageCount())->toBe(0)
        ->and(deleteImageForGoodReferenceCount())->toBe(0)
        ->and(deleteImageForGoodActivity($article->id, 'image_deleted'))->toHaveCount(1);
});

it('leaves the other images of the article and their references alone', function (): void {
    [$article, $image] = deleteImageForGoodSetup($this->admin);
    $other = KnowledgeFixtures::image($article, $this->admin);
    $version = deleteImageForGoodSystem(static fn (): KnowledgeArticleVersion => KnowledgeArticleVersion::query()
        ->where('article_id', $article->getKey())
        ->where('version_no', 1)
        ->firstOrFail());
    KnowledgeFixtures::reference($version, $other);

    $this->delete->handle($this->admin, $image, DELETE_IMAGE_FOR_GOOD_REASON);

    expect(Storage::disk()->exists($other->storagePath()))->toBeTrue()
        ->and(deleteImageForGoodImageCount())->toBe(1)
        ->and(deleteImageForGoodReferenceCount())->toBe(1);
});
