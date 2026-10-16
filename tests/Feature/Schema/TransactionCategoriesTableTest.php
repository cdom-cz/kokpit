<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\RawSql;
use Tests\Support\Uuids;

/*
 * The storage end of the finance categories (FI-01, D-15, D-16): the seeded system income category that
 * Phase 10 books invoice payments into, readable by the Admin only. The database rules (two levels, the
 * inherited type, unique names, the system freeze) follow in the later describe blocks. Names are invented
 * or generic starter names; no real business data.
 */

describe('the seeded system category', function (): void {
    it('gives the Admin exactly one system income root with the invoice_payments key', function (): void {
        $this->actingAs(Canary::admin());

        $matches = TransactionCategory::query()->where('system_key', TransactionCategory::SYSTEM_INVOICE_PAYMENTS)->get();
        $category = TransactionCategory::invoicePayments();

        expect($matches)->toHaveCount(1)
            ->and($category->type)->toBe(TransactionType::Income)
            ->and($category->parent_id)->toBeNull()
            ->and($category->depth)->toBe(0)
            ->and($category->is_system)->toBeTrue()
            ->and($category->archived_at)->toBeNull();
    });

    it('defaults the primary key of a row written by raw SQL to a version 7 uuid', function (): void {
        $row = DB::selectOne(
            "insert into transaction_categories (type, depth, name, position, created_at, updated_at) values ('expense', 0, ?, 1, now(), now()) returning id",
            ['Fictional '.bin2hex(random_bytes(4))],
        );

        expect($row->id)->toMatch(Uuids::V7_PATTERN);
    });
});

describe('Partner visibility', function (): void {
    it('shows a Partner with a client no category row', function (): void {
        [$clientA] = Canary::twoClients();

        $this->actingAs(Canary::partnerFor($clientA));

        expect(TransactionCategory::query()->count())->toBe(0);
    });

    it('grants a Partner no ability on TransactionCategory', function (): void {
        [$clientA] = Canary::twoClients();

        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);
        $row = app(PartnerContext::class)->runAsSystem(static fn (): TransactionCategory => TransactionCategory::invoicePayments());

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, TransactionCategory::class))->toBeFalse($ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, $row))->toBeFalse($ability);
        }
    });

    it('grants the Admin the abilities on TransactionCategory', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);
        $row = TransactionCategory::invoicePayments();

        expect(Gate::forUser($admin)->allows('viewAny', TransactionCategory::class))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('view', $row))->toBeTrue();
    });
});

describe('the type enum', function (): void {
    it('labels the two types in Czech', function (): void {
        App::setLocale('cs');

        expect(TransactionType::Income->getLabel())->toBe('Příjem')
            ->and(TransactionType::Expense->getLabel())->toBe('Výdaj')
            ->and(TransactionType::Income->value)->toBe('income')
            ->and(TransactionType::Expense->value)->toBe('expense');
    });
});

/**
 * Inserts one category row with valid defaults (a root of type expense) and returns its id. Overrides replace
 * single columns; the column names come from the test only. The depth follows the parent unless overridden, and
 * the names are assembled at runtime so no test line looks like real data.
 *
 * @param  array<string, mixed>  $overrides
 */
