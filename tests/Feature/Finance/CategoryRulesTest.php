<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ArchiveTransactionCategory;
use App\Domain\Finance\Actions\DeleteTransactionCategory;
use App\Domain\Finance\Actions\MoveTransactionCategory;
use App\Domain\Finance\Actions\SaveTransactionCategory;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Canary;

/*
 * The rules of the category Actions (FI-01, D-15, D-16). Names are invented; the starter set seeded by the
 * migration is left alone and every test works on rows it creates itself.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * An invented unique category name.
 */
function categoryName(string $label = 'Kategorie'): string
{
    return $label.' '.bin2hex(random_bytes(4));
}

describe('saving a category', function (): void {
    it('creates an expense root, then a child that takes the type of its parent', function (): void {
        $root = app(SaveTransactionCategory::class)->handle($this->admin, null, [
            'name' => categoryName('Kořen'),
            'type' => 'expense',
        ]);

        // The data names the other type; the parent decides.
        $child = app(SaveTransactionCategory::class)->handle($this->admin, null, [
            'name' => categoryName('Dítě'),
            'type' => 'income',
            'parent_id' => $root->id,
        ]);

        expect($root->type)->toBe(TransactionType::Expense)
            ->and($root->parent_id)->toBeNull()
            ->and($root->depth)->toBe(0)
            ->and($child->type)->toBe(TransactionType::Expense)
            ->and($child->parent_id)->toBe($root->id)
            ->and($child->depth)->toBe(1)
            ->and($child->position)->toBe(1);
    });

    it('refuses a Partner', function (): void {
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);

        expect(fn () => app(SaveTransactionCategory::class)->handle($partner, null, [
            'name' => categoryName(),
            'type' => 'expense',
        ]))->toThrow(AuthorizationException::class)
            ->and(TransactionCategory::query()->where('name', 'like', 'Kategorie %')->count())->toBe(0);
    });
});

/**
 * Stores an invented root of the type through the Action.
 */
function ruleRoot(User $actor, string $type = 'expense'): TransactionCategory
{
    return app(SaveTransactionCategory::class)->handle($actor, null, ['name' => categoryName('Kořen'), 'type' => $type]);
}

/**
 * Stores an invented child under the root through the Action.
 */
function ruleChild(User $actor, TransactionCategory $root): TransactionCategory
{
    return app(SaveTransactionCategory::class)->handle($actor, null, ['name' => categoryName('Dítě'), 'parent_id' => $root->id]);
}

/**
 * The ids a picker would offer for the type.
 *
 * @return list<string>
 */
function ruleSelectable(TransactionType $type): array
{
    return TransactionCategory::query()->selectable($type)->pluck('id')->all();
}

/**
 * The names of the children of a root in their stored order.
 *
 * @return list<string>
 */
function ruleOrder(TransactionCategory $root): array
{
    return TransactionCategory::query()->where('parent_id', $root->id)->orderBy('position')->pluck('name')->all();
}

describe('archiving and restoring', function (): void {
    it('hides an archived category from the pickers and brings it back on restore', function (): void {
        $root = ruleRoot($this->admin);

        expect(ruleSelectable(TransactionType::Expense))->toContain($root->id);

        $archived = app(ArchiveTransactionCategory::class)->handle($this->admin, $root, true);

        expect($archived->isArchived())->toBeTrue()
            ->and(ruleSelectable(TransactionType::Expense))->not->toContain($root->id);

        $restored = app(ArchiveTransactionCategory::class)->handle($this->admin, $root, false);

        expect($restored->isArchived())->toBeFalse()
            ->and(ruleSelectable(TransactionType::Expense))->toContain($root->id);
    });

    it('refuses to archive the system category and leaves it active', function (): void {
        $system = TransactionCategory::invoicePayments();

        expect(fn () => app(ArchiveTransactionCategory::class)->handle($this->admin, $system, true))
            ->toThrow(DomainException::class, __('finance.categories.errors.system_locked'))
            ->and($system->refresh()->isArchived())->toBeFalse();
    });

    it('refuses a Partner', function (): void {
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);
        $root = ruleRoot($this->admin);

        expect(fn () => app(ArchiveTransactionCategory::class)->handle($partner, $root, true))
            ->toThrow(AuthorizationException::class)
            ->and($root->refresh()->isArchived())->toBeFalse();
    });
});

