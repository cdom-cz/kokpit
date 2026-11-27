<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use App\Domain\Knowledge\Queries\ArticleImages;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewArticleVersion;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use App\Http\Controllers\KnowledgeAttachmentController;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\ImageFixtures;
use Tests\Support\KnowledgeFixtures;

/*
 * The screens that show knowledge images (Phase 20, plan 20-08; IMG-01, IMG-02, IMG-03, D-10, D-13, D-14, D-18): the
 * preview tab of the editor, the view screen and the version view all render images through the one renderer with
 * the context of ArticleImages, and every src is the authorised route. Every name is fictional; image bytes come
 * from ImageFixtures or KnowledgeFixtures at runtime.
 */

/**
 * Uploads a fictional PNG through the temporary upload of a create or edit page and stores it as a pending image of
 * the signed-in Admin. Returns the answer of storeImageUpload().
 *
 * @return array<string, mixed>
 */
function knowledgeImageScreensUpload(Testable $component, string $name = 'screenshot-example.png'): array
{
    $answer = null;

    $component
        ->set('imageUpload', UploadedFile::fake()->createWithContent($name, ImageFixtures::png()))
        ->call('storeImageUpload')
        ->assertReturned(static function (mixed $value) use (&$answer): bool {
            $answer = $value;

            return true;
        });

    assert(is_array($answer));

    return $answer;
}

/**
 * The img tags of a rendered page whose src is the internal route of the image.
 *
 * @return list<string>
 */
function knowledgeImageScreensTags(string $html, string $id): array
{
    preg_match_all('#<img\b[^>]*\bsrc="'.preg_quote(KnowledgeAttachmentController::path($id), '#').'"[^>]*>#', $html, $matches);

    return $matches[0];
}

/**
 * Every img tag of a rendered page.
 *
 * @return list<string>
 */
function knowledgeImageScreensAllTags(string $html): array
{
    preg_match_all('#<img\b[^>]*>#', $html, $matches);

    return $matches[0];
}

/**
 * The stored row of an image, read without the Partner scope.
 */
function knowledgeImageScreensRow(string $id): KnowledgeArticleImage
{
    return app(PartnerContext::class)->runAsSystem(static fn (): KnowledgeArticleImage => KnowledgeArticleImage::query()->findOrFail($id));
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('shows an image uploaded on the create screen in the preview of the unsaved article, served by the authorised route', function (): void {
    $component = Livewire::test(CreateKnowledgeArticle::class);
    $answer = knowledgeImageScreensUpload($component);

    expect($answer['ok'])->toBeTrue();

    $id = (string) $answer['id'];

    $component
        ->fillForm(['title' => 'Example preview', 'body' => 'Example text '.$answer['reference']])
        ->set('activeTab', 'preview');

    $tags = knowledgeImageScreensTags($component->html(), $id);

    expect($tags)->toHaveCount(1)
        ->and($tags[0])->toContain('width="40"')
        ->and($tags[0])->toContain('height="20"')
        ->and($tags[0])->toContain('alt="screenshot-example"')
        ->and(knowledgeImageScreensAllTags($component->html()))->toHaveCount(1);

    $stored = (string) Storage::disk()->get(knowledgeImageScreensRow($id)->storagePath());
    $response = $this->get(KnowledgeAttachmentController::path($id))->assertOk();

    expect($stored)->not->toBe('')
        ->and($response->streamedContent())->toBe($stored);
});

it('shows no image in the preview for a reference to the image of another article or to an unknown id', function (): void {
    $foreign = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $unknown = '0198a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    $body = 'Example text ![foreign file](kokpit-attachment:'.$foreign->id.') and ![unknown file](kokpit-attachment:'.$unknown.')';

    $html = Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => 'Example preview', 'body' => $body])
        ->set('activeTab', 'preview')
        ->html();

    expect(knowledgeImageScreensAllTags($html))->toBe([])
        ->and(substr_count($html, 'kokpit-image-missing'))->toBe(2)
        ->and($html)->toContain('foreign file')
        ->and($html)->toContain('unknown file');
});

it('shows no pending image of another Admin in the preview', function (): void {
    $other = Canary::admin();
    $theirs = KnowledgeFixtures::image(null, $other);

    $html = Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => 'Example preview', 'body' => '![their file](kokpit-attachment:'.$theirs->id.')'])
        ->set('activeTab', 'preview')
        ->html();

    expect(knowledgeImageScreensAllTags($html))->toBe([])
        ->and(substr_count($html, 'kokpit-image-missing'))->toBe(1);
});

