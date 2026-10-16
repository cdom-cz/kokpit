<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\SaveTransactionCategory;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Filament\Resources\TransactionCategoryResource;
use App\Filament\Resources\TransactionCategoryResource\Pages\ListTransactionCategories;
use Filament\Actions\Testing\TestAction;
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

/**
 * Stores an invented root through the Action as the signed-in Admin.
 */
function screenRoot(string $type = 'expense'): TransactionCategory
{
    return app(SaveTransactionCategory::class)->handle(TransactionCategoryResource::actor(), null, [
        'name' => 'Kořen '.bin2hex(random_bytes(4)),
        'type' => $type,
    ]);
}

/**
 * Stores an invented child under the root through the Action as the signed-in Admin.
 */
function screenChild(TransactionCategory $root): TransactionCategory
{
    return app(SaveTransactionCategory::class)->handle(TransactionCategoryResource::actor(), null, [
        'name' => 'Dítě '.bin2hex(random_bytes(4)),
        'parent_id' => $root->id,
    ]);
}

describe('the system category on the screen', function (): void {
    it('shows the system badge and offers rename and move but neither archive nor delete', function (): void {
        $system = TransactionCategory::invoicePayments();

        Livewire::test(ListTransactionCategories::class)
            ->assertSee('Systémová')
            ->assertActionVisible(TestAction::make('edit')->table($system))
            ->assertActionVisible(TestAction::make('moveUp')->table($system))
            ->assertActionVisible(TestAction::make('moveDown')->table($system))
            ->assertActionHidden(TestAction::make('archive')->table($system))
            ->assertActionHidden(TestAction::make('delete')->table($system));
    });

    it('renames the system category in the edit modal and keeps its type and parent', function (): void {
        $system = TransactionCategory::invoicePayments();
        $name = 'Úhrady '.bin2hex(random_bytes(3));

        Livewire::test(ListTransactionCategories::class)
            ->callAction(TestAction::make('edit')->table($system), ['name' => $name])
            ->assertHasNoFormErrors();

        $system->refresh();

        expect($system->name)->toBe($name)
            ->and($system->type)->toBe(TransactionType::Income)
            ->and($system->is_system)->toBeTrue()
            ->and($system->archived_at)->toBeNull();
    });
});

describe('archive, restore and delete on the screen', function (): void {
    it('archives with a badge and restores, showing the right action each time', function (): void {
        $root = screenRoot();

        Livewire::test(ListTransactionCategories::class)
            ->assertActionVisible(TestAction::make('archive')->table($root))
            ->assertActionHidden(TestAction::make('restore')->table($root))
            ->callAction(TestAction::make('archive')->table($root))
            ->assertNotified(__('finance.categories.notifications.archived'))
            ->assertActionHidden(TestAction::make('archive')->table($root))
            ->assertActionVisible(TestAction::make('restore')->table($root))
            ->assertSee('Archivovaná');

        expect($root->refresh()->isArchived())->toBeTrue();

        Livewire::test(ListTransactionCategories::class)
            ->callAction(TestAction::make('restore')->table($root))
            ->assertNotified(__('finance.categories.notifications.restored'));

        expect($root->refresh()->isArchived())->toBeFalse();
    });

    it('asks for confirmation before it deletes an unused category', function (): void {
        $root = screenRoot();

        Livewire::test(ListTransactionCategories::class)
            ->mountAction(TestAction::make('delete')->table($root));

        expect(TransactionCategory::query()->whereKey($root->id)->exists())->toBeTrue();

        Livewire::test(ListTransactionCategories::class)
            ->callAction(TestAction::make('delete')->table($root))
            ->assertNotified(__('finance.categories.notifications.deleted'));

        expect(TransactionCategory::query()->whereKey($root->id)->exists())->toBeFalse();
    });

    it('refuses to delete a parent with a child and says why in a danger notification', function (): void {
        $root = screenRoot();
        screenChild($root);

        Livewire::test(ListTransactionCategories::class)
            ->callAction(TestAction::make('delete')->table($root))
            ->assertNotified(__('finance.categories.errors.in_use'));

        expect(TransactionCategory::query()->whereKey($root->id)->exists())->toBeTrue();
    });
});

describe('moving on the screen', function (): void {
    it('moves a child up and down among its siblings', function (): void {
        $root = screenRoot();
        $a = screenChild($root);
        $b = screenChild($root);

        Livewire::test(ListTransactionCategories::class)
            ->callAction(TestAction::make('moveUp')->table($b));

        expect($b->refresh()->position)->toBeLessThan($a->refresh()->position);

        Livewire::test(ListTransactionCategories::class)
            ->callAction(TestAction::make('moveDown')->table($b));

        expect($a->refresh()->position)->toBeLessThan($b->refresh()->position);
    });
});

describe('editing on the screen', function (): void {
    it('keeps the type of a root with children and offers no parent for it', function (): void {
        $root = screenRoot();
        screenChild($root);

        Livewire::test(ListTransactionCategories::class)
            ->mountAction(TestAction::make('edit')->table($root))
            ->assertSchemaComponentExists('type', 'mountedActionSchema0', static fn ($field): bool => $field->isDisabled())
            ->assertSchemaComponentExists('parent_id', 'mountedActionSchema0', static fn ($field): bool => $field->isDisabled());
    });

    it('shows a duplicate name as a field error next to the name', function (): void {
        $root = screenRoot();
        $other = screenRoot();

        Livewire::test(ListTransactionCategories::class)
            ->callAction(TestAction::make('edit')->table($other), ['name' => $root->name])
            ->assertHasFormErrors(['name']);

        expect($other->refresh()->name)->not->toBe($root->name);
    });
});
