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
