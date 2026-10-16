<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use Illuminate\Database\QueryException;
use PDOException;

/**
 * The SQLSTATE of a rejected statement, as the finance Actions translate database refusals into Czech messages.
 *
 * `23503` is a foreign key refusal (children, later transactions) and `KP001` is the frozen system row guard.
 */
final class SqlState
{
    public const string FOREIGN_KEY = '23503';

    public const string FROZEN_ROW = 'KP001';

    public static function of(QueryException $e): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof PDOException && isset($previous->errorInfo[0])) {
            return (string) $previous->errorInfo[0];
        }

        return (string) $e->getCode();
    }
}