it('builds an empty context without a query when the body names no image or no owner is given', function (): void {
    $image = KnowledgeFixtures::image(null, $this->admin);
    $images = app(ArticleImages::class);
    $body = '![file](kokpit-attachment:'.$image->id.')';

    expect($images->forBody('Example text without an image', 'any-article')->ids())->toBe([])
        ->and($images->forBody($body, null)->ids())->toBe([])
        ->and($images->forBody($body, null, $this->admin->getKey())->ids())->toBe([$image->id])
        ->and($images->forBody($body, null, Canary::admin()->getKey())->ids())->toBe([]);
});

/**
 * The markup of the muted fallback of an image that may not show.
 */
function knowledgeImageScreensMissing(string $alt): string
{
    return '<span class="kokpit-image-missing">'.$alt.'</span>';
}

/**
 * The Markdown of one image reference with a fictional alt text.
 */
function knowledgeImageScreensRef(string $id, string $alt = 'screenshot example'): string
{
    return '!['.$alt.'](kokpit-attachment:'.$id.')';
}

/**
 * The version row of an article, read without the Partner scope.
 */
function knowledgeImageScreensVersion(KnowledgeArticle $article, int $number): KnowledgeArticleVersion
{
    return app(PartnerContext::class)->runAsSystem(static fn (): KnowledgeArticleVersion => KnowledgeArticleVersion::query()
        ->where('article_id', $article->getKey())
        ->where('version_no', $number)
        ->firstOrFail());
}

/**
 * Deletes an image for good, the way the delete of Phase 20 does: the reference rows, the row and the object.
 */
function knowledgeImageScreensDelete(KnowledgeArticleImage $image): void
{
    $path = $image->storagePath();

    app(PartnerContext::class)->runAsSystem(static function () use ($image): void {
        KnowledgeArticleVersionImage::query()->where('image_id', $image->getKey())->delete();
        KnowledgeArticleImage::query()->whereKey($image->getKey())->delete();
    });

    Storage::disk()->delete($path);
}

/**
 * An article whose version 1 names an image of its own and whose version 2 does not, with the image indexed for
 * version 1 only (an image that only an earlier version still references).
 *
 * @return array{article: KnowledgeArticle, image: KnowledgeArticleImage}
 */
function knowledgeImageScreensHistory(User $admin): array
{
    $id = (string) Str::uuid7();

    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example history', 'body' => 'Example first '.knowledgeImageScreensRef($id)],
        ['title' => 'Example history', 'body' => 'Example second without an image'],
    ]);

    $image = KnowledgeFixtures::image($article, $admin, ['id' => $id]);
    KnowledgeFixtures::reference(knowledgeImageScreensVersion($article, 1), $image);

    return ['article' => $article, 'image' => $image];
}

/**
 * The notifications sent so far (read once; the mailbox is flushed).
 *
 * @return list<Notification>
 */
function knowledgeImageScreensNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotifications();

    return array_values($component->notifications->all());
}

it('shows the images of the article on the view screen and the rest as the muted fallback text', function (): void {
    $ownId = (string) Str::uuid7();
    $foreign = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $pending = KnowledgeFixtures::image(null, $this->admin);
    $unknown = (string) Str::uuid7();

    $article = KnowledgeFixtures::article(['title' => 'Example illustrated', 'body_md' => implode("\n\n", [
        knowledgeImageScreensRef($ownId, 'own picture'),
        knowledgeImageScreensRef($foreign->id, 'foreign picture'),
        knowledgeImageScreensRef($pending->id, 'pending picture'),
        knowledgeImageScreensRef($unknown, 'unknown picture'),
    ])]);

    KnowledgeFixtures::image($article, $this->admin, ['id' => $ownId]);

    $html = Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])->html();
    $tags = knowledgeImageScreensTags($html, $ownId);

    expect($tags)->toHaveCount(1)
        ->and($tags[0])->toContain('width="2"')
        ->and($tags[0])->toContain('height="1"')
        ->and($tags[0])->toContain('alt="own picture"')
        ->and(knowledgeImageScreensAllTags($html))->toHaveCount(1)
        ->and($html)->toContain(knowledgeImageScreensMissing('foreign picture'))
        ->and($html)->toContain(knowledgeImageScreensMissing('pending picture'))
        ->and($html)->toContain(knowledgeImageScreensMissing('unknown picture'))
        ->and($html)->not->toContain($foreign->id)
        ->and($html)->not->toContain($pending->id);
});

