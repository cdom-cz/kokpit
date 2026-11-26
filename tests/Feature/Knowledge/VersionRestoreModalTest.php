<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\RedactVersion;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewArticleVersion;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
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
 * The restore modal of the history screens (Phase 19, plan 19-11; VER-03, D-11, SEC-01 restore clause): the diff first,
 * then a new version through RestoreVersion, with every refusal shown inside the modal. Every text is fictional;
 * secret-shaped samples come only from SecretFixtures.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function versionRestoreModalSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

function versionRestoreModalArticle(string $id): KnowledgeArticle
{
    return versionRestoreModalSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version rows of an article, oldest first.
 *
 * @return list<KnowledgeArticleVersion>
 */
function versionRestoreModalRows(string $id): array
{
    return versionRestoreModalSystem(static fn (): array => KnowledgeArticleVersion::query()
        ->where('article_id', $id)
        ->orderBy('version_no')
        ->get()
        ->all());
}

/**
 * The activity rows of an article with the given event.
 *
 * @return list<Activity>
 */
function versionRestoreModalActivity(string $id, string $event): array
{
    return versionRestoreModalSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->where('event', $event)
        ->get()
        ->all());
}

/**
 * A three-version article: version 1 and 2 are plain, version 3 is the current one.
 */
function versionRestoreModalThree(): KnowledgeArticle
{
    return KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => "Example line one\nExample line two"],
        ['title' => 'Example second title', 'body' => 'Example second body'],
        ['title' => 'Example third title', 'body' => 'Example third body'],
    ]);
}

/**
 * The version view of one version as a Livewire test.
 */
function versionRestoreModalView(KnowledgeArticle $article, int $version): Testable
{
    return Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => (string) $version]);
}

/**
 * The versions table as a Livewire test.
 */
function versionRestoreModalList(KnowledgeArticle $article): Testable
{
    return Livewire::test(ListArticleVersions::class, ['record' => $article->getKey()]);
}

/**
 * The text of the mounted modal's schema (the page html does not carry the modal of an action).
 */
function versionRestoreModalText(Testable $page): string
{
    return html_entity_decode(strip_tags((string) $page->instance()->getSchema('mountedActionSchema0')->toHtml()), ENT_QUOTES | ENT_HTML5);
}

/**
 * The raw html of the mounted modal's schema.
 */
function versionRestoreModalHtml(Testable $page): string
{
    return (string) $page->instance()->getSchema('mountedActionSchema0')->toHtml();
}

/**
 * The notifications sent so far, oldest first (read once; the mailbox is flushed).
 *
 * @return list<Notification>
 */
function versionRestoreModalNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotifications();

    return array_values($component->notifications->all());
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('shows the diff of the current version against the chosen one, then restores it as the next version', function (): void {
    $article = versionRestoreModalThree();

    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion');
    $mounted = $page->instance()->getMountedAction();
    $text = versionRestoreModalText($page);

    expect($mounted->getModalHeading())->toBe('Obnovit verzi 1?')
        ->and($mounted->getModalDescription())->toContain('nová verze 4')
        ->and($text)->toContain('Změny oproti aktuální verzi 3')
        ->and($text)->toContain('Example line one')
        ->and($text)->toContain('Example third body');

    $page->callMountedAction()
        ->assertNotified('Verze 1 byla obnovena jako verze 4')
        ->assertRedirect(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 4]));

    $rows = versionRestoreModalRows($article->id);
    $stored = versionRestoreModalArticle($article->id);

    expect($rows)->toHaveCount(4)
        ->and($rows[3]->title)->toBe('Example first title')
        ->and($rows[3]->body_md)->toBe("Example line one\nExample line two")
        ->and($rows[3]->restored_from_version_no)->toBe(1)
        ->and($rows[2]->body_md)->toBe('Example third body')
        ->and($stored->version_no)->toBe(4)
        ->and($stored->body_md)->toBe("Example line one\nExample line two");
});

