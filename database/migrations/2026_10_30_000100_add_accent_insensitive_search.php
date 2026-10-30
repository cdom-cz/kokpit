<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Search that ignores diacritics and letter case, backed by GIN trigram indexes (CONTRIBUTING "Search").
 *
 * The wrapper. kokpit_search_text(text) is lower(public.unaccent('public.unaccent'::regdictionary, $1)). The
 * one-argument unaccent() is only STABLE, because it finds its dictionary through the search_path, and an index
 * expression must be IMMUTABLE. The two-argument form with a schema-qualified function and a schema-qualified
 * dictionary depends on the input alone, so declaring the wrapper IMMUTABLE is honest. The body is SQL-standard
 * (RETURN ...), which PostgreSQL parses and resolves when the function is created: a later search_path change cannot
 * redirect it to another unaccent, and a dump and restore with an empty search_path still works.
 *
 * PostgreSQL never inlines an IMMUTABLE SQL function whose body is not itself immutable. The call therefore stays
 * exactly as written in the query, and the planner matches it against the index expression. The application builds
 * its predicate with the same call (App\Domain\Shared\Database\AccentInsensitiveSearch), and a test proves the match
 * with EXPLAIN.
 *
 * lower() runs after unaccent(): the Czech letters are ASCII by then, so lowering works in any database locale.
 *
 * The indexes. A search ORs all the searchable columns of a table, so every searched column has its own GIN index
 * {table}_{column}_search_index over kokpit_search_text(column) with gin_trgm_ops. Without one the search is still
 * correct and falls back to a sequential scan. A newly searched column gets its index in a new migration.
 *
 * No CONCURRENTLY: Laravel runs a PostgreSQL migration in a transaction, where CREATE INDEX CONCURRENTLY is not
 * allowed. The tables of a single-company instance are small.
 *
 * The extensions. pg_trgm and unaccent are trusted contrib extensions (PostgreSQL 13 and later), so a role with
 * CREATE on the database can install them. Both ship with the official images and with DDEV. An operator may also
 * create them first as a superuser, in which case IF NOT EXISTS skips them. They are installed WITH SCHEMA public,
 * the schema of the pgsql search_path, and the wrapper refers to public.unaccent explicitly.
 *
 * down() drops the indexes and the function and keeps both extensions. CREATE EXTENSION IF NOT EXISTS cannot tell
 * whether this migration or an operator installed them, and dropping an extension this migration did not create
 * could destroy objects that are not ours. A leftover extension changes nothing.
 */
return new class extends Migration
{
    /**
     * Table => the searched columns, each with one index. Identifiers come only from this constant.
     *
     * @var array<string, list<string>>
     */
    private const array SEARCH_COLUMNS = [
        'clients' => ['name', 'company_number'],
        'contacts' => ['name'],
        'projects' => ['key', 'name'],
        'tasks' => ['reference', 'title'],
        'transactions' => ['counterparty'],
        'documents' => ['name', 'description'],
        'invoices' => ['number', 'variable_symbol'],
    ];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_search_text(text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            RETURN lower(public.unaccent('public.unaccent'::regdictionary, $1))
            SQL);

        foreach (self::SEARCH_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'CREATE INDEX %s ON %s USING gin (kokpit_search_text(%s) gin_trgm_ops)',
                    self::indexName($table, $column),
                    $table,
                    $column,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (self::SEARCH_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement('DROP INDEX IF EXISTS '.self::indexName($table, $column));
            }
        }

        DB::statement('DROP FUNCTION IF EXISTS kokpit_search_text(text)');
    }

    private static function indexName(string $table, string $column): string
    {
        return $table.'_'.$column.'_search_index';
    }
};
