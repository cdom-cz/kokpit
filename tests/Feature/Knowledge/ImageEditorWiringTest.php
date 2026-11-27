<?php

declare(strict_types=1);

use App\Domain\Knowledge\Images\ImagePipeline;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use Filament\Facades\Filament;
use Filament\Forms\Components\MarkdownEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\ImageFixtures;
use Tests\Support\KnowledgeFixtures;

/*
 * The wiring of the image insertion in the editor (Phase 20, plan 20-08; IMG-01, D-01, D-03, D-05): the body field
 * hands its texts and limits to the editor script through data attributes, Filament's own attachment flow stays off,
 * the secret notice appears after the first stored upload, and the script holds the calls the page depends on. The
 * browser behaviour of the script (clipboard, drag and drop, CodeMirror events) is covered by the end-of-phase check
 * in a browser, because the repository has no JavaScript test tooling.
 */

/**
 * The text of the secret notice (D-05).
 */
function imageEditorWiringNotice(): string
{
    return 'Obrázek může obsahovat tajné údaje a jeho obsah nejde automaticky zkontrolovat. Pokud ano, označte článek jako citlivý.';
}

/**
 * The Markdown editor of a create or edit page.
 */
function imageEditorWiringEditor(Testable $page): MarkdownEditor
{
    $editors = array_values(array_filter(
        $page->instance()->form->getFlatComponents(),
        static fn (object $component): bool => $component instanceof MarkdownEditor,
    ));

    expect($editors)->toHaveCount(1);

    return $editors[0];
}

/**
 * Stores a fictional file as the upload of a create or edit page, the way the editor script does.
 */
function imageEditorWiringUpload(Testable $page, string $bytes): void
{
    $page
        ->set('imageUpload', UploadedFile::fake()->createWithContent('screenshot-example.png', $bytes))
        ->call('storeImageUpload');
}

/**
 * The raw source of the editor script.
 */
function imageEditorWiringScript(): string
{
    $source = file_get_contents(resource_path('js/knowledge-editor.js'));

    expect($source)->toBeString()->not->toBe('');

    return (string) $source;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake();

    $this->actingAs(Canary::admin());
});

it('keeps Filament attachment flow off and offers no attach button in the toolbar', function (): void {
    foreach ([
        Livewire::test(CreateKnowledgeArticle::class),
        Livewire::test(EditKnowledgeArticle::class, ['record' => KnowledgeFixtures::article()->getRouteKey()]),
    ] as $page) {
        $editor = imageEditorWiringEditor($page);
        $buttons = array_merge(...array_map(array_values(...), $editor->getToolbarButtons()));

        expect($editor->hasFileAttachments())->toBeFalse()
            ->and($buttons)->not->toContain('attachFiles')
            ->and($buttons)->toContain('table');
    }
});

it('hands the editor script its texts and limits through data attributes', function (): void {
    $html = Livewire::test(CreateKnowledgeArticle::class)->html();

    foreach ([
        'data-kokpit-image' => 'Vložit obrázek',
        'data-kokpit-image-uploading' => 'Nahrávám obrázek…',
        'data-kokpit-image-done' => 'Obrázek byl vložen',
        'data-kokpit-image-error-title' => 'Obrázek nebyl nahrán',
        'data-kokpit-image-too-large' => 'Soubor má více než 10 MB. Zmenšete ho a nahrajte znovu.',
        'data-kokpit-image-failed' => 'Obrázek se nepodařilo zpracovat. Zkuste to znovu.',
        'data-kokpit-image-max-bytes' => (string) ImagePipeline::MAX_UPLOAD_BYTES,
        'data-kokpit-image-accept' => 'image/png,image/jpeg,image/webp,image/gif',
    ] as $name => $value) {
        expect($html)->toContain($name.'="'.$value.'"');
    }
});

it('shows no secret notice on a fresh create or edit screen', function (): void {
    Livewire::test(CreateKnowledgeArticle::class)
        ->assertDontSee(imageEditorWiringNotice())
        ->assertDontSeeHtml('data-kokpit-image-notice');

    Livewire::test(EditKnowledgeArticle::class, ['record' => KnowledgeFixtures::article()->getRouteKey()])
        ->assertDontSee(imageEditorWiringNotice())
        ->assertDontSeeHtml('data-kokpit-image-notice');
});

it('shows the secret notice as a status line above the sensitive toggle after the first stored upload, and keeps it', function (): void {
    $page = Livewire::test(CreateKnowledgeArticle::class);

    imageEditorWiringUpload($page, ImageFixtures::png());

    $page->assertSee(imageEditorWiringNotice())->assertSeeHtml('data-kokpit-image-notice');

    $html = $page->html();

    expect($html)->toContain('role="status"')
        ->and(strpos($html, 'data-kokpit-image-notice'))->toBeInt()
        ->and(strpos($html, 'Citlivý článek'))->toBeInt()
        ->and(strpos($html, 'data-kokpit-image-notice'))->toBeLessThan((int) strpos($html, 'Citlivý článek'));

    // A later render of the page (here the tab switch) keeps it.
    $page->set('activeTab', 'preview')->assertSee(imageEditorWiringNotice());
});

it('shows the secret notice on the edit screen after a stored upload', function (): void {
    $page = Livewire::test(EditKnowledgeArticle::class, ['record' => KnowledgeFixtures::article()->getRouteKey()]);

    imageEditorWiringUpload($page, ImageFixtures::png());

    $page->assertSee(imageEditorWiringNotice());
});

it('keeps the secret notice away after a refused upload', function (): void {
    $page = Livewire::test(CreateKnowledgeArticle::class);

    imageEditorWiringUpload($page, ImageFixtures::svg());

    $page->assertDontSee(imageEditorWiringNotice())->assertDontSeeHtml('data-kokpit-image-notice');
});

it('gives the editor script the calls the page depends on and no word of its own', function (): void {
    $source = imageEditorWiringScript();

    foreach ([
        'storeImageUpload',
        "upload('imageUpload'",
        "on('paste'",
        "on('drop'",
        'coordsChar',
        'FilamentNotification',
        'kokpit-insert-image',
        "Livewire.hook('commit'",
    ] as $needle) {
        expect($source)->toContain($needle);
    }

    // No character of the Czech alphabet (or any other non-ASCII one): every text arrives in a data attribute.
    expect(preg_match('/[^\x00-\x7F]/', $source))->toBe(0);
});
