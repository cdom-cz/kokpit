<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Board;

/**
 * The filters of a kanban board: scalar ids only, so the object is safe to
 * build from a URL query and to pass from the page to the mover.
 *
 * The same object drives the rendered columns and the drop index of a move,
 * so what the Admin sees and where a card lands are computed from one filter.
 */
final readonly class BoardFilters
{
    public function __construct(
        public ?string $projectId = null,
        public ?string $clientId = null,
        public ?string $assigneeId = null,
        public ?string $tag = null,
        public ?string $priority = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
