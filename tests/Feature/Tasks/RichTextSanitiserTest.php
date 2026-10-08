<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Text\RichText;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * D-10: task text is stored and shown only as clean HTML, whoever wrote it. The
 * payloads are assembled from fragments at runtime, so no single line of this file
 * looks like a payload constant; every marker word is fictional.
 */

/**
 * The payload parts, each carrying a marker word that must never survive.
 *
 * @return array<string, string>
 */
function richParts(): array
{
    $lt = '<';

    return [
        'script' => $lt.'scr'.'ipt>window.markerOne()'.$lt.'/scr'.'ipt>',
        'handler' => $lt.'img src="x" on'.'error="markerTwo()">',
        'link' => $lt.'a href="java'.'script:markerThree()">Example link text'.$lt.'/a>',
        'style' => $lt.'p style="position:'.'fixed;top:markerFour" class="markerFive">Benign words'.$lt.'/p>',
        'frame' => $lt.'ifr'.'ame src="https://example.com/markerSix">'.$lt.'/ifr'.'ame>',
        'svg' => $lt.'s'.'vg on'.'load="markerSeven()">'.$lt.'/s'.'vg>',
    ];
}

/**
 * The marker words, none of which may appear in stored or rendered text.
 *
 * @return list<string>
 */
function richMarkers(): array
{
    return ['markerOne', 'markerTwo', 'markerThree', 'markerFour', 'markerFive', 'markerSix', 'markerSeven'];
}

/**
 * A project and its task through the Actions, as the signed-in Admin.
 */
function richTask(bool $visible = false): Task
{
    $project = app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example sanitiser project',
        'key' => 'SAN',
        'billing_type' => 'hourly',
        'client_visible' => $visible,
    ]);

    return app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example sanitised task']);
}

/**
 * The HTML of a Livewire component without its snapshot attribute, which carries the raw state.
 */
