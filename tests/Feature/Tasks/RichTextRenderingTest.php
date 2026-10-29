<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Pages\TaskBoardPage;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * D-09: rich text is shown with its formatting on every read-only surface of both
 * panels, through the one sanitiser. The formatting comes from the fi-prose class
 * (CSS only); what may appear in the markup stays decided by RichText::render().
 * Every text is fictional; the hostile parts are assembled from fragments at runtime.
 */

/**
 * A description that uses every kind of formatting the editor offers.
 */
function richRenderFormatted(): string
{
    return '<h2>Example heading</h2>'
        .'<p><strong>Example bold</strong> <em>Example italic</em> <u>Example underline</u> <s>Example strike</s> '
        .'<a href="https://example.com/page">Example link</a></p>'
        .'<ul><li>Example bullet</li></ul>'
        .'<ol><li>Example number</li></ol>'
        .'<blockquote>Example quote</blockquote>'
        .'<table><tbody><tr><th>Example head</th><td>Example cell</td></tr></tbody></table>';
}

/**
 * A hostile description assembled from fragments at runtime, so no single line of this file looks like a payload.
 */
function richRenderForged(): string
{
    $lt = '<';

    return implode('', [
        $lt.'scr'.'ipt>window.markerOne()'.$lt.'/scr'.'ipt>',
        $lt.'img src="https://example.com/marker'.'Two.png" on'.'error="markerThree()">',
        $lt.'p style="position:'.'fixed;top:markerFour" on'.'click="markerFive()">Benign words'.$lt.'/p>',
        $lt.'a href="/admin/marker'.'Six">Example relative'.$lt.'/a>',
    ]);
}

/**
 * The marker words of the hostile description, none of which may reach a page.
 *
 * @return list<string>
 */
function richRenderMarkers(): array
{
    return ['markerOne', 'markerTwo', 'markerThree', 'markerFour', 'markerFive', 'markerSix'];
}

/**
 * The page HTML without the Livewire snapshot attribute, which carries the raw state.
 */
function richRenderPage(string $html): string
{
    return (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);
}

/**
 * The inner HTML of every element that carries the fi-prose class, joined, with the
 * whitespace between tags dropped. Empty when no element has the class.
 */
function richRenderProse(string $html): string
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.richRenderPage($html), LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $inner = '';
    $nodes = (new DOMXPath($dom))->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' fi-prose ')]");

    foreach ($nodes === false ? [] : $nodes as $node) {
        foreach ($node->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }
    }

    return (string) preg_replace('/>\s+</', '><', trim($inner));
}

/**
 * A task with the given description (cleaned by the Action), written as the signed-in Admin.
 */
function richRenderTask(string $description): Task
{
    $project = app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example rendering project',
        'key' => 'RND',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);

    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example rendered task']);

    return app(UpdateTask::class)->handle(test()->admin, $task, ['description' => $description]);
}

/**
 * Asserts that the prose markup holds each kind of formatting of richRenderFormatted().
 */
function richRenderExpectFormatted(string $prose): void
{
    expect($prose)->toContain('<h2>Example heading</h2>')
        ->toContain('<strong>Example bold</strong>')
        ->toContain('<em>Example italic</em>')
        ->toContain('<u>Example underline</u>')
        ->toContain('<s>Example strike</s>')
        ->toContain('href="https://example.com/page"')
        ->toContain('<ul><li>Example bullet</li></ul>')
        ->toContain('<ol><li>Example number</li></ol>')
        ->toContain('<blockquote>Example quote</blockquote>')
        ->toContain('<table>')
        ->toContain('<td>Example cell</td>');
}

/**
 * Asserts that the prose markup holds none of the hostile parts of richRenderForged().
 */
function richRenderExpectHarmless(string $page, string $prose): void
{
    foreach (richRenderMarkers() as $marker) {
        expect($page)->not->toContain($marker);
    }

    expect($prose)->toContain('Benign words')
        ->toContain('Example relative')
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain(' style=')
        ->not->toContain('onclick')
        ->not->toContain('href="/');
}

