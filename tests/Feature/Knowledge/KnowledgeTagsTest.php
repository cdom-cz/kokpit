<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Tasks\Actions\CreateTask;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TagsInput;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The knowledge tags of an article (ORG-03, D-04): the vocabulary stays apart from the client, project and task tags in
 * both directions, and the view screen shows where an article is filed and linked in a second meta row (UI-SPEC
 * section 2). Every name is fictional.
 */

/**
 * Runs the callback without the Partner scope, to read and write what the tests need.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function knowledgeTagsSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The suggestions of the tags input of a page form.
 *
 * @return list<string>
 */
function knowledgeTagsSuggestions(Testable $page): array
{
    $field = $page->instance()->form->getComponent('tags');

    return $field instanceof TagsInput ? array_values(array_map(strval(...), $field->getSuggestions())) : [];
}

/**
 * The view screen of an article.
 */
function knowledgeTagsViewPage(string $key): Testable
{
    return Livewire::test(ViewKnowledgeArticle::class, ['record' => $key]);
}

/**
 * A client with a project, as the models.
 *
 * @return array{client: Client, project: Project}
 */
function knowledgeTagsClientWithProject(): array
{
    return knowledgeTagsSystem(static function (): array {
        $client = Client::factory()->create();
        $project = app(CreateProjectAction::class)->handle($client, [
            'name' => 'Example linked project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
        ]);

        return ['client' => $client, 'project' => $project];
    });
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('suggests only knowledge tags in the knowledge editor', function (): void {
    KnowledgeFixtures::taggedArticle(['Example knowledge tag']);
    $owner = knowledgeTagsClientWithProject();

    knowledgeTagsSystem(static function () use ($owner): void {
        $owner['client']->attachTag('Example client tag', 'client');
        $owner['project']->attachTag('Example project tag', 'project');
    });

    $page = Livewire::test(CreateKnowledgeArticle::class)
        ->assertFormFieldExists('tags', static function (TagsInput $field): bool {
            $suggestions = $field->getSuggestions();

            return in_array('Example knowledge tag', $suggestions, true)
                && ! in_array('Example client tag', $suggestions, true)
                && ! in_array('Example project tag', $suggestions, true);
        });

    // The editor input is the plain tags input bound to the form state, never the Spatie plugin input.
    expect($page->instance()->form->getComponent('tags'))->not->toBeInstanceOf(SpatieTagsInput::class)
        ->and(knowledgeTagsSuggestions($page))->toBe(['Example knowledge tag']);
});

it('never suggests a knowledge tag on the client, project and task forms', function (): void {
    KnowledgeFixtures::taggedArticle(['Example knowledge tag']);
    $owner = knowledgeTagsClientWithProject();

    $admin = $this->admin;
    $task = knowledgeTagsSystem(static fn () => app(CreateTask::class)->handle($admin, $owner['project'], ['title' => 'Example tagged task']));

    knowledgeTagsSystem(static function () use ($owner, $task): void {
        $owner['client']->attachTag('Example client tag', 'client');
        $owner['project']->attachTag('Example project tag', 'project');
        $task->attachTag('Example task tag', 'task');
    });

    $client = knowledgeTagsSuggestions(Livewire::test(CreateClient::class));
    $project = knowledgeTagsSuggestions(Livewire::test(CreateProject::class));
    $taskForm = knowledgeTagsSuggestions(Livewire::test(EditTask::class, ['record' => $task->reference]));

    expect($client)->toContain('Example client tag')
        ->and($client)->not->toContain('Example knowledge tag')
        ->and($project)->toContain('Example project tag')
        ->and($project)->not->toContain('Example knowledge tag')
        ->and($taskForm)->toContain('Example task tag')
        ->and($taskForm)->not->toContain('Example knowledge tag');
});

it('stores a tag typed in the knowledge editor with the knowledge type and keeps it out of the client suggestions', function (): void {
    Livewire::test(CreateKnowledgeArticle::class)
        ->set('data.title', 'Example tagged article')
        ->set('data.tags', ['Example fresh tag'])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = knowledgeTagsSystem(static fn (): ?Tag => Tag::query()->where('type', 'knowledge')->get()->first(static fn (Tag $tag): bool => $tag->name === 'Example fresh tag'));

    expect($row)->not->toBeNull()
        ->and(knowledgeTagsSystem(static fn (): int => Tag::query()->whereNull('type')->count()))->toBe(0)
        ->and(knowledgeTagsSuggestions(Livewire::test(CreateClient::class)))->not->toContain('Example fresh tag')
        ->and(knowledgeTagsSuggestions(Livewire::test(CreateKnowledgeArticle::class)))->toContain('Example fresh tag');
});

it('merges a tag typed in another case into the existing knowledge tag', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example plain article']);

    KnowledgeFixtures::taggedArticle(['Example knowledge tag'], ['title' => 'Example first tagged article']);

    $before = knowledgeTagsSystem(static fn (): int => Tag::query()->where('type', 'knowledge')->count());

    Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->set('data.tags', ['example KNOWLEDGE tag'])
        ->call('save')
        ->assertHasNoFormErrors();

    $after = knowledgeTagsSystem(static fn (): int => Tag::query()->where('type', 'knowledge')->count());

    $names = knowledgeTagsSystem(static fn (): array => $article->fresh()?->tagsWithType('knowledge')->map(static fn (Tag $tag): string => (string) $tag->name)->all() ?? []);

    expect($before)->toBe(1)
        ->and($after)->toBe(1)
        ->and($names)->toBe(['Example knowledge tag']);
});