function richPageHtml(string $html): string
{
    return (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('keeps paragraphs, emphasis, lists, headings, tables and safe links, and forces the link attributes', function (): void {
    $html = '<h2>Example heading</h2><p>Plain <b>bold</b> and <i>italic</i> text.</p><ul><li>One</li></ul><ol><li>Two</li></ol>'
        .'<table><tbody><tr><td>Cell</td></tr></tbody></table>'
        .'<p><a href="https://example.com/a">Secure</a> <a href="mailto:jane@example.com">Mail</a></p>';

    $clean = RichText::clean($html);

    expect($clean)->toContain('<h2>Example heading</h2>')
        ->toContain('<b>bold</b>')
        ->toContain('<i>italic</i>')
        ->toContain('<ul><li>One</li></ul>')
        ->toContain('<ol><li>Two</li></ol>')
        ->toContain('<td>Cell</td>')
        ->toContain('href="https://example.com/a"')
        // The sanitiser writes the at sign as a character reference, which a browser reads back as the same address.
        ->toContain('href="mailto:jane&#64;example.com"')
        ->toContain('rel="noopener noreferrer nofollow"')
        ->toContain('target="_blank"');
});

it('removes styles, classes, handlers, scripts, images, frames, svg elements and script links', function (): void {
    $clean = RichText::clean(implode('', richParts()));

    expect($clean)->not->toBeNull()->toContain('Benign words');

    foreach (richMarkers() as $marker) {
        expect($clean)->not->toContain($marker);
    }

    expect($clean)->not->toContain('style')
        ->not->toContain('class')
        ->not->toContain('<img')
        ->not->toContain('<script')
        ->not->toContain('<iframe')
        ->not->toContain('<svg')
        ->not->toContain('javascript:');
});

it('keeps the text of a link whose script href is dropped', function (): void {
    $clean = RichText::clean(richParts()['link']);

    expect($clean)->toContain('Example link text')->not->toContain('href');
});

it('drops relative links and unknown schemes', function (): void {
    $clean = RichText::clean('<p><a href="/admin/tasks">Relative</a> <a href="ftp://example.com/file">Ftp</a></p>');

    expect($clean)->toContain('Relative')->toContain('Ftp')->not->toContain('href');
});

it('returns null for null, an empty editor and whitespace-only content', function (): void {
    expect(RichText::clean(null))->toBeNull()
        ->and(RichText::clean(''))->toBeNull()
        ->and(RichText::clean("  \n "))->toBeNull()
        ->and(RichText::clean('<p></p>'))->toBeNull()
        ->and(RichText::clean('<p><br></p>'))->toBeNull()
        ->and(RichText::clean(implode('', [richParts()['script'], '<p> </p>'])))->toBeNull();
});

it('refuses input over the length limit and accepts input at it', function (): void {
    $atLimit = str_repeat('a', RichText::MAX_LENGTH);

    expect(RichText::clean($atLimit))->toBe($atLimit)
        ->and(fn () => RichText::clean($atLimit.'a'))->toThrow(InvalidArgumentException::class);
});

it('renders stored html harmless without a length refusal', function (): void {
    $html = implode('', richParts()).str_repeat('a', RichText::MAX_LENGTH + 10);

    $rendered = RichText::render($html)->toHtml();

    foreach (richMarkers() as $marker) {
        expect($rendered)->not->toContain($marker);
    }

    expect($rendered)->toContain('Benign words')
        ->and(RichText::render(null)->toHtml())->toBe('');
});

it('stores a description from UpdateTask without any dangerous part and keeps the benign words', function (): void {
    $task = richTask();

    app(UpdateTask::class)->handle($this->admin, $task, ['description' => implode('', richParts())]);

    $stored = (string) $task->refresh()->description;

    foreach (richMarkers() as $marker) {
        expect($stored)->not->toContain($marker);
    }

    expect($stored)->toContain('Benign words')->not->toContain('<script')->not->toContain('onerror')->not->toContain('style=');
});

it('stores a description from CreateTask without any dangerous part', function (): void {
    $project = app(CreateProject::class)->handle(Client::factory()->create(), ['name' => 'Example created project', 'key' => 'CRE', 'billing_type' => 'hourly']);

    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example created task', 'description' => implode('', richParts())]);

    foreach (richMarkers() as $marker) {
        expect((string) $task->description)->not->toContain($marker);
    }

    expect((string) $task->description)->toContain('Benign words');
});

it('stores no description for an empty editor value', function (): void {
    $task = richTask();

    app(UpdateTask::class)->handle($this->admin, $task, ['description' => '<p>Example text</p>']);
    app(UpdateTask::class)->handle($this->admin, $task, ['description' => '<p></p>']);

    expect($task->refresh()->description)->toBeNull();
});

it('turns an over-long description into a field error and writes nothing', function (): void {
    $task = richTask();
    $long = '<p>'.str_repeat('a', RichText::MAX_LENGTH).'</p>';

    foreach ([
        fn () => app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example changed title', 'description' => $long]),
        fn () => app(CreateTask::class)->handle($this->admin, $task->project, ['title' => 'Example long task', 'description' => $long]),
    ] as $call) {
        try {
            $call();
            $errors = [];
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        expect($errors)->toHaveKey('description')
            ->and($errors['description'][0])->toBe(__('kokpit.tasks.errors.description_too_long'));
    }

    expect($task->refresh()->title)->toBe('Example sanitised task')
        ->and(Task::query()->count())->toBe(1);
});

it('shows the Admin a stored description without any dangerous part', function (): void {
    $task = richTask();

    app(UpdateTask::class)->handle($this->admin, $task, ['description' => implode('', richParts())]);

    $html = richPageHtml(Livewire::test(ViewTask::class, ['record' => $task->reference])->assertSee('Benign words')->html());

    foreach (richMarkers() as $marker) {
        expect($html)->not->toContain($marker);
    }
});

it('renders raw html written straight into the column harmlessly on the task page', function (): void {
    $task = richTask();

    // A write that bypasses the Action: a query update, as an import or a bug could do.
    Task::query()->whereKey($task->id)->update(['description' => implode('', richParts())]);

    $html = richPageHtml(Livewire::test(ViewTask::class, ['record' => $task->reference])->assertSee('Benign words')->html());

    foreach (richMarkers() as $marker) {
        expect($html)->not->toContain($marker);
    }
});

it('saves a description from the edit page through the sanitiser', function (): void {
    $task = richTask();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['description' => '<p>Benign words '.richParts()['handler'].'</p>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((string) $task->refresh()->description)->toContain('Benign words')->not->toContain('markerTwo')->not->toContain('<img');
});

it('configures the description editor without file attachments and without the attach button', function (): void {
    $task = richTask();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->assertFormFieldExists('description', static function (RichEditor $editor): bool {
            $buttons = collect($editor->getToolbarButtons())->flatten()->all();

            return ! $editor->hasFileAttachments() && ! in_array('attachFiles', $buttons, true) && $buttons !== [];
        });
});

it('stores no file when an upload is forged against the description editor', function (): void {
    $disks = array_keys((array) config('filesystems.disks'));

    foreach ($disks as $disk) {
        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            Storage::fake($disk);
        }
    }

    $task = richTask();

    $component = Livewire::test(EditTask::class, ['record' => $task->reference])
        ->set('componentFileAttachments.data.description', UploadedFile::fake()->image('example.png'));

    // The call a forged browser request makes: the exposed upload method of the editor, by its component key.
    $key = $component->instance()->form->getFlatFields(withHidden: true)['description']->getKey();

    expect($key)->toBe('form.description');

    // With attachments off Filament refuses to store and then fails on the missing path (a TypeError in
    // the URL lookup), so a forged request ends in an error; what counts is that nothing is stored.
    try {
        $component->call('callSchemaComponentMethod', $key, 'saveUploadedFileAttachmentAndGetUrl');
    } catch (TypeError) {
        // Expected: see above.
    }

    foreach ($disks as $disk) {
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            continue;
        }

        $files = array_filter(
            Storage::disk($disk)->allFiles(),
            static fn (string $path): bool => ! str_contains($path, 'livewire-tmp'),
        );

        expect($files)->toBe([], "a file was stored on the {$disk} disk");
    }
});
