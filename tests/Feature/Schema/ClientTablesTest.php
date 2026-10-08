<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RawSql;

/*
 * Database-level invariants of the client tables (Phase 4 D-01, D-03, D-04, D-09
 * and the CL-01 boundaries). Every statement is raw SQL so that no model, cast
 * or form rule can stand between the value and the constraint. Values are
 * fictional and assembled at runtime. Plan 04-02 adds a projects section.
 */

/**
 * Inserts one client row with valid defaults; overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertClientRow(array $overrides = []): string
{
    $row = [
        'id' => (string) Str::uuid7(),
        'name' => 'Example client '.Str::lower(Str::random(8)),
        'country' => 'CZ',
        'stage' => 'active',
        'currency' => 'CZK',
        'hourly_rate_minor' => 0,
        'hourly_rate_currency' => 'CZK',
        'payment_terms_days' => 14,
        'invoice_language' => 'cs',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];

    $columns = array_keys($row);
    $statement = sprintf(
        'INSERT INTO clients (%s) VALUES (%s)',
        implode(', ', $columns),
        implode(', ', array_fill(0, count($columns), '?')),
    );

    DB::insert($statement, array_values($row));

    return (string) $row['id'];
}

/**
 * A company number assembled at runtime from fragments, never a literal.
 */
function fictionalCompanyNumber(): string
{
    return implode('', [(string) random_int(1000, 9999), (string) random_int(1000, 9999)]);
}

