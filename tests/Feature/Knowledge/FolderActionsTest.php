<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\CreateFolder;
use App\Domain\Knowledge\Actions\FolderName;
use App\Domain\Knowledge\Actions\MoveFolder;
use App\Domain\Knowledge\Actions\RenameFolder;
use App\Domain\Knowledge\Exceptions\FolderMoveRefused;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The folder Actions of the knowledge base (ORG-01, ORG-02, D-03): create, rename, move and delete, with the refusals
 * the Admin sees. Every folder name is fictional.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function folderActionsSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored folder row.
 */
function folderActionsRow(string $id): KnowledgeFolder
{
    return folderActionsSystem(static fn (): KnowledgeFolder => KnowledgeFolder::query()->findOrFail($id));
}

/**
 * The number of stored folders.
 */
function folderActionsCount(): int
{
    return folderActionsSystem(static fn (): int => KnowledgeFolder::query()->count());
}

/**
 * The number of activity rows of any subject (a folder Action must never add one).
 */
function folderActionsActivityCount(): int
{
    return folderActionsSystem(static fn (): int => Activity::query()->count());
}

/**
 * The field errors of the ValidationException the callback throws, or a failure when it throws nothing.
 *
 * @return array<string, list<string>>
 */
function folderActionsErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException but nothing was thrown.');
}

/**
 * The database error of a raw statement the database refuses, caught inside a savepoint so the test transaction lives
 * on. Fails when the database accepts the statement.
 */
function folderActionsProvoke(Closure $statement): QueryException
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the database to refuse the statement but it was accepted.');
}

/**
 * Moves a folder through a raw UPDATE of its parent, around every check of the Action.
 */
function folderActionsRawMove(string $folderId, string $parentId): QueryException
{
    return folderActionsProvoke(static fn (): int => DB::update('update knowledge_folders set parent_id = ? where id = ?', [$parentId, $folderId]));
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->create = app(CreateFolder::class);
    $this->rename = app(RenameFolder::class);
    $this->move = app(MoveFolder::class);
});

it('creates a root folder and a subfolder, trimming the typed name', function (): void {
    $root = $this->create->handle($this->admin, '  Example ops ', null);
    $child = $this->create->handle($this->admin, 'Example servers', $root->id);

    expect($root->name)->toBe('Example ops')
        ->and(folderActionsRow($root->id)->parent_id)->toBeNull()
        ->and(folderActionsRow($child->id)->name)->toBe('Example servers')
        ->and(folderActionsRow($child->id)->parent_id)->toBe($root->id)
        ->and(folderActionsCount())->toBe(2);
});

it('writes no activity row when a folder is created', function (): void {
    $before = folderActionsActivityCount();

    $this->create->handle($this->admin, 'Example ops', null);

    expect(folderActionsActivityCount())->toBe($before);
});

it('refuses an empty, over-long or NUL-carrying name with a field error on name and stores nothing', function (): void {
    $cases = [
        'empty' => ['', 'Vyplňte název složky.'],
        'spaces' => ["  \t ", 'Vyplňte název složky.'],
        'not text' => [['Example'], 'Vyplňte název složky.'],
        'too long' => [str_repeat('a', FolderName::MAX_LENGTH + 1), 'Název složky může mít nejvýše '.FolderName::MAX_LENGTH.' znaků.'],
        'nul inside' => ["Example\0name", __('knowledge.errors.nul_character')],
        'nul at the end' => ["Example\0", __('knowledge.errors.nul_character')],
    ];

    foreach ($cases as [$name, $message]) {
        $errors = folderActionsErrors(fn () => $this->create->handle($this->admin, $name, null));

        expect($errors['name'] ?? [])->toBe([$message]);
    }

    expect(folderActionsCount())->toBe(0);
});

it('accepts a name of exactly the maximum length', function (): void {
    $folder = $this->create->handle($this->admin, str_repeat('a', FolderName::MAX_LENGTH), null);

    expect(mb_strlen(folderActionsRow($folder->id)->name))->toBe(FolderName::MAX_LENGTH);
});

