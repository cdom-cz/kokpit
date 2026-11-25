<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArchiveArticle;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\SecretFixtures;

/*
 * The edit screen of a general knowledge article (KB-02, KB-06, KB-08, VER-01, SEC-01 to SEC-03, D-04, D-06, D-08 to
 * D-10): the entry points, the save through SaveArticle::update(), the stale-save notice, the outcome notifications and
 * the secret guard on edit. Every text is fictional; secret-shaped samples come only from SecretFixtures.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function editArticleSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article row, archived ones included.
 */
function editArticleRow(string $id): KnowledgeArticle
{
    return editArticleSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version row of an article with the given number, or null.
 */
function editArticleVersion(string $id, int $number): ?KnowledgeArticleVersion
{
    return editArticleSystem(static fn (): ?KnowledgeArticleVersion => KnowledgeArticleVersion::query()
        ->where('article_id', $id)
        ->where('version_no', $number)
        ->first());
}

/**
 * The number of version rows of an article.
 */
function editArticleVersionCount(string $id): int
{
    return editArticleSystem(static fn (): int => KnowledgeArticleVersion::query()->where('article_id', $id)->count());
}

/**
 * The edit screen of an article.
 */
function editArticlePage(KnowledgeArticle $article): Testable
{
    return Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()]);
}

/**
 * The view URL of an article.
 */
function editArticleViewUrl(KnowledgeArticle $article): string
{
    return KnowledgeArticleResource::getUrl('view', ['record' => $article]);
}

/**
 * The edit URL of an article.
 */
function editArticleUrl(KnowledgeArticle $article): string
{
    return KnowledgeArticleResource::getUrl('edit', ['record' => $article]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('offers the edit action first in the header of an active article and hides it for an archived one', function (): void {
    $active = KnowledgeFixtures::article(['title' => 'Example active article', 'body_md' => 'Example active body']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article', 'body_md' => 'Example archived body']);

    $page = Livewire::test(ViewKnowledgeArticle::class, ['record' => $active->getRouteKey()])
        ->assertActionVisible('edit')
        ->assertActionHasLabel('edit', 'Upravit článek')
        ->assertActionHasUrl('edit', editArticleUrl($active));

    $names = array_map(static fn (object $action): string => $action->getName(), array_values($page->instance()->getCachedHeaderActions()));

    expect($names[0])->toBe('edit');

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $archived->getRouteKey()])->assertActionHidden('edit');
});

it('offers the edit action as an icon button with label and tooltip on an active row only', function (): void {
    $active = KnowledgeFixtures::article(['title' => 'Example active article']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);

    $component = Livewire::test(ListKnowledgeArticles::class)
        ->assertTableActionVisible('edit', $active)
        ->assertTableActionHasUrl('edit', editArticleUrl($active), $active)
        ->filterTable('show_archived')
        ->assertTableActionHidden('edit', $archived)
        ->assertTableActionVisible('edit', $active);

    $table = $component->instance()->getTable();
    $edit = $table->getAction('edit')?->record($active);

    expect($edit?->isIconButton())->toBeTrue()
        ->and($edit?->getLabel())->toBe('Upravit článek')
        ->and($edit?->getTooltip())->toBe('Upravit článek');

    // The edit icon comes before archive and restore.
    $names = array_map(static fn (object $action): string => $action->getName(), array_values($table->getFlatActions()));

    // The empty-state create action is not a row action.
    expect(array_values(array_diff($names, ['create'])))->toBe(['edit', 'archive', 'restore']);
});

it('links the empty-body text of an active article to its edit screen and gives an archived one no link', function (): void {
    $empty = KnowledgeFixtures::article(['title' => 'Example empty article', 'body_md' => '']);
    $filled = KnowledgeFixtures::article(['title' => 'Example filled article', 'body_md' => 'Example text']);
    $archivedEmpty = KnowledgeFixtures::archived(['title' => 'Example archived empty article', 'body_md' => '']);

    // The header action carries the same URL, so the empty-body link is the second one on the page.
    $emptyPage = Livewire::test(ViewKnowledgeArticle::class, ['record' => $empty->getRouteKey()])->assertSee('Článek zatím nemá žádný text.');
    $filledPage = Livewire::test(ViewKnowledgeArticle::class, ['record' => $filled->getRouteKey()]);
    $archivedPage = Livewire::test(ViewKnowledgeArticle::class, ['record' => $archivedEmpty->getRouteKey()])->assertSee('Článek zatím nemá žádný text.');

    expect(substr_count($emptyPage->html(), 'href="'.editArticleUrl($empty).'"'))->toBe(2)
        ->and(substr_count($filledPage->html(), 'href="'.editArticleUrl($filled).'"'))->toBe(1)
        ->and(substr_count($archivedPage->html(), 'href="'.editArticleUrl($archivedEmpty).'"'))->toBe(0);
});

it('fills the title, the body and the toggle from the article and shows its version under the heading', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example editable article', 'body_md' => 'Example stored body', 'sensitive' => true]);

    editArticlePage($article)
        ->assertFormSet(['title' => 'Example editable article', 'body' => 'Example stored body', 'sensitive' => true])
        ->assertSee('Verze 1');
});

