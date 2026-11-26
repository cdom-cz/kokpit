<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\SecretFixtures;

/*
 * The Zařazení section of the article form on the edit screen (ORG-03, ORG-04, D-04, D-06): the folder, the knowledge
 * tags and the client and project links are filled, saved with the content through SaveArticle, and kept when a save is
 * refused. Every name is fictional.
 */

/**
 * Runs the callback without the Partner scope, to read and write what the tests need.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function articleClassificationFormSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article row, archived ones included.
 */
function articleClassificationFormRow(string $id): KnowledgeArticle
{
    return articleClassificationFormSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The number of version rows of an article.
 */
function articleClassificationFormVersions(string $id): int
{
    return articleClassificationFormSystem(static fn (): int => KnowledgeArticleVersion::query()->where('article_id', $id)->count());
}

/**
 * The names of the knowledge tags of an article, sorted.
 *
 * @return list<string>
 */
function articleClassificationFormTags(string $id): array
{
    return articleClassificationFormSystem(static function () use ($id): array {
        $names = KnowledgeArticle::query()->withTrashed()->findOrFail($id)->tagsWithType('knowledge')->map(static fn (Tag $tag): string => (string) $tag->name)->all();
        sort($names);

        return array_values($names);
    });
}

/**
 * The edit screen of an article.
 */
function articleClassificationFormPage(KnowledgeArticle $article): Testable
{
    return Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()]);
}

/**
 * Two folders ("Example ops" and "Example ops / Example servers"), two clients with two projects each and one archived
 * project of the first client, as the ids.
 *
 * @return array{ops: string, servers: string, clientA: string, clientB: string, projectA1: string, projectA2: string, projectA3: string, projectB1: string}
 */
function articleClassificationFormWorld(): array
{
    $ops = KnowledgeFixtures::folder('Example ops');
    $servers = KnowledgeFixtures::folder('Example servers', $ops);

    return articleClassificationFormSystem(static function () use ($ops, $servers): array {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $projectA1 = Project::factory()->create(['client_id' => $clientA->getKey()]);
        $projectA2 = Project::factory()->create(['client_id' => $clientA->getKey()]);
        $projectA3 = Project::factory()->create(['client_id' => $clientA->getKey()]);
        $projectB1 = Project::factory()->create(['client_id' => $clientB->getKey()]);

        $projectA3->delete();

        return [
            'ops' => (string) $ops->getKey(),
            'servers' => (string) $servers->getKey(),
            'clientA' => (string) $clientA->getKey(),
            'clientB' => (string) $clientB->getKey(),
            'projectA1' => (string) $projectA1->getKey(),
            'projectA2' => (string) $projectA2->getKey(),
            'projectA3' => (string) $projectA3->getKey(),
            'projectB1' => (string) $projectB1->getKey(),
        ];
    });
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('fills the folder, the tags, the client and the project of a filed article', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::taggedArticle(['Example server'], [
        'title' => 'Example filed article',
        'folder_id' => $world['servers'],
        'client_id' => $world['clientA'],
        'project_id' => $world['projectA1'],
    ]);

    articleClassificationFormPage($article)
        ->assertFormSet([
            'folder_id' => $world['servers'],
            'tags' => ['Example server'],
            'client_id' => $world['clientA'],
            'project_id' => $world['projectA1'],
        ]);
});

it('offers the folders as path labels', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example plain article']);

    $page = articleClassificationFormPage($article);
    $options = $page->instance()->form->getComponent('folder_id')?->getOptions() ?? [];

    expect($options[$world['ops']] ?? null)->toBe('Example ops')
        ->and($options[$world['servers']] ?? null)->toBe('Example ops / Example servers');
});

