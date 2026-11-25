<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Exceptions;

use RuntimeException;

/**
 * A database write of SaveArticle failed. The message is fixed and carries the SQLSTATE only.
 *
 * The original exception is deliberately not accepted and not chained: Laravel's query exception substitutes the
 * bound values (here the title and the body of the article) into its own message, a PostgreSQL check violation adds
 * a DETAIL line listing the failing row, and the log line formatter appends the message of every previous exception
 * to the logged line. Chaining the original would therefore write the article content into the log. The SQLSTATE
 * (five characters, no content) is the diagnostic that remains.
 */
final class ArticleWriteFailed extends RuntimeException
{
    public function __construct(public readonly string $sqlState)
    {
        parent::__construct('The knowledge article could not be written (SQLSTATE '.$sqlState.').');
    }
}