it('restores from the Další akce group of a row of the versions table', function (): void {
    $article = versionRestoreModalThree();
    $rows = versionRestoreModalRows($article->id);

    versionRestoreModalList($article)
        ->assertActionVisible(TestAction::make('restoreVersion')->table($rows[0]))
        ->mountAction(TestAction::make('restoreVersion')->table($rows[0]))
        ->callMountedAction()
        ->assertNotified('Verze 1 byla obnovena jako verze 4')
        ->assertRedirect(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 4]));

    expect(versionRestoreModalRows($article->id))->toHaveCount(4);
});

it('hides the restore on the current version and on every version of an archived article', function (): void {
    $article = versionRestoreModalThree();
    $rows = versionRestoreModalRows($article->id);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article', 'body_md' => 'Example archived body', 'version_no' => 2]);

    versionRestoreModalView($article, 3)->assertActionHidden('restoreVersion');
    versionRestoreModalView($article, 1)->assertActionVisible('restoreVersion');
    versionRestoreModalView($archived, 1)->assertActionHidden('restoreVersion');

    versionRestoreModalList($article)
        ->assertActionHidden(TestAction::make('restoreVersion')->table($rows[2]))
        ->assertActionVisible(TestAction::make('restoreVersion')->table($rows[1]));
});

it('gives the Další akce trigger an aria-label equal to its tooltip', function (): void {
    $article = versionRestoreModalThree();

    $groups = array_values(array_filter(
        versionRestoreModalList($article)->instance()->getTable()->getRecordActions(),
        static fn (mixed $action): bool => $action instanceof ActionGroup,
    ));

    expect($groups)->toHaveCount(1)
        ->and((string) $groups[0]->getTooltip())->toBe('Další akce')
        ->and($groups[0]->getExtraAttributes()['aria-label'] ?? null)->toBe($groups[0]->getTooltip());
});

it('says that a version equal to the current text changes nothing and offers no submit', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example same title', 'body' => 'Example same body'],
        ['title' => 'Example other title', 'body' => 'Example other body'],
        ['title' => 'Example same title', 'body' => 'Example same body'],
    ]);

    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion');

    expect(versionRestoreModalText($page))->toContain('Tato verze se shoduje s aktuálním textem. Nová verze nevznikne.')
        ->and($page->instance()->getMountedAction()->getModalSubmitAction())->toBeNull();
});

it('refuses a restore as stale when the article moved on after the modal opened, and keeps the modal open', function (): void {
    $article = versionRestoreModalThree();

    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion');

    app(SaveArticle::class)->update($this->admin, $article, ArticleInput::from('Example changed title', 'Example changed body', false), 3, 0);

    $page->callMountedAction()->assertNoRedirect();

    $notice = versionRestoreModalNotifications()[0];

    expect($notice->getTitle())->toBe('Článek mezitím někdo změnil')
        ->and($notice->getStatus())->toBe('danger')
        ->and($notice->getDuration())->toBe('persistent')
        ->and($page->instance()->getMountedAction())->not->toBeNull()
        ->and(versionRestoreModalRows($article->id))->toHaveCount(4)
        ->and(versionRestoreModalArticle($article->id)->title)->toBe('Example changed title');
});

it('keeps the modal open on an unambiguous secret, names rule and line only and offers the redaction', function (): void {
    $secret = SecretFixtures::pemPrivateKey();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example old title', 'body' => SecretFixtures::bodyWith($secret, 2)],
        ['title' => 'Example new title', 'body' => 'Example clean body'],
    ]);

    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion')->callMountedAction()->assertNoRedirect();
    $text = versionRestoreModalText($page);
    $html = versionRestoreModalHtml($page);

    expect($page->instance()->getMountedAction())->not->toBeNull()
        ->and($page->instance()->getMountedAction()->getModalSubmitAction())->toBeNull()
        ->and($text)->toContain('Verzi nelze obnovit, obsahuje tajný údaj')
        ->and($text)->toContain('Soukromý klíč (PEM) — řádek 2')
        ->and($text)->toContain('Redigovat verzi')
        ->and($html)->toContain('role="alert"')
        ->and(versionRestoreModalRows($article->id))->toHaveCount(2)
        ->and(versionRestoreModalArticle($article->id)->version_no)->toBe(2);

    foreach (SecretFixtures::needles($secret) as $needle) {
        expect($html)->not->toContain($needle);
    }
});

