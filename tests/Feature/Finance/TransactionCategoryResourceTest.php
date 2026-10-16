<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Filament\Resources\TransactionCategoryResource;
use App\Filament\Resources\TransactionCategoryResource\Pages\ListTransactionCategories;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin-only "Kategorie" screen (FI-01, D-15, D-16): the two-level tree with create and edit in modals.
 * Names are invented; the seeded starter set and the system category are read as they are.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

describe('the tracer: open the screen, build the tree', function (): void {
    it('opens for the Admin with the heading', function (): void {
        $this->get(TransactionCategoryResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Kategorie');
    });

    it('shows the seeded set with every child directly under its parent', function (): void {
        $parent = TransactionCategory::query()->where('name', 'Software')->whereNull('parent_id')->firstOrFail();
        $children = TransactionCategory::query()->where('parent_id', $parent->id)->orderBy('position')->get();

        expect($children)->not->toBeEmpty();

        $names = [$parent->name, ...$children->pluck('name')->all()];

        Livewire::test(ListTransactionCategories::class)
            ->assertSuccessful()
            ->assertSeeInOrder($names);

        // The order the table reads is parent, then its children, then the next root.
        $rows = Livewire::test(ListTransactionCategories::class)->instance()->getTableRecords()->pluck('name')->all();
        $start = array_search($parent->name, $rows, true);

        expect($start)->not->toBeFalse()
            ->and(array_slice($rows, (int) $start, count($names)))->toBe($names);
    });

    it('creates a root with a type and a child that takes the type of its parent in the create modal', function (): void {
        $rootName = 'Kořenová '.bin2hex(random_bytes(3));
        $childName = 'Podkategorie '.bin2hex(random_bytes(3));

        Livewire::test(ListTransactionCategories::class)
            ->callAction('create', ['name' => $rootName, 'type' => 'income', 'parent_id' => null])
            ->assertHasNoFormErrors();

        $root = TransactionCategory::query()->where('name', $rootName)->firstOrFail();

        Livewire::test(ListTransactionCategories::class)
            ->callAction('create', ['name' => $childName, 'type' => 'income', 'parent_id' => $root->id])
            ->assertHasNoFormErrors();

        $child = TransactionCategory::query()->where('name', $childName)->firstOrFail();

        expect($root->type)->toBe(TransactionType::Income)
            ->and($root->depth)->toBe(0)
            ->and($child->type)->toBe(TransactionType::Income)
            ->and($child->parent_id)->toBe($root->id)
            ->and($child->depth)->toBe(1);
    });

    it('offers only active roots of the chosen type as parent', function (): void {
        $income = TransactionCategory::query()->where('type', 'income')->whereNull('parent_id')->pluck('id')->all();

        $options = TransactionCategoryResource::parentOptions('income');

        expect(array_keys($options))->toEqualCanonicalizing($income)
            ->and(TransactionCategoryResource::parentOptions(null))->toBe([]);
    });
});

describe('Partner access', function (): void {
    it('answers 403 to a Partner on the screen', function (): void {
        [$clientA] = Canary::twoClients();

        $this->actingAs(Canary::partnerFor($clientA))
            ->get('/admin/transaction-categories')
            ->assertForbidden();
    });
});