function transactionCategoriesInsert(array $overrides = []): string
{
    $parentId = $overrides['parent_id'] ?? null;

    $row = [
        'id' => (string) Str::uuid7(),
        'parent_id' => $parentId,
        'type' => 'expense',
        'depth' => $parentId === null ? 0 : 1,
        'name' => 'Fictional '.bin2hex(random_bytes(4)),
        'position' => 1,
        'created_at' => '2026-10-16 10:00:00+00',
        'updated_at' => '2026-10-16 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into transaction_categories (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

describe('the two-level tree and the inherited type', function (): void {
    it('refuses a type other than income or expense, and a depth that disagrees with the parent', function (): void {
        $root = transactionCategoriesInsert();

        RawSql::expectSqlState('23514', fn () => transactionCategoriesInsert(['type' => 'other']));
        RawSql::expectSqlState('23514', fn () => transactionCategoriesInsert(['depth' => 1]));
        RawSql::expectSqlState('23514', fn () => transactionCategoriesInsert(['parent_id' => $root, 'depth' => 0]));
        RawSql::expectAllowed(fn () => transactionCategoriesInsert(['parent_id' => $root]));
    });

    it('refuses a child of the other type than its parent', function (): void {
        $root = transactionCategoriesInsert(['type' => 'expense']);

        RawSql::expectSqlState('23503', fn () => transactionCategoriesInsert(['parent_id' => $root, 'type' => 'income']));
        RawSql::expectAllowed(fn () => transactionCategoriesInsert(['parent_id' => $root, 'type' => 'expense']));
    });

    it('refuses a child of a child, so there is no third level', function (): void {
        $root = transactionCategoriesInsert();
        $child = transactionCategoriesInsert(['parent_id' => $root]);

        RawSql::expectSqlState('23503', fn () => transactionCategoriesInsert(['parent_id' => $child]));
    });

    it('refuses to move a category under a parent of the other type', function (): void {
        $income = transactionCategoriesInsert(['type' => 'income']);
        $expense = transactionCategoriesInsert(['type' => 'expense']);
        $child = transactionCategoriesInsert(['parent_id' => $expense]);

        RawSql::expectSqlState('23503', fn () => DB::update('update transaction_categories set parent_id = ? where id = ?', [$income, $child]));
    });

    it('refuses to change the type of a parent that has children', function (): void {
        $root = transactionCategoriesInsert(['type' => 'expense']);
        transactionCategoriesInsert(['parent_id' => $root]);
        $childless = transactionCategoriesInsert(['type' => 'expense']);

        RawSql::expectSqlState('23503', fn () => DB::update("update transaction_categories set type = 'income' where id = ?", [$root]));
        RawSql::expectAllowed(fn () => DB::update("update transaction_categories set type = 'income' where id = ?", [$childless]));
    });
});

describe('unique and non-blank names', function (): void {
    it('keeps a root name unique per type, ignoring case', function (): void {
        $name = 'Fictional '.bin2hex(random_bytes(4));
        transactionCategoriesInsert(['name' => ucfirst($name)]);

        RawSql::expectSqlState('23505', fn () => transactionCategoriesInsert(['name' => ucfirst($name)]));
        RawSql::expectSqlState('23505', fn () => transactionCategoriesInsert(['name' => strtolower($name)]));
        RawSql::expectAllowed(fn () => transactionCategoriesInsert(['name' => ucfirst($name), 'type' => 'income']));
    });

    it('keeps a child name unique under its parent, ignoring case, but not across parents', function (): void {
        $name = 'Fictional '.bin2hex(random_bytes(4));
        $rootOne = transactionCategoriesInsert();
        $rootTwo = transactionCategoriesInsert();
        transactionCategoriesInsert(['parent_id' => $rootOne, 'name' => ucfirst($name)]);

        RawSql::expectSqlState('23505', fn () => transactionCategoriesInsert(['parent_id' => $rootOne, 'name' => strtolower($name)]));
        RawSql::expectAllowed(fn () => transactionCategoriesInsert(['parent_id' => $rootTwo, 'name' => ucfirst($name)]));
    });

    it('refuses a blank or whitespace name', function (): void {
        RawSql::expectSqlState('23514', fn () => transactionCategoriesInsert(['name' => '']));
        RawSql::expectSqlState('23514', fn () => transactionCategoriesInsert(['name' => '   ']));
    });
});

describe('the system columns', function (): void {
    it('refuses a system key on a row that is not a system row', function (): void {
        RawSql::expectSqlState('23514', fn () => transactionCategoriesInsert(['system_key' => 'fictional_key']));
    });

    it('refuses a system row with a parent', function (): void {
        $root = transactionCategoriesInsert();

        RawSql::expectSqlState('23514', fn () => transactionCategoriesInsert(['parent_id' => $root, 'is_system' => true]));
    });
});

describe('deleting', function (): void {
    it('refuses to delete a parent that still has a child, and deletes a childless root', function (): void {
        $root = transactionCategoriesInsert();
        $child = transactionCategoriesInsert(['parent_id' => $root]);
        $childless = transactionCategoriesInsert();

        RawSql::expectSqlState('23503', fn () => DB::delete('delete from transaction_categories where id = ?', [$root]));
        RawSql::expectAllowed(fn () => DB::delete('delete from transaction_categories where id = ?', [$childless]));
        RawSql::expectAllowed(fn () => DB::delete('delete from transaction_categories where id = ?', [$child]));
        RawSql::expectAllowed(fn () => DB::delete('delete from transaction_categories where id = ?', [$root]));
    });
});

describe('the system category freeze (KP001)', function (): void {
    it('lets the Admin rename the system category and move it in the order', function (): void {
        $this->actingAs(Canary::admin());
        $id = TransactionCategory::invoicePayments()->id;

        RawSql::expectAllowed(fn () => DB::update("update transaction_categories set name = 'Fictional payments', position = 7 where id = ?", [$id]));

        $row = DB::selectOne('select name, position, is_system, system_key from transaction_categories where id = ?', [$id]);

        expect($row->name)->toBe('Fictional payments')
            ->and($row->position)->toBe(7)
            ->and($row->is_system)->toBeTrue()
            ->and($row->system_key)->toBe('invoice_payments');
    });

    it('refuses every other change to the system row', function (string $change): void {
        $id = DB::selectOne("select id from transaction_categories where system_key = 'invoice_payments'")->id;
        $other = transactionCategoriesInsert(['type' => 'income']);

        $set = match ($change) {
            'archive' => ['archived_at = now()', []],
            'type' => ["type = 'expense'", []],
            'parent' => ['parent_id = ?', [$other]],
            'unflag' => ['is_system = false', []],
            'key' => ['system_key = null', []],
            'depth' => ['depth = 1', []],
        };

        RawSql::expectSqlState('KP001', fn () => DB::update('update transaction_categories set '.$set[0].' where id = ?', [...$set[1], $id]));
    })->with(['archive', 'type', 'parent', 'unflag', 'key', 'depth']);

    it('refuses to delete the system row', function (): void {
        $id = DB::selectOne("select id from transaction_categories where system_key = 'invoice_payments'")->id;

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from transaction_categories where id = ?', [$id]));
    });

    it('refuses to delete the system row through the model', function (): void {
        $this->actingAs(Canary::admin());
        $category = TransactionCategory::invoicePayments();

        RawSql::expectSqlState('KP001', fn () => $category->delete());

        expect(TransactionCategory::query()->where('system_key', 'invoice_payments')->count())->toBe(1);
    });

    it('lets every other category be archived, renamed and deleted when it is unused and childless', function (): void {
        $id = transactionCategoriesInsert();

        RawSql::expectAllowed(fn () => DB::update('update transaction_categories set archived_at = now() where id = ?', [$id]));
        RawSql::expectAllowed(fn () => DB::update("update transaction_categories set name = 'Fictional renamed' where id = ?", [$id]));
        RawSql::expectAllowed(fn () => DB::delete('delete from transaction_categories where id = ?', [$id]));
    });
});

describe('the starter set', function (): void {
    /**
     * The seeded tree as `type/root` or `type/root/child` => position, read through the Admin.
     *
     * @return array<string, int>
     */
    function transactionCategoriesTree(): array
    {
        $rows = TransactionCategory::query()->with('parent')->get();
        $tree = [];

        foreach ($rows as $row) {
            $path = $row->type->value.'/'.($row->parent !== null ? $row->parent->name.'/' : '').$row->name;
            $tree[$path] = $row->position;
        }

        ksort($tree);

        return $tree;
    }

    it('seeds the generic Czech categories with the right types, parents and positions', function (): void {
        $this->actingAs(Canary::admin());

        $expected = [
            'income/Úhrady faktur' => 1,
            'income/Ostatní příjmy' => 2,
            'expense/Software' => 1,
            'expense/Software/Předplatné' => 1,
            'expense/Software/Hosting' => 2,
            'expense/Poplatky' => 2,
            'expense/Poplatky/Bankovní poplatky' => 1,
            'expense/Daně a odvody' => 3,
            'expense/Daně a odvody/Daň z příjmů' => 1,
            'expense/Daně a odvody/Pojištění' => 2,
            'expense/Kancelář' => 4,
            'expense/Ostatní výdaje' => 5,
        ];
        ksort($expected);

        expect(transactionCategoriesTree())->toBe($expected);
    });

    it('adds nothing when the seed migration runs a second time and keeps a renamed system category', function (): void {
        $this->actingAs(Canary::admin());

        DB::update("update transaction_categories set name = 'Fictional payments' where system_key = 'invoice_payments'");
        $before = transactionCategoriesTree();

        $migration = require database_path('migrations/2026_10_16_000300_seed_transaction_categories.php');
        $migration->up();

        expect(transactionCategoriesTree())->toBe($before)
            ->and(TransactionCategory::query()->where('system_key', 'invoice_payments')->sole()->name)->toBe('Fictional payments');
    });
});

describe('selectable categories', function (): void {
    it('offers active categories of the type, without an archived one or a child of an archived parent', function (): void {
        $this->actingAs(Canary::admin());

        $active = transactionCategoriesInsert();
        $archived = transactionCategoriesInsert(['archived_at' => '2026-10-15 10:00:00+00']);
        $activeChild = transactionCategoriesInsert(['parent_id' => $active]);
        $archivedChild = transactionCategoriesInsert(['parent_id' => $active, 'archived_at' => '2026-10-15 10:00:00+00']);
        $orphanedChild = transactionCategoriesInsert(['parent_id' => $archived]);
        $income = transactionCategoriesInsert(['type' => 'income']);

        $offered = TransactionCategory::query()->selectable(TransactionType::Expense)->pluck('id')->all();

        expect($offered)->toContain($active, $activeChild)
            ->not->toContain($archived, $archivedChild, $orphanedChild, $income);

        $types = TransactionCategory::query()->selectable(TransactionType::Expense)->get()->map(fn (TransactionCategory $c) => $c->type->value)->unique()->all();
        expect($types)->toBe(['expense']);
    });

    it('tells whether a category is archived', function (): void {
        $this->actingAs(Canary::admin());

        $active = TransactionCategory::query()->findOrFail(transactionCategoriesInsert());
        $archived = TransactionCategory::query()->findOrFail(transactionCategoriesInsert(['archived_at' => '2026-10-15 10:00:00+00']));

        expect($active->isArchived())->toBeFalse()
            ->and($archived->isArchived())->toBeTrue();
    });
});
