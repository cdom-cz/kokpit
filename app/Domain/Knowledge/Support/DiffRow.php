<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

/**
 * One row of a version diff: an unchanged line, an added line, a removed line, or a separator that stands for a run of
 * unchanged lines the view hides. The text is the stored line verbatim: nothing is escaped here, the view escapes.
 * An unchanged row carries both line numbers, a removed row only the old one, an added row only the new one, a
 * collapsed row neither (its text is empty and `collapsed` holds the number of hidden lines, 0 on every other row).
 */
final readonly class DiffRow
{
    public const string CONTEXT = 'context';

    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    public const string COLLAPSED = 'collapsed';

    public function __construct(
        public string $type,
        public ?int $oldLine,
        public ?int $newLine,
        public string $text,
        public int $collapsed = 0,
    ) {}

    public static function context(int $oldLine, int $newLine, string $text): self
    {
        return new self(self::CONTEXT, $oldLine, $newLine, $text);
    }

    public static function added(int $newLine, string $text): self
    {
        return new self(self::ADDED, null, $newLine, $text);
    }

    public static function removed(int $oldLine, string $text): self
    {
        return new self(self::REMOVED, $oldLine, null, $text);
    }

    public static function collapsed(int $hidden): self
    {
        return new self(self::COLLAPSED, null, null, '', $hidden);
    }
}
