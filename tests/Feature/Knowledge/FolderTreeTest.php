<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\CreateFolder;
use App\Domain\Knowledge\Exceptions\FolderMoveRefused;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Knowledge\Queries\FolderTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The folder tree query (ORG-01, D-01): order, depth, height, path, rolled-up article counts, the subtree and the
 * move rules, all from two queries. Every folder name is fictional.
 */

/**
 * The names of the folders in the order the tree lists them.
 *
 * @return list<string>
 */
function folderTreeNames(FolderTree $tree): array
{
    return array_map(static fn (array $node): string => $node['name'], $tree->nodes());
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('lists a root folder and its subfolder, parent first, with depth, height, path and path label', function (): void {
    $root = app(CreateFolder::class)->handle($this->admin, 'Example ops', null);
    $child = app(CreateFolder::class)->handle($this->admin, 'Example servers', $root->id);

    $tree = FolderTree::load();

    expect($tree->nodes())->toBe([
        ['id' => $root->id, 'parentId' => null, 'name' => 'Example ops', 'depth' => 1, 'height' => 2, 'path' => ['Example ops'], 'count' => 0, 'hasChildren' => true],
        ['id' => $child->id, 'parentId' => $root->id, 'name' => 'Example servers', 'depth' => 2, 'height' => 1, 'path' => ['Example ops', 'Example servers'], 'count' => 0, 'hasChildren' => false],
    ])
        ->and($tree->pathLabel($child->id))->toBe('Example ops / Example servers')
        ->and($tree->pathLabel((string) Str::uuid()))->toBeNull()
        ->and($tree->has($child->id))->toBeTrue()
        ->and($tree->has((string) Str::uuid()))->toBeFalse()
        ->and($tree->node($root->id)['name'] ?? null)->toBe('Example ops')
        ->and($tree->node((string) Str::uuid()))->toBeNull()
        ->and($tree->depthOf($child->id))->toBe(2)
        ->and($tree->heightOf($root->id))->toBe(2);
});

it('rolls the active general articles up to every ancestor and leaves the archived ones out', function (): void {
    $root = KnowledgeFixtures::folder('Example ops');
    $child = KnowledgeFixtures::folder('Example servers', $root);
    $empty = KnowledgeFixtures::folder('Example empty', $root);

    KnowledgeFixtures::article(['folder_id' => $child->id, 'title' => 'Example one']);
    KnowledgeFixtures::article(['folder_id' => $child->id, 'title' => 'Example two']);
    KnowledgeFixtures::article(['folder_id' => $root->id, 'title' => 'Example three']);
    KnowledgeFixtures::archived(['folder_id' => $child->id, 'title' => 'Example archived']);
    KnowledgeFixtures::article(['title' => 'Example without a folder']);

    $tree = FolderTree::load();

    expect($tree->node($child->id)['count'] ?? null)->toBe(2)
        ->and($tree->node($root->id)['count'] ?? null)->toBe(3)
        ->and($tree->node($empty->id)['count'] ?? null)->toBe(0);
});

it('orders siblings in Czech alphabetical order', function (): void {
    KnowledgeFixtures::folder('Example chléb');
    KnowledgeFixtures::folder('Example čaj');
    KnowledgeFixtures::folder('Example cukr');
    KnowledgeFixtures::folder('Example hlína');

    expect(folderTreeNames(FolderTree::load()))->toBe(['Example cukr', 'Example čaj', 'Example hlína', 'Example chléb']);
});

it('keeps the children right behind their parent, each level in its own Czech order', function (): void {
    $b = KnowledgeFixtures::folder('Example b');
    $a = KnowledgeFixtures::folder('Example a');
    KnowledgeFixtures::folder('Example y', $b);
    KnowledgeFixtures::folder('Example x', $b);
    KnowledgeFixtures::folder('Example z', $a);

    expect(folderTreeNames(FolderTree::load()))->toBe(['Example a', 'Example z', 'Example b', 'Example x', 'Example y']);
});

it('lists the folder and every descendant of a three-level chain as its subtree', function (): void {
    $chain = KnowledgeFixtures::folderChain(3);
    $other = KnowledgeFixtures::folder('Example other');

    $tree = FolderTree::load();

    expect($tree->subtreeIds($chain[0]->id))->toBe([$chain[0]->id, $chain[1]->id, $chain[2]->id])
        ->and($tree->subtreeIds($chain[1]->id))->toBe([$chain[1]->id, $chain[2]->id])
        ->and($tree->subtreeIds($chain[2]->id))->toBe([$chain[2]->id])
        ->and($tree->subtreeIds($other->id))->toBe([$other->id])
        ->and($tree->subtreeIds((string) Str::uuid()))->toBe([]);
});

it('reports a move into the own subtree as a cycle, a move past the cap as too deep and a move to the root as allowed', function (): void {
    $chain = KnowledgeFixtures::folderChain(3, 'Example chain');
    $deep = KnowledgeFixtures::folderChain(KnowledgeFolder::MAX_DEPTH - 1, 'Example deep');
    $pair = KnowledgeFixtures::folderChain(2, 'Example pair');

    $tree = FolderTree::load();

    expect($tree->moveRefusal($chain[0]->id, $chain[0]->id))->toBe(FolderMoveRefused::CYCLE)
        ->and($tree->moveRefusal($chain[0]->id, $chain[2]->id))->toBe(FolderMoveRefused::CYCLE)
        ->and($tree->moveRefusal($chain[1]->id, $chain[2]->id))->toBe(FolderMoveRefused::CYCLE)
        // The parent is at level MAX_DEPTH - 1 and the moved folder has height 2: one level too many.
        ->and($tree->moveRefusal($pair[0]->id, $deep[array_key_last($deep)]->id))->toBe(FolderMoveRefused::DEPTH)
        // A leaf (height 1) still fits below the same parent.
        ->and($tree->moveRefusal($pair[1]->id, $deep[array_key_last($deep)]->id))->toBeNull()
        ->and($tree->moveRefusal($chain[2]->id, $chain[0]->id))->toBeNull()
        ->and($tree->moveRefusal($chain[1]->id, null))->toBeNull();
});

it('costs two queries however many folders there are and never reads an article body', function (): void {
    $chain = KnowledgeFixtures::folderChain(3);
    KnowledgeFixtures::folder('Example other');
    KnowledgeFixtures::article(['folder_id' => $chain[2]->id, 'body_md' => 'Example body text']);

    // The first read of a request loads the roles of the acting user for the Partner scope; that lookup is not part of
    // the tree, so it is paid before the log starts.
    FolderTree::load();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $tree = FolderTree::load();
    $tree->nodes();
    $tree->moveRefusal($chain[0]->id, $chain[2]->id);

    $queries = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and(implode("\n", $queries))->not->toContain('body_md')
        ->and($tree->nodes())->toHaveCount(4);
});

it('names the cap of the database in every depth rule, never a literal', function (): void {
    $source = (string) file_get_contents(base_path('app/Domain/Knowledge/Queries/FolderTree.php'));

    expect($source)->toContain('KnowledgeFolder::MAX_DEPTH');
});