it('saves a changed classification alone, says no new version came and writes the folder and the tags', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::taggedArticle(['Example server'], [
        'title' => 'Example filed article',
        'body_md' => 'Example stored body',
        'folder_id' => $world['ops'],
    ]);

    articleClassificationFormPage($article)
        ->set('data.folder_id', $world['servers'])
        ->set('data.tags', ['Example server', 'Example new tag'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Zařazení článku bylo uloženo, nová verze nevznikla.');

    $stored = articleClassificationFormRow($article->getKey());

    expect($stored->folder_id)->toBe($world['servers'])
        ->and($stored->version_no)->toBe(1)
        ->and(articleClassificationFormVersions($article->getKey()))->toBe(1)
        ->and(articleClassificationFormTags($article->getKey()))->toBe(['Example new tag', 'Example server']);
});

it('saves a changed body and a changed client in one save as one new version', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example filed article', 'body_md' => 'Example stored body']);

    articleClassificationFormPage($article)
        ->set('data.body', 'Example changed body')
        ->set('data.client_id', $world['clientA'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Článek byl uložen jako verze 2');

    $stored = articleClassificationFormRow($article->getKey());

    expect($stored->version_no)->toBe(2)
        ->and($stored->body_md)->toBe('Example changed body')
        ->and($stored->client_id)->toBe($world['clientA'])
        ->and(articleClassificationFormVersions($article->getKey()))->toBe(2);
});

it('saves a client with its project and clears them again', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example filed article']);

    articleClassificationFormPage($article)
        ->set('data.client_id', $world['clientA'])
        ->set('data.project_id', $world['projectA1'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(articleClassificationFormRow($article->getKey())->project_id)->toBe($world['projectA1']);

    articleClassificationFormPage($article)
        ->set('data.client_id', null)
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = articleClassificationFormRow($article->getKey());

    expect($stored->client_id)->toBeNull()
        ->and($stored->project_id)->toBeNull();
});

it('clears the project when the client is cleared or changed', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article([
        'title' => 'Example filed article',
        'client_id' => $world['clientA'],
        'project_id' => $world['projectA1'],
    ]);

    articleClassificationFormPage($article)
        ->assertSet('data.project_id', $world['projectA1'])
        ->set('data.client_id', null)
        ->assertSet('data.project_id', null);

    articleClassificationFormPage($article)
        ->assertSet('data.project_id', $world['projectA1'])
        ->set('data.client_id', $world['clientB'])
        ->assertSet('data.project_id', null);

    // The same client keeps its project.
    articleClassificationFormPage($article)
        ->set('data.client_id', $world['clientA'])
        ->assertSet('data.project_id', $world['projectA1']);
});

it('disables the project with a helper until a client is chosen and then lists only the selectable projects of that client', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example filed article']);

    $page = articleClassificationFormPage($article);
    $project = static fn (Testable $page) => $page->instance()->form->getComponent('project_id');

    // Filament 5 renders the helper text from the below-content schema, so the rendered page is checked.
    $page->assertSee('Nejdřív vyberte klienta.');

    expect($project($page)?->isDisabled())->toBeTrue()
        ->and($project($page)?->getOptions())->toBe([]);

    $page->set('data.client_id', $world['clientA']);

    $page->assertDontSee('Nejdřív vyberte klienta.');

    expect($project($page)?->isDisabled())->toBeFalse()
        ->and(array_keys($project($page)?->getOptions() ?? []))->toEqualCanonicalizing([$world['projectA1'], $world['projectA2']]);

    $page->set('data.client_id', $world['clientB']);

    expect(array_keys($project($page)?->getOptions() ?? []))->toBe([$world['projectB1']]);
});

it('refuses a project of another client as an error under the project field and writes nothing', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example filed article', 'body_md' => 'Example stored body']);

    articleClassificationFormPage($article)
        ->set('data.body', 'Example changed body')
        ->set('data.client_id', $world['clientA'])
        ->set('data.project_id', $world['projectB1'])
        ->call('save')
        ->assertHasFormErrors(['project_id']);

    $stored = articleClassificationFormRow($article->getKey());

    expect($stored->version_no)->toBe(1)
        ->and($stored->body_md)->toBe('Example stored body')
        ->and($stored->client_id)->toBeNull()
        ->and($stored->project_id)->toBeNull();
});

