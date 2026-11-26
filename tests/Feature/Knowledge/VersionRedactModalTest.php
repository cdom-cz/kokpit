<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeVersionRedaction;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CompareArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewArticleVersion;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\SecretFixtures;

/*
 * The redaction modal of the history screens (Phase 19, plan 19-11; VER-05, D-12, D-13, owner decisions OD-1 and OD-3):
 * the warnings, the numbered preview, the field errors under their fields, the success notices and the end-to-end proof
 * that a redacted secret no longer appears in the version view, the compare page or a restore. The reason is a
 * fictional text with a unique marker; it must never come back. Secrets come only from SecretFixtures.
 */

const VERSION_REDACT_MODAL_REASON = 'Example reason zq7f3-marker: a fictional token was pasted';

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function versionRedactModalSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

function versionRedactModalArticle(string $id): KnowledgeArticle
{
    return versionRedactModalSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version rows of an article, oldest first.
 *
 * @return list<KnowledgeArticleVersion>
 */
function versionRedactModalRows(string $id): array
{
    return versionRedactModalSystem(static fn (): array => KnowledgeArticleVersion::query()
        ->where('article_id', $id)
        ->orderBy('version_no')
        ->get()
        ->all());
}

/**
 * Every stored row that a redaction or a restore may touch, as one JSON text: the article, its versions, the
 * redaction rows and the activity rows.
 */
function versionRedactModalStoredJson(string $id): string
{
    return (string) json_encode(versionRedactModalSystem(static fn (): array => [
        KnowledgeArticle::query()->withTrashed()->whereKey($id)->get()->map(static fn (KnowledgeArticle $row): array => $row->getAttributes())->all(),
        KnowledgeArticleVersion::query()->where('article_id', $id)->get()->map(static fn (KnowledgeArticleVersion $row): array => $row->getAttributes())->all(),
        KnowledgeVersionRedaction::query()->get()->map(static fn (KnowledgeVersionRedaction $row): array => $row->getAttributes())->all(),
        Activity::query()->where('subject_id', $id)->get()->map(static fn (Activity $row): array => $row->getAttributes())->all(),
    ]));
}

/**
 * A three-version article whose version 1 holds the given secret on the given line; versions 2 and 3 are plain.
 */
function versionRedactModalArticleWith(string $secret, int $line): KnowledgeArticle
{
    return KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => SecretFixtures::bodyWith($secret, $line)],
        ['title' => 'Example second title', 'body' => 'Example second body'],
        ['title' => 'Example third title', 'body' => 'Example third body'],
    ]);
}

function versionRedactModalView(KnowledgeArticle $article, int $version): Testable
{
    return Livewire::test(ViewArticleVersion::class, ['record' => $article->getKey(), 'version' => (string) $version]);
}

function versionRedactModalList(KnowledgeArticle $article): Testable
{
    return Livewire::test(ListArticleVersions::class, ['record' => $article->getKey()]);
}

/**
 * The text of the mounted modal's schema (the page html does not carry the modal of an action).
 */
function versionRedactModalText(Testable $page): string
{
    return html_entity_decode(strip_tags((string) $page->instance()->getSchema('mountedActionSchema0')->toHtml()), ENT_QUOTES | ENT_HTML5);
}

function versionRedactModalHtml(Testable $page): string
{
    return (string) $page->instance()->getSchema('mountedActionSchema0')->toHtml();
}

/**
 * The notifications sent so far, oldest first (read once; the mailbox is flushed).
 *
 * @return list<\Filament\Notifications\Notification>
 */
function versionRedactModalNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotifications();

    return array_values($component->notifications->all());
}

/**
 * The titles and bodies of the notifications as one text.
 */
function versionRedactModalNotificationText(): string
{
    return implode("\n", array_map(
        static fn (\Filament\Notifications\Notification $notice): string => $notice->getTitle().' '.$notice->getBody(),
        versionRedactModalNotifications(),
    ));
}

/**
 * Starts the live capture of every log write and proves it with a probe entry.
 */
function versionRedactModalCapturedLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
        $logs->append((string) $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
    });

    Log::info('versionRedactModal capture probe');

    expect(implode("\n", $logs->getArrayCopy()))->toContain('versionRedactModal capture probe');

    $logs->exchangeArray([]);

    return $logs;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('warns that the redaction is irreversible, that the secret must be rotated and that later versions keep their text', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => "Example plain line\n<script>alert(1)</script>\nExample third line"],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $page = versionRedactModalView($article, 1)->mountAction('redactVersion');
    $mounted = $page->instance()->getMountedAction();
    $text = versionRedactModalText($page);
    $html = versionRedactModalHtml($page);

    expect($mounted->getModalHeading())->toBe('Redigovat verzi 1')
        ->and($mounted->getModalSubmitAction()?->getColor())->toBe('danger')
        ->and($text)->toContain('Redigování nelze vrátit zpět')
        ->and($text)->toContain('Skutečné řešení je tajný údaj změnit')
        ->and($text)->toContain(__('knowledge.redact.later_versions_keep'))
        ->and($text)->toContain('Rozsah')
        ->and($text)->toContain('Čísla řádků')
        ->and($text)->toContain('Důvod redigování')
        ->and($text)->not->toContain('Tato verze je aktuální')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');

    $page->assertSet('mountedActions.0.data.scope', 'lines');

    // The current version carries the extra note (D-13).
    $current = versionRedactModalView($article, 2)->mountAction('redactVersion');

    expect(versionRedactModalText($current))->toContain('Tato verze je aktuální: redigování se projeví i v aktuálním textu článku.');
});

