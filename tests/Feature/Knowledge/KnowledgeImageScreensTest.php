<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Queries\ArticleImages;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Http\Controllers\KnowledgeAttachmentController;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