it('saves a changed body as the next version, with its snapshot, and opens the view screen', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example editable article', 'body_md' => 'Example stored body']);

    editArticlePage($article)
        ->fillForm(['body' => 'Example changed body'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Článek byl uložen jako verze 2')
        ->assertRedirect(editArticleViewUrl($article));

    $stored = editArticleRow($article->getKey());
    $version = editArticleVersion($article->getKey(), 2);

    expect($stored->version_no)->toBe(2)
        ->and($stored->body_md)->toBe('Example changed body')
        ->and($version)->not->toBeNull()
        ->and($version?->title)->toBe('Example editable article')
        ->and($version?->body_md)->toBe('Example changed body')
        ->and(editArticleVersionCount($article->getKey()))->toBe(2);
});

it('names the save action Uložit změny, links the cancel action back to the view screen and lists no relation manager', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example editable article']);

    $page = editArticlePage($article)
        ->assertSee('Uložit změny')
        ->assertSee('Zrušit úpravy');

    expect($page->html())->toContain('href="'.editArticleViewUrl($article).'"')
        ->and($page->instance()->getRelationManagers())->toBe([]);
});

it('answers 404 on the edit URL of an archived article and opens the edit URL of an active one', function (): void {
    $active = KnowledgeFixtures::article(['title' => 'Example active article']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);

    $this->get(editArticleUrl($active))->assertOk();
    $this->get(editArticleUrl($archived))->assertNotFound();
});

/**
 * The notifications waiting for the browser, pulled the way Filament's own assertion pulls them.
 *
 * @return list<Notification>
 */
function editArticleNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotifications();

    return array_values($component->notifications->all());
}

/**
 * The text a body is stored as: the normalised form, which is what the editor shows after a save.
 */
function editArticleStored(string $text): string
{
    return app(MarkdownNormaliser::class)->normalise($text);
}

/**
 * The guard callouts of the rendered page (heading, body text and findings), as one string. The editor state sits in
 * the Livewire snapshot of the page, so only the callouts are cut out before a search for a secret.
 */
function editArticleCalloutHtml(string $html): string
{
    preg_match_all('#<[^>]*data-kokpit-guard.*?</ul>#s', $html, $matches);

    return implode("\n", $matches[0]);
}

/**
 * The number of `secret_acknowledged` activity rows, read without the Partner scope.
 */
function editArticleAcknowledgments(): int
{
    return editArticleSystem(static fn (): int => Activity::query()->where('event', 'secret_acknowledged')->count());
}

