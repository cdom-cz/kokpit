<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;
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