it('shows in the preview of the edit screen the own images and the own pending images of the Admin, never another article', function (): void {
    $ownId = (string) Str::uuid7();
    $article = KnowledgeFixtures::article(['title' => 'Example editable', 'body_md' => 'Example text '.knowledgeImageScreensRef($ownId, 'own picture')]);
    KnowledgeFixtures::image($article, $this->admin, ['id' => $ownId]);

    $pending = KnowledgeFixtures::image(null, $this->admin);
    $foreign = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $theirs = KnowledgeFixtures::image(null, Canary::admin());

    $body = implode("\n\n", [
        knowledgeImageScreensRef($ownId, 'own picture'),
        knowledgeImageScreensRef($pending->id, 'pending picture'),
        knowledgeImageScreensRef($foreign->id, 'foreign picture'),
        knowledgeImageScreensRef($theirs->id, 'their picture'),
    ]);

    $html = Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['body' => $body])
        ->set('activeTab', 'preview')
        ->html();

    expect(knowledgeImageScreensTags($html, $ownId))->toHaveCount(1)
        ->and(knowledgeImageScreensTags($html, $pending->id))->toHaveCount(1)
        ->and(knowledgeImageScreensAllTags($html))->toHaveCount(2)
        ->and($html)->toContain(knowledgeImageScreensMissing('foreign picture'))
        ->and($html)->toContain(knowledgeImageScreensMissing('their picture'));
});

it('shows an image on the version view while an earlier version still references it, and its alt text once it is deleted', function (): void {
    ['article' => $article, 'image' => $image] = knowledgeImageScreensHistory($this->admin);

    $first = Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '1'])->assertOk()->html();
    $second = Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '2'])->assertOk()->html();

    expect(knowledgeImageScreensTags($first, $image->id))->toHaveCount(1)
        ->and(knowledgeImageScreensAllTags($second))->toBe([]);

    knowledgeImageScreensDelete($image);

    $afterDelete = Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '1'])->assertOk()->html();

    expect(knowledgeImageScreensAllTags($afterDelete))->toBe([])
        ->and($afterDelete)->toContain(knowledgeImageScreensMissing('screenshot example'));
});

it('shows the image again after the restore of the version that references it', function (): void {
    ['article' => $article, 'image' => $image] = knowledgeImageScreensHistory($this->admin);

    Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '1'])
        ->mountAction('restoreVersion')
        ->callMountedAction()
        ->assertNotified('Verze 1 byla obnovena jako verze 3');

    $html = Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])->html();
    $current = Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '3'])->html();

    expect(knowledgeImageScreensTags($html, $image->id))->toHaveCount(1)
        ->and(knowledgeImageScreensTags($current, $image->id))->toHaveCount(1);
});

it('restores a version whose image was deleted without an error and shows the alt text', function (): void {
    ['article' => $article, 'image' => $image] = knowledgeImageScreensHistory($this->admin);

    knowledgeImageScreensDelete($image);
    knowledgeImageScreensNotifications();

    Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '1'])
        ->mountAction('restoreVersion')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $statuses = array_map(static fn (Notification $notification): string => (string) $notification->getStatus(), knowledgeImageScreensNotifications());
    $html = Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])->html();

    expect($statuses)->not->toBe([])
        ->and(array_unique($statuses))->toBe(['success'])
        ->and(knowledgeImageScreensAllTags($html))->toBe([])
        ->and($html)->toContain(knowledgeImageScreensMissing('screenshot example'));
});

it('puts no bucket host, presigned query or temporary upload path in the view, the version view or the preview', function (): void {
    config([
        'filesystems.disks.s3.endpoint' => 'https://bucket.example.com',
        'filesystems.disks.s3.url' => 'https://bucket.example.com',
    ]);

    ['article' => $article, 'image' => $image] = knowledgeImageScreensHistory($this->admin);

    $create = Livewire::test(CreateKnowledgeArticle::class);
    $answer = knowledgeImageScreensUpload($create);

    $create
        ->fillForm(['title' => 'Example preview', 'body' => 'Example '.$answer['reference']])
        ->set('activeTab', 'preview');

    $preview = KnowledgeArticleResource::previewHtml('Example '.$answer['reference'])->toHtml();

    $pages = [
        'view screen' => Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])->html(),
        'version view' => Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '1'])->html(),
        'create screen' => $create->html(),
        'preview html' => $preview,
    ];

    expect(knowledgeImageScreensTags($pages['version view'], $image->id))->toHaveCount(1)
        ->and(knowledgeImageScreensTags($pages['create screen'], (string) $answer['id']))->toHaveCount(1)
        ->and(knowledgeImageScreensTags($preview, (string) $answer['id']))->toHaveCount(1);

    foreach ($pages as $html) {
        expect(str_contains($html, 'bucket.example.com'))->toBeFalse()
            ->and(str_contains($html, 'X-Amz-'))->toBeFalse()
            ->and(str_contains($html, 'livewire-tmp'))->toBeFalse();
    }
});

it('refuses a Partner on the view screen and the version view', function (): void {
    ['article' => $article] = knowledgeImageScreensHistory($this->admin);

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])->assertForbidden();
    Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => '1'])->assertForbidden();
});