it('refuses a sibling name that differs only in case and accepts the same name under another parent', function (): void {
    $root = $this->create->handle($this->admin, 'Example ops', null);
    $other = $this->create->handle($this->admin, 'Example other', null);
    $this->create->handle($this->admin, 'Example servers', $root->id);

    $errors = folderActionsErrors(fn () => $this->create->handle($this->admin, 'example OPS', null));
    $childErrors = folderActionsErrors(fn () => $this->create->handle($this->admin, 'EXAMPLE servers', $root->id));

    expect($errors)->toHaveKey('name', ['Složka s tímto názvem už na stejné úrovni existuje.'])
        ->and($childErrors)->toHaveKey('name', ['Složka s tímto názvem už na stejné úrovni existuje.'])
        ->and(folderActionsCount())->toBe(3);

    // The same name one level down, and the name of a child at the root, are different siblings.
    $this->create->handle($this->admin, 'Example servers', $other->id);
    $this->create->handle($this->admin, 'Example servers', null);

    expect(folderActionsCount())->toBe(5);
});

it('refuses a subfolder below the deepest allowed level and accepts one on the deepest level itself', function (): void {
    $chain = KnowledgeFixtures::folderChain(KnowledgeFolder::MAX_DEPTH - 1);
    $deepest = $this->create->handle($this->admin, 'Example last level', $chain[array_key_last($chain)]->id);

    expect(folderActionsRow($deepest->id)->parent_id)->toBe($chain[array_key_last($chain)]->id);

    $errors = folderActionsErrors(fn () => $this->create->handle($this->admin, 'Example too deep', $deepest->id));

    expect($errors)->toHaveKey('name', [__('knowledge.folders.errors.create_depth', ['max' => KnowledgeFolder::MAX_DEPTH])])
        ->and(folderActionsCount())->toBe(KnowledgeFolder::MAX_DEPTH);
});

it('answers an unknown or malformed parent with not found, never a database error', function (): void {
    foreach ([(string) Str::uuid(), 'not-a-uuid', '1'] as $parentId) {
        expect(fn () => $this->create->handle($this->admin, 'Example ops', $parentId))->toThrow(ModelNotFoundException::class);
    }

    expect(folderActionsCount())->toBe(0);
});

it('refuses a Partner, a user without a role and a fresh user when they create a folder directly', function (): void {
    $actors = [
        'partner' => Canary::partnerFor(Canary::twoClients()[0]),
        'roleless' => Canary::userWithoutRole(null),
        'fresh' => new User,
    ];
    $activity = folderActionsActivityCount();

    foreach ($actors as $label => $actor) {
        expect(fn () => $this->create->handle($actor, 'Example '.$label, null))->toThrow(AuthorizationException::class);
    }

    expect(folderActionsCount())->toBe(0)
        ->and(folderActionsActivityCount())->toBe($activity);
});

it('renames a folder and trims the typed name', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');

    $renamed = $this->rename->handle($this->admin, $folder->id, '  Example operations ');

    expect($renamed->name)->toBe('Example operations')
        ->and(folderActionsRow($folder->id)->name)->toBe('Example operations');
});

it('renames a folder to the same name or to a change of case only without a name error', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');

    $same = $this->rename->handle($this->admin, $folder->id, 'Example ops');
    $cased = $this->rename->handle($this->admin, $folder->id, 'example OPS');

    expect($same->name)->toBe('Example ops')
        ->and($cased->name)->toBe('example OPS')
        ->and(folderActionsRow($folder->id)->name)->toBe('example OPS');
});

it('refuses to rename a folder to the name of a sibling in another case and accepts a name used under another parent', function (): void {
    $root = KnowledgeFixtures::folder('Example ops');
    $first = KnowledgeFixtures::folder('Example servers', $root);
    $second = KnowledgeFixtures::folder('Example network', $root);

    $errors = folderActionsErrors(fn () => $this->rename->handle($this->admin, $second->id, 'EXAMPLE SERVERS'));

    expect($errors)->toHaveKey('name', ['Složka s tímto názvem už na stejné úrovni existuje.'])
        ->and(folderActionsRow($second->id)->name)->toBe('Example network');

    // The root level holds no "Example servers", so the same name is free there.
    $renamedRoot = $this->rename->handle($this->admin, $root->id, 'Example servers');

    expect($renamedRoot->name)->toBe('Example servers')
        ->and(folderActionsRow($first->id)->name)->toBe('Example servers');
});

