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
 * childGuardTriggerSql() attaches the companion guard to the rows of a child table (invoice items): they follow
 * the state of their parent row and are frozen with it, INSERT included.
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
     * @param  list<string>  $mutableColumns  operational columns that may change after the row is frozen
     * @param  bool  $withUpdatedAt  true (the default) adds updated_at to the list; false means the list is exact, so an empty list freezes every column and a write that only touches updated_at is refused too
     */
    public static function guardTriggerSql(string $table, string $stateColumn, string $openState, array $mutableColumns, bool $withUpdatedAt = true): string
    {
        self::assertTable($table);
        self::assertIdentifier($stateColumn, 'state column');
        self::assertState($openState);

        foreach ($mutableColumns as $column) {
            self::assertIdentifier($column, 'mutable column');
        }

        $columns = $withUpdatedAt
            ? array_values(array_unique([...$mutableColumns, 'updated_at']))
            : $mutableColumns;

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

    /**
     * Guards the rows of a child table whose parent row carries the lifecycle: while the parent is in any state but
     * the open one, an INSERT, UPDATE or DELETE of a child row is refused with KP001. The list of always-mutable
     * columns is exact: updated_at is never added, so a frozen child refuses even a timestamp-only write.
     *
     * @param  list<string>  $mutableColumns  columns of the child that may still change under a frozen parent
     */
    public static function childGuardTriggerSql(
        string $table,
        string $fkColumn,
        string $parentTable,
        string $parentStateColumn,
        string $openState,
        array $mutableColumns = [],
    ): string {
        self::assertTable($table);
        self::assertIdentifier($fkColumn, 'foreign key column');
        self::assertTable($parentTable);
        self::assertIdentifier($parentStateColumn, 'parent state column');
        self::assertState($openState);

        foreach ($mutableColumns as $column) {
            self::assertIdentifier($column, 'mutable column');
        }

        return sprintf(
            "CREATE TRIGGER %s_parent_guard BEFORE INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION kokpit_guard_child_of_frozen_parent('%s', '%s', '%s', '%s', '%s')",
            $table,
            $table,
            $parentTable,
            $fkColumn,
            $parentStateColumn,
            $openState,
            implode(',', $mutableColumns),
        );
    }

    public static function dropChildGuardTriggerSql(string $table): string
    {
        self::assertTable($table);

        return sprintf('DROP TRIGGER IF EXISTS %s_parent_guard ON %s', $table, $table);
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

    private static function assertState(string $openState): void
    {
        if (preg_match(self::STATE_PATTERN, $openState) !== 1) {
            throw new InvalidArgumentException('Open state must match ^[a-z_]+$.');
        }
    }

    private static function assertIdentifier(string $value, string $what): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s: must match ^[a-z_][a-z0-9_]*$.', $what));
        }
    }
}
