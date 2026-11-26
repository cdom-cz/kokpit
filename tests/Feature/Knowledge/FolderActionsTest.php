<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\CreateFolder;
use App\Domain\Knowledge\Actions\FolderName;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->create = app(CreateFolder::class);
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
