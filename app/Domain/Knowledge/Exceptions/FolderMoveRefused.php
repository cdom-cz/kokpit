<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Exceptions;

use App\Domain\Knowledge\Models\KnowledgeFolder;
use InvalidArgumentException;
use RuntimeException;

/**
 * A folder cannot move to the chosen parent (D-03, ORG-01). Nothing was written.
 *
 * The reason is `cycle` (the parent is the folder itself or lies in its own subtree) or `depth` (the tree would
 * get deeper than the cap, the folder counted with its subfolders). `maxDepth` is always the one constant the
 * database trigger is built with, so the explanation shown to the Admin names the cap the database enforces. The
 * message is fixed and names no folder.
 */
final class FolderMoveRefused extends RuntimeException
{
    public const string CYCLE = 'cycle';

    public const string DEPTH = 'depth';

    public readonly int $maxDepth;

    /**
     * @param  string  $reason  CYCLE or DEPTH
     *
     * @throws InvalidArgumentException a reason other than CYCLE or DEPTH
     */
    public function __construct(public readonly string $reason)
    {
        if ($reason !== self::CYCLE && $reason !== self::DEPTH) {
            throw new InvalidArgumentException('The reason of a refused folder move is cycle or depth.');
        }

        $this->maxDepth = KnowledgeFolder::MAX_DEPTH;

        parent::__construct('The folder cannot move there.');
    }
}