it('applies the name rules to a rename and answers an unknown folder with not found', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');

    expect(folderActionsErrors(fn () => $this->rename->handle($this->admin, $folder->id, '  ')))->toHaveKey('name')
        ->and(folderActionsErrors(fn () => $this->rename->handle($this->admin, $folder->id, str_repeat('a', FolderName::MAX_LENGTH + 1))))->toHaveKey('name')
        ->and(folderActionsErrors(fn () => $this->rename->handle($this->admin, $folder->id, "Example\0name")))->toHaveKey('name')
        ->and(folderActionsRow($folder->id)->name)->toBe('Example ops');

    foreach ([(string) Str::uuid(), 'not-a-uuid'] as $id) {
        expect(fn () => $this->rename->handle($this->admin, $id, 'Example ops'))->toThrow(ModelNotFoundException::class);
    }
});

it('moves a folder with its subtree under another root and back to the root', function (): void {
    $chain = KnowledgeFixtures::folderChain(3, 'Example chain');
    $target = KnowledgeFixtures::folder('Example target');

    $moved = $this->move->handle($this->admin, $chain[0]->id, $target->id);

    expect($moved->parent_id)->toBe($target->id)
        ->and(folderActionsRow($chain[0]->id)->parent_id)->toBe($target->id)
        ->and(folderActionsRow($chain[1]->id)->parent_id)->toBe($chain[0]->id)
        ->and(folderActionsRow($chain[2]->id)->parent_id)->toBe($chain[1]->id);

    $root = $this->move->handle($this->admin, $chain[0]->id, null);

    expect($root->parent_id)->toBeNull()
        ->and(folderActionsRow($chain[0]->id)->parent_id)->toBeNull();
});

it('treats a move to the current parent as a no-op', function (): void {
    $root = KnowledgeFixtures::folder('Example ops');
    $child = KnowledgeFixtures::folder('Example servers', $root);
    $before = folderActionsRow($child->id)->updated_at;

    $this->move->handle($this->admin, $child->id, $root->id);
    $this->move->handle($this->admin, $root->id, null);

    expect(folderActionsRow($child->id)->parent_id)->toBe($root->id)
        ->and(folderActionsRow($child->id)->updated_at?->equalTo($before))->toBeTrue()
        ->and(folderActionsRow($root->id)->parent_id)->toBeNull();
});

it('refuses to move a folder under itself or under its own descendant as a cycle and changes nothing', function (): void {
    $chain = KnowledgeFixtures::folderChain(3, 'Example chain');

    foreach ([$chain[0]->id, $chain[1]->id, $chain[2]->id] as $parentId) {
        try {
            $this->move->handle($this->admin, $chain[0]->id, $parentId);
            $this->fail('A move into the own subtree was accepted.');
        } catch (FolderMoveRefused $e) {
            expect($e->reason)->toBe(FolderMoveRefused::CYCLE)
                ->and($e->maxDepth)->toBe(KnowledgeFolder::MAX_DEPTH);
        }
    }

    expect(folderActionsRow($chain[0]->id)->parent_id)->toBeNull()
        ->and(folderActionsRow($chain[1]->id)->parent_id)->toBe($chain[0]->id)
        ->and(folderActionsRow($chain[2]->id)->parent_id)->toBe($chain[1]->id);
});

it('refuses a move that makes the tree deeper than the cap, a folder with subfolders counted with them, and accepts the boundary', function (): void {
    $deep = KnowledgeFixtures::folderChain(KnowledgeFolder::MAX_DEPTH - 1, 'Example deep');
    $pair = KnowledgeFixtures::folderChain(2, 'Example pair');

    try {
        // The parent sits on level MAX_DEPTH - 1 and the moved subtree is 2 levels high: one level too many.
        $this->move->handle($this->admin, $pair[0]->id, $deep[array_key_last($deep)]->id);
        $this->fail('A move past the depth cap was accepted.');
    } catch (FolderMoveRefused $e) {
        expect($e->reason)->toBe(FolderMoveRefused::DEPTH)
            ->and($e->maxDepth)->toBe(KnowledgeFolder::MAX_DEPTH);
    }

    expect(folderActionsRow($pair[0]->id)->parent_id)->toBeNull();

    // One level higher, parent level plus subtree height equals the cap exactly: allowed.
    $moved = $this->move->handle($this->admin, $pair[0]->id, $deep[array_key_last($deep) - 1]->id);

    expect($moved->parent_id)->toBe($deep[array_key_last($deep) - 1]->id);
});

