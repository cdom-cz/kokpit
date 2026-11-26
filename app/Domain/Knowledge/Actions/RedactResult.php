<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Guard\SecretFinding;

/**
 * What a redaction did (D-12, D-13): the redacted version number, whether it was the current one (so the article body
 * was rewritten and the redaction number raised), the 1-based lines that now hold the marker, the ranges a private key
 * extended the selection to, and the findings a rescan of the redacted body still reports (rule and line only, never
 * any text). It carries no line text and no reason.
 */
final readonly class RedactResult
{
    /**
     * @param  list<int>  $redactedLines
     * @param  list<array{from: int, to: int}>  $keyRanges
     * @param  list<SecretFinding>  $remainingFindings
     */
    public function __construct(
        public int $versionNo,
        public bool $current,
        public array $redactedLines,
        public array $keyRanges,
        public array $remainingFindings,
    ) {}
}