it('caps the preview at 2,000 numbered lines and says so', function (): void {
    $body = implode("\n", array_map(static fn (int $number): string => 'Example row '.$number, range(1, 2500)));
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => $body],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $page = versionRedactModalView($article, 1)->mountAction('redactVersion');
    $text = versionRedactModalText($page);

    expect($text)->toContain('Example row 2000')
        ->and($text)->not->toContain('Example row 2001')
        ->and($text)->toContain('Zobrazeno prvních 2 000 řádků.');
});

it('redacts a version and then shows the marker, never the token, in the version view, the compare page and a restore', function (): void {
    $token = SecretFixtures::githubToken();
    $article = versionRedactModalArticleWith($token, 2);
    $needles = SecretFixtures::needles($token);

    $page = versionRedactModalView($article, 1)
        ->callAction('redactVersion', ['scope' => 'lines', 'lines' => '2', 'reason' => VERSION_REDACT_MODAL_REASON])
        ->assertNotified('Verze 1 byla redigována')
        ->assertSee('Redigováno')
        ->assertSee('Tato verze byla redigována');

    $rows = versionRedactModalRows($article->id);

    expect($rows[0]->body_md)->toContain('[REDIGOVÁNO]')
        ->and($rows[0]->body_md)->not->toContain($needles[0])
        ->and(versionRedactModalArticle($article->id)->redaction_no)->toBe(0);

    // The version view reads the stored row.
    $view = versionRedactModalView($article, 1);

    expect($view->html())->toContain('[REDIGOVÁNO]');

    foreach ($needles as $needle) {
        expect($view->html())->not->toContain($needle)
            ->and($page->html())->not->toContain($needle);
    }

    // The compare page of version 1 against version 2.
    $compare = Livewire::withQueryParams(['from' => '1', 'to' => '2'])->test(CompareArticleVersions::class, ['record' => $article->getKey()]);

    expect($compare->html())->toContain('[REDIGOVÁNO]');

    foreach ($needles as $needle) {
        expect($compare->html())->not->toContain($needle);
    }

    // A restore of version 1 brings the marker back, not the token.
    versionRedactModalView($article, 1)->callAction('restoreVersion');

    $restored = versionRedactModalRows($article->id)[3];

    expect($restored->version_no)->toBe(4)
        ->and($restored->body_md)->toContain('[REDIGOVÁNO]')
        ->and($restored->body_md)->not->toContain($needles[0]);
});

it('redacts from the Další akce group of the versions table and marks the row', function (): void {
    $token = SecretFixtures::githubToken();
    $article = versionRedactModalArticleWith($token, 2);
    $rows = versionRedactModalRows($article->id);

    versionRedactModalList($article)
        ->assertActionVisible(TestAction::make('redactVersion')->table($rows[0]))
        ->callAction(TestAction::make('redactVersion')->table($rows[0]), ['scope' => 'lines', 'lines' => '2', 'reason' => VERSION_REDACT_MODAL_REASON])
        ->assertNotified('Verze 1 byla redigována')
        ->assertTableColumnStateSet('markers', ['Redigováno'], $rows[0]);

    expect(versionRedactModalRows($article->id)[0]->body_md)->not->toContain(SecretFixtures::needles($token)[0]);
});

it('offers the redaction on every version of an archived article too', function (): void {
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article', 'body_md' => 'Example archived body', 'version_no' => 2]);

    versionRedactModalView($archived, 1)
        ->assertActionVisible('redactVersion')
        ->callAction('redactVersion', ['scope' => 'whole', 'reason' => VERSION_REDACT_MODAL_REASON])
        ->assertNotified('Verze 1 byla redigována');

    expect(versionRedactModalRows($archived->id)[0]->body_md)->toBe('[REDIGOVÁNO]');
});

it('shows a missing line and a missing reason under their fields and keeps the modal open', function (): void {
    $article = versionRedactModalArticleWith(SecretFixtures::githubToken(), 2);
    $before = versionRedactModalStoredJson($article->id);

    $page = versionRedactModalView($article, 1)
        ->mountAction('redactVersion')
        ->callMountedAction();

    expect($page->errors()->get('mountedActions.0.data.reason'))->toContain('Vyplňte důvod redigování.')
        ->and($page->errors()->get('mountedActions.0.data.lines'))->toContain('Vyberte alespoň jeden řádek.');

    $page->fillForm(['scope' => 'lines', 'lines' => '99', 'reason' => VERSION_REDACT_MODAL_REASON])->callMountedAction();

    expect($page->errors()->get('mountedActions.0.data.lines'))->toContain('Řádek 99 ve verzi neexistuje.')
        ->and($page->instance()->getMountedAction())->not->toBeNull()
        ->and(versionRedactModalStoredJson($article->id))->toBe($before);
});