it('rejects the second of two edit screens saving from the same version, keeps its text and offers the current article in a new tab', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example contested article', 'body_md' => 'Example original body']);
    $viewUrl = editArticleViewUrl($article);

    $first = editArticlePage($article);
    $second = editArticlePage($article);

    $first->fillForm(['body' => 'Example body A'])
        ->call('save')
        ->assertNotified('Článek byl uložen jako verze 2');

    $second->fillForm(['title' => 'Example typed title', 'body' => 'Example body B', 'sensitive' => true])
        ->call('save')
        ->assertNoRedirect()
        ->assertSet('data.title', 'Example typed title')
        ->assertSet('data.body', 'Example body B')
        ->assertSet('data.sensitive', true);

    $notifications = editArticleNotifications();

    expect($notifications)->toHaveCount(1);

    $notice = $notifications[0];
    $actions = array_values($notice->getActions());

    expect($notice->getTitle())->toBe('Článek mezitím někdo změnil')
        ->and($notice->getBody())->toBe('Váš text zůstal v editoru, aby ho šlo zkopírovat. Načtěte aktuální verzi a změny sloučte ručně.')
        ->and($notice->getStatus())->toBe('warning')
        ->and($notice->getDuration())->toBe('persistent')
        ->and($actions)->toHaveCount(1)
        ->and($actions[0]->getName())->toBe('load_current')
        ->and($actions[0]->getLabel())->toBe('Načíst aktuální verzi')
        ->and($actions[0]->getUrl())->toBe($viewUrl)
        ->and($actions[0]->shouldOpenUrlInNewTab())->toBeTrue();

    $stored = editArticleRow($article->getKey());

    expect($stored->version_no)->toBe(2)
        ->and($stored->body_md)->toBe('Example body A')
        ->and($stored->title)->toBe('Example contested article')
        ->and($stored->sensitive)->toBeFalse()
        ->and(editArticleVersionCount($article->getKey()))->toBe(2);

    // The starting version is never refreshed: the same save is refused again.
    $second->call('save')
        ->assertNoRedirect()
        ->assertSet('data.body', 'Example body B');

    expect(editArticleNotifications())->toHaveCount(1)
        ->and(editArticleRow($article->getKey())->version_no)->toBe(2)
        ->and(editArticleRow($article->getKey())->body_md)->toBe('Example body A');
});

it('offers neither an overwrite nor a merge after a stale save', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example contested article', 'body_md' => 'Example original body']);

    $first = editArticlePage($article);
    $second = editArticlePage($article);

    $first->fillForm(['body' => 'Example body A'])->call('save');
    $second->fillForm(['body' => 'Example body B'])->call('save');

    $names = array_map(static fn (object $action): string => $action->getName(), array_values((fn (): array => $this->getFormActions())->call($second->instance())));

    expect($names)->toBe(['save', 'saveAnyway', 'cancel']);
});

it('does not let the browser set the starting version or the starting sensitive flag', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example locked article']);
    $page = editArticlePage($article);

    expect(fn () => $page->set('loadedVersion', 2))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $page->set('loadedSensitive', true))->toThrow(CannotUpdateLockedPropertyException::class);

    $page->assertSet('loadedVersion', 1)->assertSet('loadedSensitive', false);
});

it('notifies that nothing changed for a resave whose prose differs only in line endings and trailing spaces, and writes no version', function (): void {
    // Double-quoted on purpose: no source line of this file ends in spaces and no raw carriage return is stored.
    $typed = "First prose line   \r\nSecond prose line \r\n\r\n```text\ncode line\n```";
    $stored = editArticleStored($typed);

    expect($stored)->not->toBe($typed);

    $edited = CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC');
    $article = KnowledgeFixtures::article(['title' => 'Example resaved article', 'body_md' => $stored, 'edited_at' => $edited]);

    editArticlePage($article)
        ->fillForm(['body' => $typed])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Nic se nezměnilo, nová verze nevznikla.')
        ->assertRedirect(editArticleViewUrl($article));

    $row = editArticleRow($article->getKey());

    expect($row->version_no)->toBe(1)
        ->and($row->body_md)->toBe($stored)
        ->and($row->edited_at->equalTo($edited))->toBeTrue()
        ->and(editArticleVersionCount($article->getKey()))->toBe(1);
});

