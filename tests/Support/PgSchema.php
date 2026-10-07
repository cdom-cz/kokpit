<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the PostgreSQL catalogue and applies the schema convention rules.
 *
 * Every rule is a pure function over catalogue rows, so a self-check can feed
 * it a synthetic violating row and prove the rule is able to fail. The rows
 * come from pg_catalog, which means a table added by any later migration is
 * checked without anyone listing it.
 *
 * @phpstan-type Column object{tbl: string, col: string, udt: string, typ: string, auto_inc: bool, is_fk: bool, is_single_pk: bool, default_expr: string|null}
 */
final class PgSchema
{
    /**
     * Columns allowed to break rule R1 and R2, each with the reason.
     * Keys are `table.column`. Every entry must still exist (rule R5).
     *
     * @var array<string, string>
     */
    public const array EXEMPT = [
        'migrations.id' => 'Laravel migrator table, created by the framework with an integer key',
        'failed_jobs.id' => 'framework failed-job store keys rows by its uuid column',
        'sessions.id' => 'opaque session string; sessions.user_id is still checked',
        'password_reset_tokens.email' => 'framework table keyed by e-mail',
    ];

    /**
     * One row per column of every table in the current schema.
     *
     * @return Collection<int, Column>
     */
    public static function columns(): Collection
    {
        /** @var list<object> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT c.relname AS tbl,
                   a.attname AS col,
                   a.atttypid::regtype::text AS udt,
                   format_type(a.atttypid, a.atttypmod) AS typ,
                   (a.attidentity <> '' OR coalesce(pg_get_expr(d.adbin, d.adrelid) LIKE 'nextval(%', false)) AS auto_inc,
                   EXISTS (SELECT 1 FROM pg_constraint k
                           WHERE k.conrelid = c.oid AND k.contype = 'f' AND a.attnum = ANY (k.conkey)) AS is_fk,
                   EXISTS (SELECT 1 FROM pg_constraint k
                           WHERE k.conrelid = c.oid AND k.contype = 'p'
                             AND array_length(k.conkey, 1) = 1 AND a.attnum = ANY (k.conkey)) AS is_single_pk,
                   pg_get_expr(d.adbin, d.adrelid) AS default_expr
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema()
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            WHERE a.attnum > 0 AND NOT a.attisdropped
            ORDER BY c.relname, a.attnum
        SQL);

        /** @var Collection<int, Column> */
        return collect($rows);
    }

    /**
     * R1: id, *_id, foreign key and single-column primary key columns are uuid.
     *
     * @param  Collection<int, Column>  $columns
     * @param  array<string, string>  $exempt
     * @return list<string>
     */
    public static function nonUuidKeys(Collection $columns, array $exempt = self::EXEMPT): array
    {
        return self::names($columns->filter(
            fn (object $c): bool => ($c->col === 'id' || str_ends_with($c->col, '_id') || $c->is_fk || $c->is_single_pk)
                && ! isset($exempt["{$c->tbl}.{$c->col}"])
                && $c->udt !== 'uuid',
        ));
    }

    /**
     * R2: no identity column and no nextval() default.
     *
     * @param  Collection<int, Column>  $columns
     * @param  array<string, string>  $exempt
     * @return list<string>
     */
    public static function autoIncrementing(Collection $columns, array $exempt = self::EXEMPT): array
    {
        return self::names($columns->filter(
            fn (object $c): bool => $c->auto_inc && ! isset($exempt["{$c->tbl}.{$c->col}"]),
        ));
    }

    /**
     * R4: no timestamp without time zone anywhere.
     *
     * @param  Collection<int, Column>  $columns
     * @return list<string>
     */
    public static function timestampsWithoutZone(Collection $columns): array
    {
        return self::names($columns->filter(
            fn (object $c): bool => str_starts_with($c->typ, 'timestamp') && str_contains($c->typ, 'without time zone'),
        ));
    }

    /**
     * R5: exempt entries that no longer name an existing column.
     *
     * @param  Collection<int, Column>  $columns
     * @param  array<string, string>  $exempt
     * @return list<string>
     */
    public static function staleExemptions(Collection $columns, array $exempt = self::EXEMPT): array
    {
        $existing = $columns->map(fn (object $c): string => "{$c->tbl}.{$c->col}")->all();
        $stale = array_values(array_diff(array_keys($exempt), $existing));
        sort($stale);

        return $stale;
    }

    /**
     * R6: every single-column uuid primary key defaults to uuidv7().
     *
     * @param  Collection<int, Column>  $columns
     * @return list<string>
     */
    public static function missingUuidv7Default(Collection $columns): array
    {
        return self::names($columns->filter(
            fn (object $c): bool => $c->is_single_pk && $c->udt === 'uuid' && $c->default_expr !== 'uuidv7()',
        ));
    }

    /**
     * @param  Collection<int, object>  $columns
     * @return list<string>
     */
    private static function names(Collection $columns): array
    {
        $names = $columns->map(fn (object $c): string => "{$c->tbl}.{$c->col}")->unique()->values()->all();
        sort($names);

        return $names;
    }
}
