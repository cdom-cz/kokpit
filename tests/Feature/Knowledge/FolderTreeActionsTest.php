<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The folder actions of the tree on the general knowledge list (D-03, ORG-01, ORG-02): create, create a subfolder,
 * rename, move and delete, each with its refusals explained in Czech under the field or in a notification. A forged
 * folder id or move target ends in the same refusals, because the Actions of the domain decide again. Every name is
 * fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

/**
 * The list page, with the folder selected when an id is given.
 */
function folderTreeActionsPage(?string $folder = null): Testable
{
    return Livewire::withQueryParams(['folder' => $folder])->test(ListKnowledgeArticles::class);
}

/**
 * The folder of the name, read back.
 */
function folderTreeActionsFolder(string $name): ?KnowledgeFolder
{
    return KnowledgeFolder::query()->where('name', $name)->first();
}

/**
 * The expected danger notification of a refusal.
 */
function folderTreeActionsRefusal(string $title, string $body): Notification
{
    return Notification::make()->danger()->persistent()->title($title)->body($body);
}

/**
 * The private move rule of the page, asked for one folder and one target of the select.
 */
function folderTreeActionsDisabled(Testable $page, string $folderId, string $target): bool
{
    $instance = $page->instance();
    $method = new ReflectionMethod($instance, 'moveOptionDisabled');

    return (bool) $method->invoke($instance, $folderId, $target);
}

it('creates a root folder and shows it in the tree with a count of zero', function (): void {
    folderTreeActionsPage()
        ->callAction('createFolder', ['name' => 'Example ops'])
        ->assertNotified('Složka byla vytvořena')
        ->assertSee('Example ops')
        ->assertSeeHtml('<span class="kokpit-tree-count">0</span>');

    expect(folderTreeActionsFolder('Example ops')?->parent_id)->toBeNull();
});

it('creates a subfolder under the folder of the argument', function (): void {
    $root = KnowledgeFixtures::folder('Example ops');

    folderTreeActionsPage()
        ->callAction('createSubfolder', ['name' => 'Example servers'], ['folder' => (string) $root->getKey()])
        ->assertNotified('Složka byla vytvořena');

    expect(folderTreeActionsFolder('Example servers')?->parent_id)->toBe($root->getKey());
});

it('offers no subfolder item at the deepest level and refuses a forged one under the name field', function (): void {
    $chain = KnowledgeFixtures::folderChain(KnowledgeFolder::MAX_DEPTH);
    $deepest = (string) $chain[KnowledgeFolder::MAX_DEPTH - 1]->getKey();
    $above = (string) $chain[KnowledgeFolder::MAX_DEPTH - 2]->getKey();

    $page = folderTreeActionsPage();

    $page->assertSeeHtml("mountAction('createSubfolder', { folder: '{$above}' })")
        ->assertDontSeeHtml("mountAction('createSubfolder', { folder: '{$deepest}' })");

    $page->callAction('createSubfolder', ['name' => 'Example too deep'], ['folder' => $deepest])
        ->assertHasErrors(['mountedActions.0.data.name']);

    expect($page->errors()->first('mountedActions.0.data.name'))
        ->toBe(__('knowledge.folders.errors.create_depth', ['max' => KnowledgeFolder::MAX_DEPTH]))
        ->and(folderTreeActionsFolder('Example too deep'))->toBeNull();
});

it('refuses a duplicate name on rename under the field and keeps the modal open, and renames otherwise', function (): void {
    KnowledgeFixtures::folder('Example alpha');
    $beta = KnowledgeFixtures::folder('Example beta');

    $page = folderTreeActionsPage();

    $page->callAction('renameFolder', ['name' => 'EXAMPLE ALPHA'], ['folder' => (string) $beta->getKey()])
        ->assertHasErrors(['mountedActions.0.data.name']);

    expect($page->errors()->first('mountedActions.0.data.name'))->toBe('Složka s tímto názvem už na stejné úrovni existuje.')
        ->and($page->instance()->mountedActions)->not->toBe([])
        ->and($beta->fresh()->name)->toBe('Example beta');

    folderTreeActionsPage()
        ->callAction('renameFolder', ['name' => 'Example gamma'], ['folder' => (string) $beta->getKey()])
        ->assertNotified('Složka byla přejmenována')
        ->assertSee('Example gamma');

    expect($beta->fresh()->name)->toBe('Example gamma');
});

it('fills the rename form with the current name', function (): void {
    $folder = KnowledgeFixtures::folder('Example current');

    folderTreeActionsPage()
        ->mountAction('renameFolder', arguments: ['folder' => (string) $folder->getKey()])
        ->assertActionDataSet(['name' => 'Example current']);
});

it('disables the folder itself, its subfolders and every target past the depth cap in the move select', function (): void {
    $chain = KnowledgeFixtures::folderChain(5);
    $mover = KnowledgeFixtures::folder('Example mover');
    $moverChild = KnowledgeFixtures::folder('Example mover child', $mover);

    $page = folderTreeActionsPage();
    $moverId = (string) $mover->getKey();

    // A subtree of height 2 fits below a folder of level 4 (6 levels) and not below one of level 5 (7 levels).
    expect(folderTreeActionsDisabled($page, $moverId, $moverId))->toBeTrue()
        ->and(folderTreeActionsDisabled($page, $moverId, (string) $moverChild->getKey()))->toBeTrue()
        ->and(folderTreeActionsDisabled($page, $moverId, (string) $chain[3]->getKey()))->toBeFalse()
        ->and(folderTreeActionsDisabled($page, $moverId, (string) $chain[4]->getKey()))->toBeTrue()
        ->and(folderTreeActionsDisabled($page, $moverId, 'root'))->toBeFalse();

    $page->mountAction('moveFolder', arguments: ['folder' => $moverId])
        ->assertSee('Strom může mít nejvýše '.KnowledgeFolder::MAX_DEPTH.' úrovní.');
});