it('saves a change of the sensitive toggle alone without a version and says so', function (): void {
    $stored = editArticleStored('Example sensitive text');
    $article = KnowledgeFixtures::article(['title' => 'Example flagged article', 'body_md' => $stored, 'sensitive' => true]);

    editArticlePage($article)
        ->assertFormSet(['sensitive' => true])
        ->fillForm(['sensitive' => false])
        ->call('save')
        ->assertNotified('Označení citlivosti bylo uloženo, nová verze nevznikla.')
        ->assertRedirect(editArticleViewUrl($article));

    $row = editArticleRow($article->getKey());

    expect($row->sensitive)->toBeFalse()
        ->and($row->version_no)->toBe(1)
        ->and(editArticleVersionCount($article->getKey()))->toBe(1);
});

it('keeps the sensitive flag on through a content save with the toggle on', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example flagged article', 'body_md' => editArticleStored('Example sensitive text'), 'sensitive' => true]);

    editArticlePage($article)
        ->fillForm(['body' => 'Example changed sensitive text'])
        ->call('save')
        ->assertNotified('Článek byl uložen jako verze 2');

    $row = editArticleRow($article->getKey());

    expect($row->sensitive)->toBeTrue()
        ->and($row->version_no)->toBe(2);
});

it('never clears a flag that was set after the screen was opened with the toggle off', function (): void {
    $stored = editArticleStored('Example plain text');
    $article = KnowledgeFixtures::article(['title' => 'Example racing article', 'body_md' => $stored]);
    $admin = auth()->user();

    $page = editArticlePage($article)->assertFormSet(['sensitive' => false]);

    // Another save marks the article sensitive in the meantime; it changes no content, so the version stays 1.
    $marked = app(SaveArticle::class)->update($admin, $article, ArticleInput::from($article->title, $stored, true), 1);

    expect($marked->outcome)->toBe(SaveOutcome::SensitivityChanged)
        ->and(editArticleRow($article->getKey())->sensitive)->toBeTrue();

    $page->fillForm(['body' => 'Example new text'])
        ->call('save')
        ->assertNotified('Článek byl uložen jako verze 2');

    $row = editArticleRow($article->getKey());

    expect($row->version_no)->toBe(2)
        ->and($row->sensitive)->toBeTrue();
});

it('refuses a Blocking finding on edit with rule and line, keeps the typed text and writes nothing', function (): void {
    $stored = editArticleStored('Example plain text');
    $article = KnowledgeFixtures::article(['title' => 'Example guarded article', 'body_md' => $stored]);
    $secret = SecretFixtures::stripeLiveSecret();
    $body = SecretFixtures::bodyWith($secret, 4);

    $page = editArticlePage($article)
        ->fillForm(['body' => $body])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee('Text obsahuje tajný údaj a nelze ho uložit')
        ->assertSee('Token Stripe — řádek 4')
        ->assertDontSee('Uložit článek i přesto')
        ->assertSet('data.body', $body)
        ->assertNotNotified();

    $callouts = editArticleCalloutHtml($page->html());

    expect($callouts)->toContain('role="alert"')
        ->and($page->instance()->canSaveAnyway())->toBeFalse()
        ->and(editArticleRow($article->getKey())->body_md)->toBe($stored)
        ->and(editArticleVersionCount($article->getKey()))->toBe(1);

    foreach (SecretFixtures::needles($secret) as $needle) {
        expect($callouts)->not->toContain($needle);
    }
});

