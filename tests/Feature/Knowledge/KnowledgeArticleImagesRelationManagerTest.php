<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\DeleteImageForGood;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\RelationManagers\KnowledgeArticleHistoryRelationManager;
use App\Filament\RelationManagers\KnowledgeArticleImagesRelationManager;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use App\Http\Controllers\KnowledgeAttachmentController;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The Obrázky tab of the article view screen (Phase 20, plan 20-11; IMG-02, IMG-03, D-10, D-19): the images of one
 * article with their usage, a thumbnail through the authorised route only, and the delete for good with a reason.
 * Every name and text is fictional; the bucket host is a fictional example.com host.
 */

const KNOWLEDGE_IMAGES_MANAGER_REASON = 'Example reason: a screenshot showed a key';

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function knowledgeImagesManagerSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

function knowledgeImagesManagerImageCount(): int
{
    return knowledgeImagesManagerSystem(static fn (): int => KnowledgeArticleImage::query()->count());
}

function knowledgeImagesManagerReferenceCount(): int
{
    return knowledgeImagesManagerSystem(static fn (): int => KnowledgeArticleVersionImage::query()->count());
}

/**
 * The version row of an article with the given number.
 */
function knowledgeImagesManagerVersion(KnowledgeArticle $article, int $number): KnowledgeArticleVersion
{
    return knowledgeImagesManagerSystem(static fn (): KnowledgeArticleVersion => KnowledgeArticleVersion::query()
        ->where('article_id', $article->getKey())
        ->where('version_no', $number)
        ->firstOrFail());
}

/**
 * The relation manager as the Admin sees it on the view screen of the article.
 */
function knowledgeImagesManagerTable(KnowledgeArticle $article): Testable
{
    return Livewire::test(KnowledgeArticleImagesRelationManager::class, [
        'ownerRecord' => $article,
        'pageClass' => ViewKnowledgeArticle::class,
    ]);
}

/**
 * The text of the mounted modal's schema (the page html does not carry the modal of an action).
 */
function knowledgeImagesManagerModalText(Testable $page): string
{
    return html_entity_decode(strip_tags((string) $page->instance()->getSchema('mountedActionSchema0')->toHtml()), ENT_QUOTES | ENT_HTML5);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('deletes an image for good from the table and the body shows its alt text afterwards', function (): void {
    $id = (string) Str::uuid7();
    $article = KnowledgeFixtures::article(['title' => 'Example illustrated', 'body_md' => 'Example text ![example alt](kokpit-attachment:'.$id.')']);
    $image = KnowledgeFixtures::image($article, $this->admin, ['id' => $id]);
    KnowledgeFixtures::reference(knowledgeImagesManagerVersion($article, 1), $image);
    $path = $image->storagePath();

    $table = knowledgeImagesManagerTable($article)->assertCanSeeTableRecords([$image]);

    expect(Storage::disk()->exists($path))->toBeTrue();

    $table
        ->callTableAction('deleteForGood', $image, ['reason' => KNOWLEDGE_IMAGES_MANAGER_REASON])
        ->assertNotified('Obrázek byl smazán')
        ->assertCanNotSeeTableRecords([$image]);

    expect(Storage::disk()->exists($path))->toBeFalse()
        ->and(knowledgeImagesManagerImageCount())->toBe(0)
        ->and(knowledgeImagesManagerReferenceCount())->toBe(0);

    $html = Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])->html();

    expect($html)->toContain('<span class="kokpit-image-missing">example alt</span>')
        ->and($html)->not->toContain($id);
});

it('lists the images of the article with their usage, dimensions and size, newest first', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => 'Example first body'],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $current = KnowledgeFixtures::image($article, $this->admin, ['created_at' => now()->subDays(3), 'file_name' => 'current-example.png', 'width' => 40, 'height' => 20, 'size_bytes' => 2048]);
    $older = KnowledgeFixtures::image($article, $this->admin, ['created_at' => now()->subDays(2), 'file_name' => 'older-example.png']);
    $unused = KnowledgeFixtures::image($article, $this->admin, ['created_at' => now()->subDay(), 'file_name' => 'unused-example.png']);

    KnowledgeFixtures::reference(knowledgeImagesManagerVersion($article, 1), $current);
    KnowledgeFixtures::reference(knowledgeImagesManagerVersion($article, 2), $current);
    KnowledgeFixtures::reference(knowledgeImagesManagerVersion($article, 1), $older);

    knowledgeImagesManagerTable($article)
        ->assertCanSeeTableRecords([$unused, $older, $current], inOrder: true)
        ->assertTableColumnFormattedStateSet('usage', 'V aktuálním textu', $current)
        ->assertTableColumnFormattedStateSet('usage', 'Jen ve starších verzích', $older)
        ->assertTableColumnFormattedStateSet('usage', 'Nepoužitý', $unused)
        ->assertTableColumnStateSet('dimensions', '40 × 20 px', $current)
        ->assertTableColumnFormattedStateSet('size_bytes', '2 KB', $current)
        ->assertSee('current-example.png');
});

it('lists no pending image and no image of another article', function (): void {
    $article = KnowledgeFixtures::article();
    $other = KnowledgeFixtures::article(['title' => 'Example other article']);

    $own = KnowledgeFixtures::image($article, $this->admin);
    $pending = KnowledgeFixtures::image(null, $this->admin);
    $foreign = KnowledgeFixtures::image($other, $this->admin);

    knowledgeImagesManagerTable($article)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$pending, $foreign]);
});