it('keeps every typed value, the classification included, after a stale save and writes nothing', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example contested article', 'body_md' => 'Example original body']);

    $first = articleClassificationFormPage($article);
    $second = articleClassificationFormPage($article);

    $first->set('data.body', 'Example body A')->call('save');

    $second
        ->set('data.body', 'Example body B')
        ->set('data.folder_id', $world['servers'])
        ->set('data.tags', ['Example typed tag'])
        ->set('data.client_id', $world['clientA'])
        ->set('data.project_id', $world['projectA1'])
        ->call('save')
        ->assertNoRedirect()
        ->assertNotified('Článek mezitím někdo změnil')
        ->assertSet('data.body', 'Example body B')
        ->assertSet('data.folder_id', $world['servers'])
        ->assertSet('data.tags', ['Example typed tag'])
        ->assertSet('data.client_id', $world['clientA'])
        ->assertSet('data.project_id', $world['projectA1']);

    $stored = articleClassificationFormRow($article->getKey());

    expect($stored->version_no)->toBe(2)
        ->and($stored->body_md)->toBe('Example body A')
        ->and($stored->folder_id)->toBeNull()
        ->and($stored->client_id)->toBeNull()
        ->and(articleClassificationFormTags($article->getKey()))->toBe([]);
});

it('refuses a classification-only save of an editor opened before a redaction and keeps the typed values', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example redacted article', 'body_md' => 'Example original body']);

    $page = articleClassificationFormPage($article);

    DB::table('knowledge_articles')->where('id', $article->getKey())->increment('redaction_no');

    $page
        ->set('data.folder_id', $world['ops'])
        ->set('data.tags', ['Example typed tag'])
        ->call('save')
        ->assertNoRedirect()
        ->assertSet('data.folder_id', $world['ops'])
        ->assertSet('data.tags', ['Example typed tag']);

    expect(articleClassificationFormRow($article->getKey())->folder_id)->toBeNull()
        ->and(articleClassificationFormTags($article->getKey()))->toBe([]);
});

it('keeps the typed classification when the secret guard refuses the body', function (): void {
    $world = articleClassificationFormWorld();
    $article = KnowledgeFixtures::article(['title' => 'Example guarded article', 'body_md' => 'Example plain text']);
    $body = SecretFixtures::bodyWith(SecretFixtures::stripeLiveSecret(), 4);

    articleClassificationFormPage($article)
        ->set('data.body', $body)
        ->set('data.folder_id', $world['servers'])
        ->set('data.tags', ['Example typed tag'])
        ->set('data.client_id', $world['clientA'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee('Text obsahuje tajný údaj a nelze ho uložit')
        ->assertSet('data.folder_id', $world['servers'])
        ->assertSet('data.tags', ['Example typed tag'])
        ->assertSet('data.client_id', $world['clientA']);

    $stored = articleClassificationFormRow($article->getKey());

    expect($stored->body_md)->toBe('Example plain text')
        ->and($stored->folder_id)->toBeNull()
        ->and($stored->client_id)->toBeNull()
        ->and(articleClassificationFormTags($article->getKey()))->toBe([])
        ->and(articleClassificationFormVersions($article->getKey()))->toBe(1);
});

it('makes only the client select live', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example filed article']);

    $page = articleClassificationFormPage($article);
    $form = $page->instance()->form;

    expect($form->getComponent('client_id')?->isLive())->toBeTrue()
        ->and($form->getComponent('folder_id')?->isLive())->toBeFalse()
        ->and($form->getComponent('tags')?->isLive())->toBeFalse()
        ->and($form->getComponent('project_id')?->isLive())->toBeFalse();

    $html = $page->html();

    foreach (['data.folder_id', 'data.tags', 'data.project_id', 'data.body'] as $path) {
        expect($html)->not->toContain('wire:model.live="'.$path.'"');
    }
});

it('puts the Zařazení section between the editor and the sensitive toggle', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example filed article']);

    $page = articleClassificationFormPage($article);
    $html = $page->html();

    $section = strpos($html, 'Zařazení');
    $toggle = strpos($html, 'data.sensitive');

    expect($section)->not->toBeFalse()
        ->and($toggle)->not->toBeFalse()
        ->and($section)->toBeLessThan($toggle);

    $page->assertSee('Bez složky')->assertSee('Štítky znalostí jsou oddělené od štítků klientů.');
});