it('refuses an Ambiguous finding on edit with a warning and saves it anyway for this body only, marking the article sensitive', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example acknowledged article', 'body_md' => editArticleStored('Example plain text')]);
    $body = SecretFixtures::bodyWith(SecretFixtures::passwordAssignment(), 2);

    $page = editArticlePage($article)
        ->fillForm(['body' => $body])
        ->call('save')
        ->assertSee('Text může obsahovat tajný údaj')
        ->assertSee('Možné heslo (password=) — řádek 2')
        ->assertSee('Uložit článek i přesto')
        ->assertNotNotified();

    expect(editArticleVersionCount($article->getKey()))->toBe(1)
        ->and(editArticleAcknowledgments())->toBe(0);

    $page->call('saveAnyway')
        ->assertHasNoFormErrors()
        ->assertNotified('Článek byl uložen jako verze 2 a označen jako citlivý');

    $row = editArticleRow($article->getKey());

    expect($row->version_no)->toBe(2)
        ->and($row->sensitive)->toBeTrue()
        ->and(editArticleAcknowledgments())->toBe(1);
});

it('previews the unsaved body on the edit screen exactly as the view screen will show it', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example preview article', 'body_md' => editArticleStored('Example plain text')]);
    $body = "# Example heading\n\n> [!NOTE]\n> Example callout text\n\n```php\necho 'example';\n```";
    $expected = '<div class="kokpit-prose">'.app(KnowledgeMarkdown::class)->render(editArticleStored($body))->toHtml().'</div>';

    editArticlePage($article)
        ->fillForm(['body' => $body])
        ->set('activeTab', 'preview')
        ->assertSeeHtml($expected)
        ->set('activeTab', 'write')
        ->assertSet('data.body', $body);

    expect(editArticleRow($article->getKey())->version_no)->toBe(1);
});

it('warns about unsaved changes on the edit page', function (): void {
    $page = editArticlePage(KnowledgeFixtures::article(['title' => 'Example warned article']))->instance();

    expect((new ReflectionMethod($page, 'hasUnsavedDataChangesAlert'))->invoke($page))->toBeTrue()
        ->and(Filament::hasUnsavedChangesAlerts())->toBeFalse();
});

it('refuses a save the database rejects with a persistent notice, keeps the typed text and writes nothing', function (): void {
    Exceptions::fake();

    $article = KnowledgeFixtures::article(['title' => 'Example failing article', 'body_md' => 'Example original body']);
    $page = editArticlePage($article);

    // A conflicting version row at the next number: the version insert of the save hits the unique constraint.
    DB::table('knowledge_article_versions')->insert([
        'article_id' => $article->getKey(),
        'version_no' => 2,
        'title' => 'Example conflicting title',
        'body_md' => 'Example conflicting body',
        'author_id' => auth()->id(),
    ]);

    $before = editArticleVersionCount($article->getKey());

    $page->fillForm(['title' => 'Example typed failing title', 'body' => 'Example typed failing body'])
        ->call('save')
        ->assertNoRedirect()
        ->assertSet('data.title', 'Example typed failing title')
        ->assertSet('data.body', 'Example typed failing body');

    $notifications = editArticleNotifications();

    expect($notifications)->toHaveCount(1);

    $notice = $notifications[0];

    expect($notice->getTitle())->toBe('Článek se nepodařilo uložit')
        ->and($notice->getBody())->toBe('Váš text zůstal v editoru, aby ho šlo zkopírovat. Zkuste článek uložit znovu.')
        ->and($notice->getStatus())->toBe('danger')
        ->and($notice->getDuration())->toBe('persistent')
        ->and($notice->getTitle())->not->toContain('Example typed')
        ->and($notice->getBody())->not->toContain('Example typed');

    $stored = editArticleRow($article->getKey());

    expect($stored->version_no)->toBe(1)
        ->and($stored->title)->toBe('Example failing article')
        ->and($stored->body_md)->toBe('Example original body')
        ->and(editArticleVersionCount($article->getKey()))->toBe($before);

    Exceptions::assertReported(static fn (ArticleWriteFailed $failed): bool => $failed->sqlState === '23505'
        && ! str_contains($failed->getMessage(), 'Example typed')
        && $failed->getPrevious() === null);
});