it('shows the folder path, the client, the project and the tags in a second meta row', function (): void {
    $ops = KnowledgeFixtures::folder('Example ops');
    $servers = KnowledgeFixtures::folder('Example servers', $ops);
    $owner = knowledgeTagsClientWithProject();

    $article = KnowledgeFixtures::taggedArticle(['Example server', 'Example backup'], [
        'title' => 'Example filed article',
        'folder_id' => $servers->getKey(),
        'client_id' => $owner['client']->getKey(),
        'project_id' => $owner['project']->getKey(),
    ]);

    knowledgeTagsSystem(static fn () => $article->attachTag('Example client tag', 'client'));

    $folderUrl = KnowledgeArticleResource::getUrl('index', ['folder' => $servers->getKey()]);
    $clientUrl = ClientResource::getUrl('view', ['record' => $owner['client']]);
    $projectUrl = ProjectResource::getUrl('view', ['record' => $owner['project']]);
    $projectLabel = $owner['project']->key.' · '.$owner['project']->name;

    knowledgeTagsViewPage($article->getRouteKey())
        ->assertSeeHtml('data-kokpit-classification')
        ->assertSeeHtml('href="'.$folderUrl.'"')
        ->assertSeeHtml('href="'.$clientUrl.'"')
        ->assertSeeHtml('href="'.$projectUrl.'"')
        ->assertSee('Example ops / Example servers')
        ->assertSee($owner['client']->name)
        ->assertSee($projectLabel)
        ->assertSee('Example server')
        ->assertSee('Example backup')
        ->assertDontSee('Example client tag');
});

it('shows no second meta row for an article with nothing set', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example plain article']);

    knowledgeTagsViewPage($article->getRouteKey())
        ->assertSee('Verze 1')
        ->assertDontSeeHtml('data-kokpit-classification');
});

it('shows only the chips for an article that has only tags', function (): void {
    $article = KnowledgeFixtures::taggedArticle(['Example lonely tag'], ['title' => 'Example tagged article']);

    knowledgeTagsViewPage($article->getRouteKey())
        ->assertSeeHtml('data-kokpit-classification')
        ->assertSee('Example lonely tag')
        ->assertDontSeeHtml('?folder=');
});

it('keeps an archived client and its project shown on the view screen', function (): void {
    $owner = knowledgeTagsClientWithProject();
    $article = KnowledgeFixtures::article([
        'title' => 'Example linked article',
        'client_id' => $owner['client']->getKey(),
        'project_id' => $owner['project']->getKey(),
    ]);

    knowledgeTagsSystem(static fn () => $owner['client']->delete());

    knowledgeTagsViewPage($article->getRouteKey())
        ->assertSee($owner['client']->name)
        ->assertSeeHtml('href="'.ClientResource::getUrl('view', ['record' => $owner['client']]).'"');
});

it('escapes a tag name on the view screen', function (): void {
    $article = KnowledgeFixtures::taggedArticle(['<i>Example markup</i>'], ['title' => 'Example escaped article']);

    knowledgeTagsViewPage($article->getRouteKey())
        ->assertSee('<i>Example markup</i>')
        ->assertDontSeeHtml('<i>Example markup</i>');
});