it('extends a selected private key header to the whole key and says so', function (): void {
    $key = SecretFixtures::pemPrivateKey();
    $article = versionRedactModalArticleWith($key, 3);

    versionRedactModalView($article, 1)->callAction('redactVersion', ['scope' => 'lines', 'lines' => '3', 'reason' => VERSION_REDACT_MODAL_REASON]);

    $notices = versionRedactModalNotifications();

    expect($notices)->toHaveCount(1)
        ->and($notices[0]->getTitle())->toBe('Verze 1 byla redigována')
        ->and($notices[0]->getBody())->toBe('Výběr se rozšířil na celý soukromý klíč (řádky 3–6).');

    foreach (SecretFixtures::needles($key) as $needle) {
        expect(versionRedactModalRows($article->id)[0]->body_md)->not->toContain($needle);
    }
});

it('sends a second warning that names rule and line when the rescan still finds a key header', function (): void {
    $key = SecretFixtures::pemPrivateKey();
    $article = versionRedactModalArticleWith($key, 3);

    versionRedactModalView($article, 1)->callAction('redactVersion', ['scope' => 'lines', 'lines' => '5', 'reason' => VERSION_REDACT_MODAL_REASON]);

    $notices = versionRedactModalNotifications();

    expect($notices)->toHaveCount(2)
        ->and($notices[0]->getTitle())->toBe('Verze 1 byla redigována')
        ->and($notices[1]->getTitle())->toBe('Ve verzi může dál zůstat tajný údaj')
        ->and($notices[1]->getStatus())->toBe('warning')
        ->and($notices[1]->getBody())->toContain('Soukromý klíč (PEM) — řádek 3');
});

it('changes the article body when the current version is redacted whole, and refuses an editor opened before as stale', function (): void {
    $article = versionRedactModalArticleWith(SecretFixtures::githubToken(), 2);

    $edit = Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getKey()]);

    versionRedactModalView($article, 3)->callAction('redactVersion', ['scope' => 'whole', 'reason' => VERSION_REDACT_MODAL_REASON]);

    $stored = versionRedactModalArticle($article->id);

    expect($stored->body_md)->toBe('[REDIGOVÁNO]')
        ->and($stored->redaction_no)->toBe(1)
        ->and($stored->version_no)->toBe(3);

    versionRedactModalNotifications();

    $edit->fillForm(['body' => 'Example text typed after the redaction'])->call('save');

    $notice = versionRedactModalNotifications()[0];

    expect($notice->getTitle())->toBe('Text článku byl mezitím změněn nebo redigován')
        ->and($notice->getStatus())->toBe('danger')
        ->and(versionRedactModalArticle($article->id)->body_md)->toBe('[REDIGOVÁNO]')
        ->and(versionRedactModalRows($article->id))->toHaveCount(3);
});

it('never lets the reason or the token reach a notification, the page, a stored row or the log', function (): void {
    $token = SecretFixtures::githubToken();
    $article = versionRedactModalArticleWith($token, 2);
    $logs = versionRedactModalCapturedLogs();

    $page = versionRedactModalView($article, 1)
        ->callAction('redactVersion', ['scope' => 'lines', 'lines' => '2', 'reason' => VERSION_REDACT_MODAL_REASON]);

    $watched = ['zq7f3-marker', VERSION_REDACT_MODAL_REASON, ...SecretFixtures::needles($token)];
    $surfaces = [
        'notifications' => versionRedactModalNotificationText(),
        'page' => $page->html(),
        'snapshot' => (string) json_encode($page->instance()->all(), JSON_PARTIAL_OUTPUT_ON_ERROR),
        'rows' => versionRedactModalStoredJson($article->id),
        'log' => implode("\n", $logs->getArrayCopy()),
    ];

    foreach ($surfaces as $name => $text) {
        foreach ($watched as $needle) {
            expect($text)->not->toContain($needle, "{$name} carries a watched value");
        }
    }

    $redactions = versionRedactModalSystem(static fn (): array => KnowledgeVersionRedaction::query()->get()->all());

    expect($redactions)->toHaveCount(1)
        ->and($redactions[0]->reason_length)->toBe(mb_strlen(VERSION_REDACT_MODAL_REASON));
});

it('opens a new redaction modal with an empty reason', function (): void {
    $article = versionRedactModalArticleWith(SecretFixtures::githubToken(), 2);

    $page = versionRedactModalView($article, 1)
        ->callAction('redactVersion', ['scope' => 'lines', 'lines' => '2', 'reason' => VERSION_REDACT_MODAL_REASON])
        ->mountAction('redactVersion');

    $page->assertSet('mountedActions.0.data.reason', '')
        ->assertSet('mountedActions.0.data.lines', '');

    expect(versionRedactModalHtml($page))->not->toContain('zq7f3-marker');
});
