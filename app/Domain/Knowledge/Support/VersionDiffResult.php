<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

/**
 * The outcome of comparing two stored bodies. `added` and `removed` count the lines of the whole diff, also when
 * `truncated` cut the rows. `identical` and `tooLarge` results carry no rows and (for `tooLarge`) no counts.
 */
final readonly class VersionDiffResult
{
    /**
     * @param  list<DiffRow>  $rows
     */
    public function __construct(
        public array $rows,
        public int $added,
        public int $removed,
        public bool $identical,
        public bool $truncated,
        public bool $tooLarge,
    ) {}

    public static function identical(): self
    {
        return new self([], 0, 0, true, false, false);
    }

    public static function tooLarge(): self
    {
        return new self([], 0, 0, false, false, true);
    }
}