/**
 * Inserts one user row; overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertUserRow(array $overrides = []): string
{
    $row = [
        'id' => (string) Str::uuid7(),
        'name' => 'Example user',
        'email' => exampleEmail(),
        'password' => 'not-a-real-hash',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];

    $columns = array_keys($row);
    DB::insert(
        sprintf('INSERT INTO users (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
        array_values($row),
    );

    return (string) $row['id'];
}

describe('clients', function (): void {
    it('stores payment terms of 0 and 365 days and refuses -1 and 366 (CL-01 boundary)', function (): void {
        RawSql::expectAllowed(fn () => insertClientRow(['payment_terms_days' => 0]));
        RawSql::expectAllowed(fn () => insertClientRow(['payment_terms_days' => 365]));
        RawSql::expectSqlState('23514', fn () => insertClientRow(['payment_terms_days' => -1]));
        RawSql::expectSqlState('23514', fn () => insertClientRow(['payment_terms_days' => 366]));
    });

    it('stores an hourly rate of 0 minor units and refuses a negative one', function (): void {
        RawSql::expectAllowed(fn () => insertClientRow(['hourly_rate_minor' => 0]));
        RawSql::expectAllowed(fn () => insertClientRow(['hourly_rate_minor' => 125000]));
        RawSql::expectSqlState('23514', fn () => insertClientRow(['hourly_rate_minor' => -1]));
    });

    it('refuses a rate currency that differs from the client currency', function (): void {
        RawSql::expectAllowed(fn () => insertClientRow(['currency' => 'EUR', 'hourly_rate_currency' => 'EUR']));
        RawSql::expectSqlState('23514', fn () => insertClientRow(['currency' => 'CZK', 'hourly_rate_currency' => 'EUR']));
    });

    it('refuses a malformed currency code', function (): void {
        RawSql::expectSqlState('23514', fn () => insertClientRow(['currency' => 'czk', 'hourly_rate_currency' => 'czk']));
        RawSql::expectSqlState('22001', fn () => insertClientRow(['currency' => 'CZKK', 'hourly_rate_currency' => 'CZKK']));
    });

    it('refuses a lower-case country and a three-letter country', function (): void {
        RawSql::expectAllowed(fn () => insertClientRow(['country' => 'SK']));
        RawSql::expectSqlState('23514', fn () => insertClientRow(['country' => 'cz']));
        RawSql::expectSqlState('22001', fn () => insertClientRow(['country' => 'CZE']));
    });

    it('refuses an unknown stage and an unknown invoice language', function (): void {
        foreach (['lead', 'active', 'paused', 'ended'] as $stage) {
            RawSql::expectAllowed(fn () => insertClientRow(['stage' => $stage]));
        }

        RawSql::expectSqlState('23514', fn () => insertClientRow(['stage' => 'archived']));
        RawSql::expectAllowed(fn () => insertClientRow(['invoice_language' => 'en']));
        RawSql::expectSqlState('23514', fn () => insertClientRow(['invoice_language' => 'de']));
    });

    it('requires a currency, a rate, payment terms, a language and a country (no database defaults)', function (): void {
        foreach (['currency', 'hourly_rate_minor', 'hourly_rate_currency', 'payment_terms_days', 'invoice_language', 'country', 'name'] as $column) {
            RawSql::expectSqlState('23502', fn () => insertClientRow([$column => null]));
        }
    });

    it('refuses two clients with the same company number in one country, also when the first is archived', function (): void {
        $number = fictionalCompanyNumber();

        RawSql::expectAllowed(fn () => insertClientRow(['country' => 'CZ', 'company_number' => $number]));
        RawSql::expectSqlState('23505', fn () => insertClientRow(['country' => 'CZ', 'company_number' => $number]));

        $archivedNumber = fictionalCompanyNumber();

        RawSql::expectAllowed(fn () => insertClientRow(['country' => 'CZ', 'company_number' => $archivedNumber, 'deleted_at' => now()]));
        RawSql::expectSqlState('23505', fn () => insertClientRow(['country' => 'CZ', 'company_number' => $archivedNumber]));
    });

    it('allows the same company number in another country and several clients without one', function (): void {
        $number = fictionalCompanyNumber();

        RawSql::expectAllowed(fn () => insertClientRow(['country' => 'CZ', 'company_number' => $number]));
        RawSql::expectAllowed(fn () => insertClientRow(['country' => 'SK', 'company_number' => $number]));

        RawSql::expectAllowed(fn () => insertClientRow(['company_number' => null]));
        RawSql::expectAllowed(fn () => insertClientRow(['company_number' => null]));
        RawSql::expectAllowed(fn () => insertClientRow(['company_number' => null, 'country' => 'SK']));
    });
});

describe('users', function (): void {
    it('refuses a user that points at an unknown client with SQLSTATE 23503', function (): void {
        RawSql::expectSqlState('23503', fn () => insertUserRow(['client_id' => (string) Str::uuid7()]));
        RawSql::expectAllowed(fn () => insertUserRow(['client_id' => insertClientRow()]));
        RawSql::expectAllowed(fn () => insertUserRow(['client_id' => null]));
    });

    it('refuses a hard delete of a client that a user references with SQLSTATE 23001', function (): void {
        $clientId = insertClientRow();
        insertUserRow(['client_id' => $clientId]);

        RawSql::expectSqlState('23001', fn () => DB::delete('DELETE FROM clients WHERE id = ?', [$clientId]));

        $unreferenced = insertClientRow();
        RawSql::expectAllowed(fn () => DB::delete('DELETE FROM clients WHERE id = ?', [$unreferenced]));
    });

    it('refuses two users whose e-mail addresses differ only in letter case', function (): void {
        $local = 'user'.Str::lower(Str::random(8));
        $address = $local.'@'.implode('.', ['example', 'com']);

        RawSql::expectAllowed(fn () => insertUserRow(['email' => $address]));
        RawSql::expectSqlState('23505', fn () => insertUserRow(['email' => Str::upper($local).'@'.implode('.', ['Example', 'COM'])]));
        RawSql::expectSqlState('23505', fn () => insertUserRow(['email' => $address]));
    });

    it('stores a nullable deactivation timestamp with a time zone', function (): void {
        $id = insertUserRow();

        expect(DB::scalar('SELECT deactivated_at FROM users WHERE id = ?', [$id]))->toBeNull();

        DB::update('UPDATE users SET deactivated_at = now() WHERE id = ?', [$id]);

        expect(DB::scalar('SELECT deactivated_at FROM users WHERE id = ?', [$id]))->not->toBeNull()
            ->and(DB::scalar("SELECT data_type FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'deactivated_at'"))
            ->toBe('timestamp with time zone');
    });
});
