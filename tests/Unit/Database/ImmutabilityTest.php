<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;

dataset('hostile identifiers', [
    'injection' => ['a;drop'],
    'upper case' => ['A'],
    'leading digit' => ['1a'],
    'empty' => [''],
    'quote' => ["a'b"],
    'space' => ['a b'],
    'trailing newline' => ["a\n"],
    'dash' => ['a-b'],
]);

it('rejects a hostile table name in every builder', function (string $table) {
    expect(fn () => Immutability::guardTriggerSql($table, 'status', 'draft', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Immutability::dropGuardTriggerSql($table))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Immutability::truncateGuardSql($table))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Immutability::dropTruncateGuardSql($table))->toThrow(InvalidArgumentException::class);
})->with('hostile identifiers');

it('rejects a hostile state column', function (string $column) {
    expect(fn () => Immutability::guardTriggerSql('invoices', $column, 'draft', []))->toThrow(InvalidArgumentException::class);
})->with('hostile identifiers');

it('rejects a hostile entry in the operational column list', function (string $column) {
    expect(fn () => Immutability::guardTriggerSql('invoices', 'status', 'draft', ['note', $column]))->toThrow(InvalidArgumentException::class);
})->with('hostile identifiers');

it('rejects an open state outside the allowed pattern', function (string $state) {
    expect(fn () => Immutability::guardTriggerSql('invoices', 'status', $state, []))->toThrow(InvalidArgumentException::class);
})->with([
    'quote' => ["draft'"],
    'upper case' => ['Draft'],
    'digit' => ['d1'],
    'empty' => [''],
    'injection' => ["x'); drop table a; --"],
]);

it('rejects a table name that would overflow the trigger name', function () {
    expect(fn () => Immutability::truncateGuardSql(str_repeat('a', 49)))->toThrow(InvalidArgumentException::class);
    expect(Immutability::truncateGuardSql(str_repeat('a', 48)))->toContain('CREATE TRIGGER');
});

it('names the trigger after the table and always guards updated_at', function () {
    $sql = Immutability::guardTriggerSql('invoices', 'status', 'draft', ['note']);

    expect($sql)->toContain('CREATE TRIGGER invoices_frozen_guard BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW')
        ->and($sql)->toContain("kokpit_guard_frozen_row('status', 'draft', 'note,updated_at')");
});

it('does not repeat updated_at when the caller lists it too', function () {
    $sql = Immutability::guardTriggerSql('invoices', 'status', 'draft', ['updated_at', 'note']);

    expect($sql)->toContain("'updated_at,note'");
});

it('builds the statement-level truncate guard and the drop statements', function () {
    expect(Immutability::truncateGuardSql('invoices'))
        ->toBe('CREATE TRIGGER invoices_truncate_guard BEFORE TRUNCATE ON invoices FOR EACH STATEMENT EXECUTE FUNCTION kokpit_refuse_truncate()')
        ->and(Immutability::dropGuardTriggerSql('invoices'))->toBe('DROP TRIGGER IF EXISTS invoices_frozen_guard ON invoices')
        ->and(Immutability::dropTruncateGuardSql('invoices'))->toBe('DROP TRIGGER IF EXISTS invoices_truncate_guard ON invoices');
});