it('refuses a move to a parent that already holds a sibling of the same name with a field error on parent_id', function (): void {
    $target = KnowledgeFixtures::folder('Example target');
    KnowledgeFixtures::folder('Example servers', $target);
    $mover = KnowledgeFixtures::folder('EXAMPLE SERVERS');

    $errors = folderActionsErrors(fn () => $this->move->handle($this->admin, $mover->id, $target->id));

    expect($errors)->toHaveKey('parent_id', ['Složka s tímto názvem už na stejné úrovni existuje.'])
        ->and(folderActionsRow($mover->id)->parent_id)->toBeNull();
});

it('answers an unknown or malformed folder or parent of a move with not found', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');

    foreach ([[(string) Str::uuid(), null], [$folder->id, (string) Str::uuid()], ['not-a-uuid', null], [$folder->id, 'not-a-uuid']] as [$id, $parentId]) {
        expect(fn () => $this->move->handle($this->admin, $id, $parentId))->toThrow(ModelNotFoundException::class);
    }

    expect(folderActionsRow($folder->id)->parent_id)->toBeNull();
});

it('maps the database tree trigger to the same reasons: cycle for a folder under itself or its subtree, depth past the cap', function (): void {
    $chain = KnowledgeFixtures::folderChain(3, 'Example chain');
    $deep = KnowledgeFixtures::folderChain(KnowledgeFolder::MAX_DEPTH - 1, 'Example deep');
    $pair = KnowledgeFixtures::folderChain(2, 'Example pair');

    $ownParent = folderActionsRawMove($chain[0]->id, $chain[0]->id);
    $ownSubtree = folderActionsRawMove($chain[0]->id, $chain[2]->id);
    $tooDeep = folderActionsRawMove($pair[0]->id, $deep[array_key_last($deep)]->id);

    expect(MoveFolder::refusalFor($ownParent)?->reason)->toBe(FolderMoveRefused::CYCLE)
        ->and(MoveFolder::refusalFor($ownSubtree)?->reason)->toBe(FolderMoveRefused::CYCLE)
        ->and(MoveFolder::refusalFor($tooDeep)?->reason)->toBe(FolderMoveRefused::DEPTH)
        ->and(MoveFolder::refusalFor($tooDeep)?->maxDepth)->toBe(KnowledgeFolder::MAX_DEPTH);
});

it('leaves any other database error to the caller', function (): void {
    KnowledgeFixtures::folder('Example ops');

    $duplicate = folderActionsProvoke(static fn (): bool => DB::insert(
        'insert into knowledge_folders (id, parent_id, name, created_at, updated_at) values (?, null, ?, now(), now())',
        [(string) Str::uuid7(), 'EXAMPLE OPS'],
    ));

    expect(MoveFolder::refusalFor($duplicate))->toBeNull();
});

it('refuses a Partner, a user without a role and a fresh user when they rename or move a folder directly', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');
    $target = KnowledgeFixtures::folder('Example target');
    $actors = [
        'partner' => Canary::partnerFor(Canary::twoClients()[0]),
        'roleless' => Canary::userWithoutRole(null),
        'fresh' => new User,
    ];
    $activity = folderActionsActivityCount();

    foreach ($actors as $actor) {
        expect(fn () => $this->rename->handle($actor, $folder->id, 'Example renamed'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->move->handle($actor, $folder->id, $target->id))->toThrow(AuthorizationException::class);
    }

    expect(folderActionsRow($folder->id)->name)->toBe('Example ops')
        ->and(folderActionsRow($folder->id)->parent_id)->toBeNull()
        ->and(folderActionsActivityCount())->toBe($activity);
});

it('writes no activity row when a folder is renamed or moved', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');
    $target = KnowledgeFixtures::folder('Example target');
    $before = folderActionsActivityCount();

    $this->rename->handle($this->admin, $folder->id, 'Example renamed');
    $this->move->handle($this->admin, $folder->id, $target->id);

    expect(folderActionsActivityCount())->toBe($before);
});
