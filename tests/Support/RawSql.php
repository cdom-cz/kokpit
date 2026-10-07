<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Assert;

/**
 * Raw-SQL assertions on SQLSTATE.
 *
 * Inside RefreshDatabase one rejected statement would abort the surrounding
 * transaction and every later statement would fail. Each statement therefore
 * runs in a nested DB::transaction(), which Laravel issues as a SAVEPOINT, and
 * a rejected statement only rolls the savepoint back.
 */
final class RawSql
{
    /**
     * Fails unless the statement is rejected with exactly this SQLSTATE.
     */
    public static function expectSqlState(string $state, Closure $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            Assert::assertSame(
                $state,
                self::sqlState($e),
                sprintf('Expected SQLSTATE %s but the database answered %s: %s', $state, self::sqlState($e), $e->getMessage()),
            );

            return;
        }

        Assert::fail(sprintf('Expected SQLSTATE %s but the statement was accepted.', $state));
    }

    /**
     * Fails if the statement is rejected.
     */
    public static function expectAllowed(Closure $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            Assert::fail(sprintf('Expected the statement to be allowed but the database answered %s: %s', self::sqlState($e), $e->getMessage()));
        }

        Assert::assertTrue(true);
    }

    private static function sqlState(QueryException $e): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof PDOException && is_array($previous->errorInfo) && isset($previous->errorInfo[0])) {
            return (string) $previous->errorInfo[0];
        }

        return (string) $e->getCode();
    }
}
