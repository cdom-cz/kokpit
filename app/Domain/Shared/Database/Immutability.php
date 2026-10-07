<?php

declare(strict_types=1);

namespace App\Domain\Shared\Database;

use InvalidArgumentException;

/**
 * Emits the DDL that attaches the generic immutability guards to a table.
 *
 * A later migration (invoices, billed time entries) calls these builders and
 * hands the result to DB::unprepared(); the guard functions themselves are
 * installed by the kokpit_guard_frozen_row migration. Every identifier that
 * becomes part of SQL text is validated first, so no input can inject SQL.
 *
 * Limits of a database trigger, stated so nobody assumes more than it gives:
 * - row triggers do not fire on TRUNCATE; truncateGuardSql() adds a
 *   statement-level trigger for tables where that matters;
 * - the table owner can ALTER TABLE ... DISABLE TRIGGER and a session with
 *   session_replication_role = replica skips triggers, so the production
 *   application role must not be a superuser (assumption A4, Phase 3);
 * - the operational column list is compared by name: a column renamed in a
 *   guarded table becomes frozen, so such a migration must re-create the
 *   trigger.
 */
final class Immutability
{
    /** Identifier pattern; D keeps a trailing newline from passing. */
    private const string IDENTIFIER_PATTERN = '/^[a-z_][a-z0-9_]*$/D';

    private const string STATE_PATTERN = '/^[a-z_]+$/D';

    /** Longest table name that keeps "<table>_truncate_guard" within the 63 byte identifier limit. */
    private const int TABLE_MAX_LENGTH = 48;

    /**
     * @param  list<string>  $mutableColumns  operational columns that may change after the row is frozen; updated_at is always included
     */
    public static function guardTriggerSql(string $table, string $stateColumn, string $openState, array $mutableColumns): string
    {
        self::assertTable($table);
        self::assertIdentifier($stateColumn, 'state column');

        if (preg_match(self::STATE_PATTERN, $openState) !== 1) {
            throw new InvalidArgumentException('Open state must match ^[a-z_]+$.');
        }

        foreach ($mutableColumns as $column) {
            self::assertIdentifier($column, 'mutable column');
        }

        $columns = array_values(array_unique([...$mutableColumns, 'updated_at']));

        return sprintf(
            "CREATE TRIGGER %s_frozen_guard BEFORE UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION kokpit_guard_frozen_row('%s', '%s', '%s')",
            $table,
            $table,
            $stateColumn,
            $openState,
            implode(',', $columns),
        );
    }

    public static function dropGuardTriggerSql(string $table): string
    {
        self::assertTable($table);

        return sprintf('DROP TRIGGER IF EXISTS %s_frozen_guard ON %s', $table, $table);
    }

    public static function truncateGuardSql(string $table): string
    {
        self::assertTable($table);

        return sprintf(
            'CREATE TRIGGER %s_truncate_guard BEFORE TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION kokpit_refuse_truncate()',
            $table,
            $table,
        );
    }

    public static function dropTruncateGuardSql(string $table): string
    {
        self::assertTable($table);

        return sprintf('DROP TRIGGER IF EXISTS %s_truncate_guard ON %s', $table, $table);
    }

    private static function assertTable(string $table): void
    {
        self::assertIdentifier($table, 'table');

        if (strlen($table) > self::TABLE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Table name is longer than %d characters.', self::TABLE_MAX_LENGTH));
        }
    }

    private static function assertIdentifier(string $value, string $what): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s: must match ^[a-z_][a-z0-9_]*$.', $what));
        }
    }
}
