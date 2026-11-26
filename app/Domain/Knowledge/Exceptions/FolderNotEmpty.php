<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Exceptions;

use RuntimeException;

/**
 * A folder that still holds something cannot be deleted (ORG-02). Nothing was deleted. The counts are what blocks it:
 * the articles of the folder, archived ones and every kind included (the foreign key counts them all), and its direct
 * subfolders, so the screen can say how much has to move first. The message is fixed and names no folder.
 */
final class FolderNotEmpty extends RuntimeException
{
    public function __construct(public readonly int $articleCount, public readonly int $subfolderCount)
    {
        parent::__construct('The folder is not empty.');
    }
}
