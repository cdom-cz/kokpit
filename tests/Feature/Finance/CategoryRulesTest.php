<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\SaveTransactionCategory;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use Illuminate\Auth\Access\AuthorizationException;
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