it('moves a folder and keeps the selection', function (): void {
    $source = KnowledgeFixtures::folder('Example source');
    $target = KnowledgeFixtures::folder('Example target');

    folderTreeActionsPage((string) $source->getKey())
        ->callAction('moveFolder', ['parent_id' => (string) $target->getKey()], ['folder' => (string) $source->getKey()])
        ->assertNotified('Složka byla přesunuta')
        ->assertSet('folder', (string) $source->getKey());

    expect($source->fresh()->parent_id)->toBe($target->getKey());
});

it('moves a folder to the root', function (): void {
    $parent = KnowledgeFixtures::folder('Example parent');
    $child = KnowledgeFixtures::folder('Example child', $parent);

    folderTreeActionsPage()
        ->callAction('moveFolder', ['parent_id' => 'root'], ['folder' => (string) $child->getKey()])
        ->assertNotified('Složka byla přesunuta');

    expect($child->fresh()->parent_id)->toBeNull();
});

it('explains a forged move into the own subtree and a forged move past the cap', function (): void {
    $mover = KnowledgeFixtures::folder('Example mover');
    $moverChild = KnowledgeFixtures::folder('Example mover child', $mover);
    $chain = KnowledgeFixtures::folderChain(5);

    folderTreeActionsPage()
        ->callAction('moveFolder', ['parent_id' => (string) $moverChild->getKey()], ['folder' => (string) $mover->getKey()])
        ->assertNotified(folderTreeActionsRefusal('Složku se nepodařilo přesunout', 'Složku nelze přesunout do sebe ani do její vlastní podsložky.'));

    folderTreeActionsPage()
        ->callAction('moveFolder', ['parent_id' => (string) $chain[4]->getKey()], ['folder' => (string) $mover->getKey()])
        ->assertNotified(folderTreeActionsRefusal('Složku se nepodařilo přesunout', 'Složku nelze přesunout sem: strom by byl hlubší než '.KnowledgeFolder::MAX_DEPTH.' úrovní. Složka s podsložkami se počítá i s nimi.'));

    expect($mover->fresh()->parent_id)->toBeNull();
});

it('asks for confirmation before it deletes an empty folder, then deletes it', function (): void {
    $folder = KnowledgeFixtures::folder('Example empty');
    $arguments = ['folder' => (string) $folder->getKey()];

    $page = folderTreeActionsPage();

    $page->mountAction('deleteFolder', arguments: $arguments)
        ->assertSee('Smazat složku „Example empty“');

    expect($page->instance()->mountedActions)->not->toBe([])
        ->and(folderTreeActionsFolder('Example empty'))->not->toBeNull();

    $page->callAction('deleteFolder', arguments: $arguments)
        ->assertNotified('Složka byla smazána');

    expect(folderTreeActionsFolder('Example empty'))->toBeNull();
});

it('refuses a folder that holds a subfolder and an archived article before any confirmation', function (): void {
    $folder = KnowledgeFixtures::folder('Example busy');
    KnowledgeFixtures::folder('Example busy child', $folder);
    KnowledgeFixtures::archived(['title' => 'Example archived article', 'folder_id' => $folder->getKey()]);

    $page = folderTreeActionsPage();

    $page->mountAction('deleteFolder', arguments: ['folder' => (string) $folder->getKey()])
        ->assertNotified(folderTreeActionsRefusal(
            'Složku nelze smazat.',
            'Složka není prázdná: obsahuje 1 článek (včetně archivovaných) a 1 podsložku. Přesuňte obsah jinam a zkuste to znovu.',
        ));

    // No confirmation modal was opened: the refusal came first.
    expect($page->instance()->mountedActions)->toBe([])
        ->and(folderTreeActionsFolder('Example busy'))->not->toBeNull();
});

it('resets the selection when the selected folder is deleted', function (): void {
    $folder = KnowledgeFixtures::folder('Example empty');

    folderTreeActionsPage((string) $folder->getKey())
        ->assertSet('folder', (string) $folder->getKey())
        ->callAction('deleteFolder', arguments: ['folder' => (string) $folder->getKey()])
        ->assertSet('folder', null);
});

it('says the folder is gone for an unknown or malformed folder id and changes nothing', function (string $kind): void {
    $existing = KnowledgeFixtures::folder('Example existing');
    $id = $kind === 'unknown' ? (string) Str::uuid() : 'not-a-uuid';

    $page = folderTreeActionsPage();

    $page->callAction('renameFolder', ['name' => 'Example renamed'], ['folder' => $id])
        ->assertNotified('Složka už neexistuje. Strom se načetl znovu.');
    $page->callAction('moveFolder', ['parent_id' => 'root'], ['folder' => $id])
        ->assertNotified('Složka už neexistuje. Strom se načetl znovu.');
    $page->callAction('deleteFolder', arguments: ['folder' => $id])
        ->assertNotified('Složka už neexistuje. Strom se načetl znovu.');
    $page->callAction('createSubfolder', ['name' => 'Example child'], ['folder' => $id])
        ->assertNotified('Složka už neexistuje. Strom se načetl znovu.');

    expect(KnowledgeFolder::query()->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Example existing');
})->with(['unknown uuid' => ['unknown'], 'not a uuid' => ['malformed']]);
