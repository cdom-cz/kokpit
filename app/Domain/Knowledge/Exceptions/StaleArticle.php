<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Exceptions;

use RuntimeException;

/**
 * The article moved to a newer version since the editor was opened (D-09, KB-08), or the current version was redacted
 * since then (D-13, `redactionChanged`). Nothing was written; the screen keeps the typed text and offers the current
 * article. The message is fixed and names no content.
 */
final class StaleArticle extends RuntimeException
{
    public function __construct(
        public readonly int $currentVersion,
        public readonly bool $redactionChanged = false,
    ) {
        parent::__construct('The article moved to a newer version since the editor was opened.');
    }
}