describe('deleting', function (): void {
    it('removes a childless unused category', function (): void {
        $root = ruleRoot($this->admin);

        app(DeleteTransactionCategory::class)->handle($this->admin, $root);

        expect(TransactionCategory::query()->whereKey($root->id)->exists())->toBeFalse();
    });

    it('keeps a parent that still has a child and says it is in use', function (): void {
        $root = ruleRoot($this->admin);
        $child = ruleChild($this->admin, $root);

        expect(fn () => app(DeleteTransactionCategory::class)->handle($this->admin, $root))
            ->toThrow(DomainException::class, __('finance.categories.errors.in_use'))
            ->and(TransactionCategory::query()->whereKey([$root->id, $child->id])->count())->toBe(2);
    });

    it('keeps a parent for a stale instance that believes it has no children', function (): void {
        $root = ruleRoot($this->admin);
        $child = ruleChild($this->admin, $root);
        $stale = TransactionCategory::query()->findOrFail($root->id);

        expect(fn () => app(DeleteTransactionCategory::class)->handle($this->admin, $stale))
            ->toThrow(DomainException::class, __('finance.categories.errors.in_use'))
            ->and(TransactionCategory::query()->whereKey($child->id)->exists())->toBeTrue();
    });

    it('translates the foreign key refusal of the database into the same message', function (): void {
        // The transactions table arrives with a later plan; a probe table with the same kind of key
        // stands in for "used by a row the pre-check does not know about".
        Schema::create('category_probe', function (Blueprint $table): void {
            $table->uuid('category_id');
            $table->foreign('category_id')->references('id')->on('transaction_categories');
        });

        $root = ruleRoot($this->admin);
        DB::table('category_probe')->insert(['category_id' => $root->id]);

        expect(fn () => app(DeleteTransactionCategory::class)->handle($this->admin, $root))
            ->toThrow(DomainException::class, __('finance.categories.errors.in_use'))
            ->and(TransactionCategory::query()->whereKey($root->id)->exists())->toBeTrue();
    });

    it('refuses to delete the system category', function (): void {
        $system = TransactionCategory::invoicePayments();

        expect(fn () => app(DeleteTransactionCategory::class)->handle($this->admin, $system))
            ->toThrow(DomainException::class, __('finance.categories.errors.system_locked'))
            ->and(TransactionCategory::query()->whereKey($system->id)->exists())->toBeTrue();
    });

    it('refuses a Partner', function (): void {
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);
        $root = ruleRoot($this->admin);

        expect(fn () => app(DeleteTransactionCategory::class)->handle($partner, $root))
            ->toThrow(AuthorizationException::class)
            ->and(TransactionCategory::query()->whereKey($root->id)->exists())->toBeTrue();
    });
});

describe('moving inside the siblings', function (): void {
    it('swaps positions with the neighbour only inside the sibling group', function (): void {
        $root = ruleRoot($this->admin);
        $other = ruleRoot($this->admin);
        $a = ruleChild($this->admin, $root);
        $b = ruleChild($this->admin, $root);
        $c = ruleChild($this->admin, $root);
        $foreign = ruleChild($this->admin, $other);

        app(MoveTransactionCategory::class)->handle($this->admin, $c, 'up');

        expect(ruleOrder($root))->toBe([$a->name, $c->name, $b->name]);

        app(MoveTransactionCategory::class)->handle($this->admin, $a, 'down');

        expect(ruleOrder($root))->toBe([$c->name, $a->name, $b->name])
            ->and($foreign->refresh()->position)->toBe(1)
            ->and($other->refresh()->position)->toBe($root->position + 1);
    });

    it('changes nothing when the first sibling moves up or the last moves down', function (): void {
        $root = ruleRoot($this->admin);
        $a = ruleChild($this->admin, $root);
        $b = ruleChild($this->admin, $root);

        app(MoveTransactionCategory::class)->handle($this->admin, $a, 'up');
        app(MoveTransactionCategory::class)->handle($this->admin, $b, 'down');

        expect(ruleOrder($root))->toBe([$a->name, $b->name]);
    });

    it('moves roots of one type among themselves, not among the roots of the other type', function (): void {
        $expense = ruleRoot($this->admin, 'expense');
        $income = ruleRoot($this->admin, 'income');

        $before = $income->position;
        app(MoveTransactionCategory::class)->handle($this->admin, $expense, 'up');

        expect($income->refresh()->position)->toBe($before);
    });

    it('rejects a direction other than up or down', function (): void {
        $root = ruleRoot($this->admin);

        expect(fn () => app(MoveTransactionCategory::class)->handle($this->admin, $root, 'sideways'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses a Partner', function (): void {
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);
        $root = ruleRoot($this->admin);

        expect(fn () => app(MoveTransactionCategory::class)->handle($partner, $root, 'up'))
            ->toThrow(AuthorizationException::class);
    });
});