it('acknowledges an ambiguous finding for this one restore only and marks the article sensitive', function (): void {
    $secret = SecretFixtures::passwordAssignment();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example old title', 'body' => SecretFixtures::bodyWith($secret, 2)],
        ['title' => 'Example new title', 'body' => 'Example clean body'],
    ]);

    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion')->callMountedAction()->assertNoRedirect();
    $mounted = $page->instance()->getMountedAction();

    expect($mounted)->not->toBeNull()
        ->and($mounted->getModalSubmitActionLabel())->toBe('Obnovit i přesto')
        ->and(versionRestoreModalText($page))->toContain('Obnovovaná verze může obsahovat tajný údaj')
        ->and(versionRestoreModalText($page))->toContain('Možné heslo (password=) — řádek 2')
        ->and(versionRestoreModalRows($article->id))->toHaveCount(2);

    $page->callMountedAction()
        ->assertNotified('Verze 1 byla obnovena jako verze 3 a článek byl označen jako citlivý')
        ->assertRedirect(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 3]));

    $stored = versionRestoreModalArticle($article->id);

    expect($stored->version_no)->toBe(3)
        ->and($stored->sensitive)->toBeTrue()
        ->and(versionRestoreModalActivity($article->id, 'secret_acknowledged'))->toHaveCount(1);

    // The acknowledgment was spent: a new modal starts without it.
    $again = versionRestoreModalView($article, 1)->mountAction('restoreVersion');

    expect($again->instance()->restoreAcknowledgeableHash)->toBeNull()
        ->and($again->instance()->restoreFindings)->toBe([]);
});

it('never carries an acknowledgment over to a version that was redacted after the refusal', function (): void {
    $secret = SecretFixtures::passwordAssignment();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example old title', 'body' => SecretFixtures::bodyWith($secret, 2)],
        ['title' => 'Example new title', 'body' => 'Example clean body'],
    ]);

    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion')->callMountedAction();

    expect($page->instance()->restoreAcknowledgeableHash)->not->toBeNull();

    app(RedactVersion::class)->handle($this->admin, $article, 1, RedactionScope::Lines, '2', 'Example reason for the redaction');

    $page->callMountedAction()->assertNotified('Verze 1 byla obnovena jako verze 3');

    $stored = versionRestoreModalArticle($article->id);

    expect($stored->version_no)->toBe(3)
        ->and($stored->sensitive)->toBeFalse()
        ->and($stored->body_md)->not->toContain(SecretFixtures::needles($secret)[0])
        ->and(versionRestoreModalActivity($article->id, 'secret_acknowledged'))->toBe([]);
});

it('does not let the browser set the restore tokens, the findings or the acknowledgment', function (): void {
    $article = versionRestoreModalThree();
    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion');

    foreach (['restoreVersionNo' => 2, 'restoreExpectedVersion' => 9, 'restoreExpectedRedactionNo' => 9, 'restoreFindings' => [], 'restoreAcknowledgeableHash' => 'forged'] as $property => $value) {
        expect(fn () => $page->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }

    $page->assertSet('restoreVersionNo', 1)->assertSet('restoreExpectedVersion', 3)->assertSet('restoreExpectedRedactionNo', 0);
});

it('keeps the modal open and sends the write-failed notice when the database refuses the write', function (): void {
    Exceptions::fake();

    $article = versionRestoreModalThree();
    $page = versionRestoreModalView($article, 1)->mountAction('restoreVersion');

    // A conflicting version row at the next number: the version insert of the restore hits the unique constraint.
    DB::table('knowledge_article_versions')->insert([
        'article_id' => $article->getKey(),
        'version_no' => 4,
        'title' => 'Example conflicting title',
        'body_md' => 'Example conflicting body',
        'author_id' => $this->admin->getKey(),
    ]);

    $page->callMountedAction()->assertNoRedirect();

    $notice = versionRestoreModalNotifications()[0];

    expect($notice->getTitle())->toBe('Článek se nepodařilo uložit')
        ->and($notice->getStatus())->toBe('danger')
        ->and($page->instance()->getMountedAction())->not->toBeNull()
        ->and(versionRestoreModalArticle($article->id)->version_no)->toBe(3)
        ->and(versionRestoreModalArticle($article->id)->title)->toBe('Example third title');
});