it('feeds the thumbnail the absolute route and puts no bucket host, presigned query or storage path in the table', function (): void {
    config([
        'filesystems.disks.s3.endpoint' => 'https://bucket.example.com',
        'filesystems.disks.s3.url' => 'https://bucket.example.com',
    ]);

    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);

    $html = knowledgeImagesManagerTable($article)->assertCanSeeTableRecords([$image])->html();

    expect($html)->toContain(route(KnowledgeAttachmentController::ROUTE, ['attachment' => $image->getKey()]))
        ->and($html)->not->toContain('bucket.example.com')
        ->and($html)->not->toContain('X-Amz-')
        ->and($html)->not->toContain(KnowledgeArticleImage::STORAGE_DIRECTORY);
});

it('shows the empty state of an article without images', function (): void {
    $article = KnowledgeFixtures::article();

    knowledgeImagesManagerTable($article)
        ->assertSee('Článek zatím nemá žádné obrázky')
        ->assertSee('Vložte obrázek v editoru tlačítkem, vložením ze schránky nebo přetažením.');
});

it('pages a long list: the first page shows ten rows', function (): void {
    $article = KnowledgeFixtures::article();

    foreach (range(1, 12) as $number) {
        KnowledgeFixtures::image($article, $this->admin, ['created_at' => now()->subMinutes($number)]);
    }

    knowledgeImagesManagerTable($article)->assertCountTableRecords(10);
});

it('opens the delete modal with the warnings and the reason field of the contract', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);

    $page = knowledgeImagesManagerTable($article)->mountTableAction('deleteForGood', $image);
    $mounted = $page->instance()->getMountedAction();
    $text = knowledgeImagesManagerModalText($page);

    expect($mounted->getModalHeading())->toBe('Smazat obrázek navždy?')
        ->and($mounted->getModalSubmitAction()?->getColor())->toBe('danger')
        ->and($text)->toContain(__('knowledge.images.delete.irreversible'))
        ->and($text)->toContain(__('knowledge.images.delete.rotate'))
        ->and($text)->toContain('Důvod smazání')
        ->and($text)->toContain(__('knowledge.images.delete.reason_helper'));
});

it('keeps the image and the modal when the reason is empty and shows the message under the field', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);

    $page = knowledgeImagesManagerTable($article)
        ->callTableAction('deleteForGood', $image, ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => ['required']]);

    expect(Storage::disk()->exists($image->storagePath()))->toBeTrue()
        ->and(knowledgeImagesManagerImageCount())->toBe(1)
        ->and(implode(' ', $page->errors()->all()))->toContain('Vyplňte důvod smazání.');
});

it('keeps the image and shows the refusal of the action under the reason field', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);

    $page = knowledgeImagesManagerTable($article)
        ->callTableAction('deleteForGood', $image, ['reason' => "Example reason\0"]);

    expect(Storage::disk()->exists($image->storagePath()))->toBeTrue()
        ->and(knowledgeImagesManagerImageCount())->toBe(1)
        ->and(implode(' ', $page->errors()->all()))->toContain(__('knowledge.errors.nul_character'));
});

it('refuses a reason longer than the maximum', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);

    knowledgeImagesManagerTable($article)
        ->callTableAction('deleteForGood', $image, ['reason' => str_repeat('a', DeleteImageForGood::MAX_REASON_LENGTH + 1)])
        ->assertHasTableActionErrors(['reason' => ['max']]);

    expect(knowledgeImagesManagerImageCount())->toBe(1);
});

it('answers a failing storage with the danger notification and changes nothing', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);
    $real = Storage::disk();
    $disk = (string) config('filesystems.default');

    $table = knowledgeImagesManagerTable($article);

    $double = Mockery::mock(Filesystem::class);
    $double->shouldReceive('delete')->once()->andReturn(false);
    Storage::set($disk, $double);

    $table
        ->callTableAction('deleteForGood', $image, ['reason' => KNOWLEDGE_IMAGES_MANAGER_REASON])
        ->assertNotified('Obrázek se nepodařilo smazat. Nic se nezměnilo, zkuste to znovu.');

    expect($real->exists($image->storagePath()))->toBeTrue()
        ->and(knowledgeImagesManagerImageCount())->toBe(1);
});

it('lists the delete in the history tab of the article without the reason', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);

    knowledgeImagesManagerTable($article)
        ->callTableAction('deleteForGood', $image, ['reason' => KNOWLEDGE_IMAGES_MANAGER_REASON]);

    Livewire::test(KnowledgeArticleHistoryRelationManager::class, ['ownerRecord' => $article, 'pageClass' => ViewKnowledgeArticle::class])
        ->assertSee('Obrázek smazán navždy')
        ->assertDontSee(KNOWLEDGE_IMAGES_MANAGER_REASON)
        ->assertDontSee('screenshot-example.png');
});

it('refuses a Partner: no tab, no component and no view screen', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    expect(knowledgeImagesManagerSystem(static fn (): bool => KnowledgeArticleImagesRelationManager::canViewForRecord($article, ViewKnowledgeArticle::class)))->toBeFalse();

    Livewire::test(KnowledgeArticleImagesRelationManager::class, ['ownerRecord' => $article, 'pageClass' => ViewKnowledgeArticle::class])
        ->assertForbidden();

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->assertForbidden();

    expect(KnowledgeArticleResource::getRelations())->toContain(KnowledgeArticleImagesRelationManager::class)
        ->and(Storage::disk()->exists($image->storagePath()))->toBeTrue()
        ->and(knowledgeImagesManagerImageCount())->toBe(1);
});
