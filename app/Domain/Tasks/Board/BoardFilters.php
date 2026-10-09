<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Board;

use App\Domain\Projects\Enums\ProjectPriority;
use Illuminate\Support\Str;

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

    /**
     * Filters from untrusted input, such as the URL query: an id that is no UUID
     * and a priority that is no priority are dropped, so a forged value never
     * reaches a uuid column as a database error.
     */
    public static function fromInput(
        mixed $projectId = null,
        mixed $clientId = null,
        mixed $assigneeId = null,
        mixed $tag = null,
        mixed $priority = null,
    ): self {
        $id = static fn (mixed $value): ?string => is_string($value) && Str::isUuid($value) ? $value : null;

        return new self(
            projectId: $id($projectId),
            clientId: $id($clientId),
            assigneeId: $id($assigneeId),
            tag: $id($tag),
            priority: is_string($priority) ? ProjectPriority::tryFrom($priority)?->value : null,
        );
    }
}