/**
 * A client-visible project of a client with one Partner and a task in it, written as system
 * with the given description. Returns the Partner (not yet signed in) and the task.
 *
 * @return array{0: User, 1: Task}
 */
function richRenderPartnerTask(string $description): array
{
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);

    $task = app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $description): Task {
        $project = app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
            'name' => 'Example partner rendering project',
            'key' => 'PRN',
            'billing_type' => 'hourly',
            'client_visible' => true,
        ]);

        $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example partner rendered task']);

        return app(UpdateTask::class)->handle(test()->admin, $task, ['description' => $description]);
    });

    return [$partner, $task];
}

/**
 * The HTML the board preview of a task shows in its modal, mounted on the task board.
 */
function richRenderPreview(Task $task): string
{
    $component = Livewire::test(TaskBoardPage::class)->assertActionExists('preview')->mountAction('preview', ['task' => $task->id]);

    return (string) $component->instance()->getMountedAction()?->getModalContent()?->render();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('shows the Admin a description with its formatting inside a fi-prose element', function (): void {
    $task = richRenderTask(richRenderFormatted());

    $html = Livewire::test(ViewTask::class, ['record' => $task->reference])->assertSee('Example heading')->html();

    richRenderExpectFormatted(richRenderProse($html));
});

it('renders a hostile description written around the Action harmlessly inside the Admin fi-prose element', function (): void {
    $task = richRenderTask(richRenderFormatted());

    // A write that bypasses the Action: a query update, as an import or a bug could do.
    Task::query()->whereKey($task->id)->update(['description' => richRenderForged()]);

    $html = Livewire::test(ViewTask::class, ['record' => $task->reference])->assertSee('Benign words')->html();

    richRenderExpectHarmless(richRenderPage($html), richRenderProse($html));
});

it('shows the Partner a description with its formatting inside a fi-prose element', function (): void {
    [$partner, $task] = richRenderPartnerTask(richRenderFormatted());

    $this->actingAs($partner);

    $html = Livewire::test(ViewPartnerTask::class, ['record' => $task->reference])->assertSee('Example heading')->html();

    richRenderExpectFormatted(richRenderProse($html));
});

it('renders a hostile description written around the Action harmlessly on the Partner task page', function (): void {
    [$partner, $task] = richRenderPartnerTask(richRenderFormatted());

    app(PartnerContext::class)->runAsSystem(static fn (): int => Task::query()->whereKey($task->id)->update(['description' => richRenderForged()]));

    $this->actingAs($partner);

    $html = Livewire::test(ViewPartnerTask::class, ['record' => $task->reference])->assertSee('Benign words')->html();

    richRenderExpectHarmless(richRenderPage($html), richRenderProse($html));
});

it('shows the board card preview a description with its formatting inside a fi-prose container', function (): void {
    $task = richRenderTask(richRenderFormatted());

    $html = richRenderPreview($task);

    expect($html)->toContain('class="fi-prose"');

    richRenderExpectFormatted(richRenderProse($html));
});

it('renders a hostile description written around the Action harmlessly in the board card preview', function (): void {
    $task = richRenderTask(richRenderFormatted());

    Task::query()->whereKey($task->id)->update(['description' => richRenderForged()]);

    $html = richRenderPreview($task);

    richRenderExpectHarmless(richRenderPage($html), richRenderProse($html));
});

it('keeps the formatting of a comment inside a fi-prose element in the Admin and the Partner thread', function (): void {
    [$partner, $task] = richRenderPartnerTask(richRenderFormatted());

    app(PartnerContext::class)->runAsSystem(static fn () => app(AddTaskComment::class)->handle(test()->admin, $task, '<ul><li>Example comment item</li></ul>'));

    $adminThread = Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class]);

    expect(richRenderProse($adminThread->html()))->toContain('<ul><li>Example comment item</li></ul>');

    $this->actingAs($partner);

    $seen = Task::query()->where('reference', $task->reference)->firstOrFail();
    $partnerThread = Livewire::test(PartnerTaskCommentsRelationManager::class, ['ownerRecord' => $seen, 'pageClass' => ViewPartnerTask::class]);

    expect(richRenderProse($partnerThread->html()))->toContain('<ul><li>Example comment item</li></ul>');
});
