<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The classification on the create screen and the prefill of its query string (ORG-03, ORG-04, D-06, D-07, research
 * Pitfall 14): a new article lands where the Admin started it, and a tampered link can prefill nothing. Every name is
 * fictional.
 */

/**
 * Runs the callback without the Partner scope, to read and write what the tests need.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function articleLinksSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article with the given title.
 */
function articleLinksByTitle(string $title): KnowledgeArticle
{
    return articleLinksSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->where('title', $title)->firstOrFail());
}

/**
 * The create screen opened with the given query string.
 *
 * @param  array<string, mixed>  $query
 */
function articleLinksPage(array $query = []): Testable
{
    return Livewire::withQueryParams($query)->test(CreateKnowledgeArticle::class);
}

/**
 * A folder, two clients with one project each, an archived client and a project of the archived client, as the ids.
 *
 * @return array{folder: string, clientA: string, clientB: string, archived: string, projectA: string, projectB: string, projectArchived: string}
 */
function articleLinksWorld(): array
{
    $folder = KnowledgeFixtures::folder('Example ops');

    return articleLinksSystem(static function () use ($folder): array {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $archived = Client::factory()->create();
        $projectA = Project::factory()->create(['client_id' => $clientA->getKey()]);
        $projectB = Project::factory()->create(['client_id' => $clientB->getKey()]);
        $projectArchived = Project::factory()->create(['client_id' => $archived->getKey()]);

        $archived->delete();

        return [
            'folder' => (string) $folder->getKey(),
            'clientA' => (string) $clientA->getKey(),
            'clientB' => (string) $clientB->getKey(),
            'archived' => (string) $archived->getKey(),
            'projectA' => (string) $projectA->getKey(),
            'projectB' => (string) $projectB->getKey(),
            'projectArchived' => (string) $projectArchived->getKey(),
        ];
    });
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('creates an article as version 1 with a folder, a client, its project and two tags', function (): void {
    $world = articleLinksWorld();

    articleLinksPage()
        ->set('data.title', 'Example linked article')
        ->set('data.body', 'Example linked body')
        ->set('data.folder_id', $world['folder'])
        ->set('data.client_id', $world['clientA'])
        ->set('data.project_id', $world['projectA'])
        ->set('data.tags', ['Example server', 'Example backup'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Článek byl uložen jako verze 1');

    $article = articleLinksByTitle('Example linked article');

    $tags = articleLinksSystem(static function () use ($article): array {
        $names = $article->tagsWithType('knowledge')->map(static fn (Tag $tag): string => (string) $tag->name)->all();
        sort($names);

        return array_values($names);
    });

    $versions = articleLinksSystem(static fn (): int => KnowledgeArticleVersion::query()->where('article_id', $article->getKey())->count());

    expect($article->version_no)->toBe(1)
        ->and($article->folder_id)->toBe($world['folder'])
        ->and($article->client_id)->toBe($world['clientA'])
        ->and($article->project_id)->toBe($world['projectA'])
        ->and($tags)->toBe(['Example backup', 'Example server'])
        ->and($versions)->toBe(1);
});

it('refuses a project of another client on create as an error under the project field and creates nothing', function (): void {
    $world = articleLinksWorld();

    articleLinksPage()
        ->set('data.title', 'Example refused article')
        ->set('data.client_id', $world['clientA'])
        ->set('data.project_id', $world['projectB'])
        ->call('create')
        ->assertHasFormErrors(['project_id']);

    expect(articleLinksSystem(static fn (): int => KnowledgeArticle::query()->withTrashed()->where('title', 'Example refused article')->count()))->toBe(0);
});

it('prefills the folder from the query string', function (): void {
    $world = articleLinksWorld();

    articleLinksPage(['folder' => $world['folder']])
        ->assertFormSet(['folder_id' => $world['folder'], 'client_id' => null, 'project_id' => null]);
});

it('prefills the client and its project from the query string', function (): void {
    $world = articleLinksWorld();

    articleLinksPage(['client' => $world['clientA'], 'project' => $world['projectA']])
        ->assertFormSet(['folder_id' => null, 'client_id' => $world['clientA'], 'project_id' => $world['projectA']]);
});

it('creates the article with the prefilled values when the Admin only types a title', function (): void {
    $world = articleLinksWorld();

    articleLinksPage(['folder' => $world['folder'], 'client' => $world['clientA'], 'project' => $world['projectA']])
        ->set('data.title', 'Example prefilled article')
        ->call('create')
        ->assertHasNoFormErrors();

    $article = articleLinksByTitle('Example prefilled article');

    expect($article->folder_id)->toBe($world['folder'])
        ->and($article->client_id)->toBe($world['clientA'])
        ->and($article->project_id)->toBe($world['projectA']);
});

it('ignores silently every prefill value that is not a valid link', function (array $query): void {
    articleLinksWorld();

    articleLinksPage($query)
        ->assertStatus(200)
        ->assertFormSet(['folder_id' => null, 'client_id' => null, 'project_id' => null])
        ->assertNotNotified();
})->with([
    'folder that is not a UUID' => [['folder' => 'abc']],
    'folder that does not exist' => [['folder' => '00000000-0000-4000-8000-000000000001']],
    'folder given as a list' => [['folder' => ['abc']]],
    'client that is not a UUID' => [['client' => 'abc']],
    'client that does not exist' => [['client' => '00000000-0000-4000-8000-000000000002']],
    'project without a client' => [['project' => '00000000-0000-4000-8000-000000000003']],
    'empty values' => [['folder' => '', 'client' => '', 'project' => '']],
]);

it('ignores an archived client and the project that came with it', function (): void {
    $world = articleLinksWorld();

    articleLinksPage(['client' => $world['archived'], 'project' => $world['projectArchived']])
        ->assertFormSet(['client_id' => null, 'project_id' => null])
        ->assertNotNotified();
});

it('takes the client but drops a project of another client', function (): void {
    $world = articleLinksWorld();

    articleLinksPage(['client' => $world['clientA'], 'project' => $world['projectB']])
        ->assertFormSet(['client_id' => $world['clientA'], 'project_id' => null]);
});

it('takes a valid folder and drops a bad client in the same link', function (): void {
    $world = articleLinksWorld();

    articleLinksPage(['folder' => $world['folder'], 'client' => 'abc', 'project' => $world['projectA']])
        ->assertFormSet(['folder_id' => $world['folder'], 'client_id' => null, 'project_id' => null]);
});

it('takes a prefill value written in capitals', function (): void {
    $world = articleLinksWorld();

    articleLinksPage(['folder' => strtoupper($world['folder'])])
        ->assertFormSet(['folder_id' => $world['folder']]);
});

it('refuses in the database an article whose project belongs to another client, so the link rule holds below the screen', function (): void {
    $world = articleLinksWorld();

    $refusal = null;

    try {
        articleLinksSystem(static fn () => DB::transaction(static function () use ($world): void {
            (new KnowledgeArticle)->forceFill([
                'kind' => ArticleKind::General,
                'title' => 'Example raw article',
                'body_md' => '',
                'version_no' => 1,
                'edited_at' => now(),
                'sensitive' => false,
                'client_id' => $world['clientA'],
                'project_id' => $world['projectB'],
            ])->save();
        }));
    } catch (QueryException $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(QueryException::class)
        ->and((string) $refusal?->getCode())->toBe('23503')
        ->and(Str::contains((string) $refusal?->getMessage(), 'knowledge_articles_project_client_fk'))->toBeTrue();
});
