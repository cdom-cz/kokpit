<?php

declare(strict_types=1);

namespace App\Domain\Settings\Numbering;

use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Shared\Sequences\SequenceAllocator;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The one entry point for document and task numbers (D-05).
 *
 * The stored pattern decides the allocator scope key and the written form;
 * the gap-free counter itself stays in the Phase 2 SequenceAllocator.
 */
final class DocumentNumbering
{
    public function __construct(
        private readonly SequenceAllocator $allocator,
        private readonly NumberingSettings $settings,
    ) {}

    /**
     * Allocates and writes the next number of a document kind. Must run inside
     * the caller's transaction (allocator contract): when the caller rolls
     * back, the number is given back.
     *
     * @throws \LogicException outside a transaction
     * @throws \OverflowException when the number does not fit the pattern's limit
     */
    public function next(DocumentKind $kind, DateTimeInterface $at): string
    {
        self::assertDocumentKind($kind);

        $pattern = $this->settings->patternFor($kind);
        $number = $this->allocator->next($pattern->scopeKey($at));

        return $pattern->format($number, $at);
    }

    /**
     * Shows the number the next allocation would produce, for the stored
     * pattern or for a candidate pattern. Reads the counter only; it never
     * takes, skips or reuses a number.
     *
     * @throws InvalidNumberPattern for a candidate pattern the grammar refuses
     */
    public function preview(DocumentKind $kind, ?string $pattern = null, ?DateTimeInterface $at = null): string
    {
        self::assertDocumentKind($kind);

        $parsed = $pattern === null ? $this->settings->patternFor($kind) : NumberPattern::parse($pattern, $kind);
        $at ??= now();

        return $parsed->format($this->allocator->peek($parsed->scopeKey($at)), $at);
    }

    /**
     * Allocates the next task number of a project, written as KEY-N. The
     * counter is per project (scope key task:<project id>) and never resets.
     * The task pattern is fixed, so it is not read from the settings.
     *
     * @throws InvalidArgumentException when the project key is not two to six capital letters
     * @throws \LogicException outside a transaction
     */
    public function nextTaskNumber(string $projectId, string $projectKey): string
    {
        if (preg_match(NumberPattern::PROJECT_KEY_PATTERN, $projectKey) !== 1) {
            throw new InvalidArgumentException('A project key is two to six capital letters.');
        }

        $number = $this->allocator->next(DocumentKind::Task->sequenceKind().':'.$projectId);

        return NumberPattern::parse(DocumentKind::Task->defaultPattern(), DocumentKind::Task)
            ->format($number, now(), $projectKey);
    }

    private static function assertDocumentKind(DocumentKind $kind): void
    {
        if ($kind === DocumentKind::Task) {
            throw new InvalidArgumentException('A task number is allocated per project, use nextTaskNumber().');
        }
    }
}
