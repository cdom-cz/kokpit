<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Guard;

/**
 * One secret-scanner result: the rule and the 1-based line, nothing else. The matched text is never
 * kept, not even masked, so nothing downstream (exception, UI, log, activity record) can leak it (D-03).
 * Line 0 means "no line", used by the scan_failed finding.
 */
final readonly class SecretFinding
{
    public function __construct(
        public SecretRule $rule,
        public int $line,
    ) {}

    public function severity(): Severity
    {
        return $this->rule->severity();
    }

    /**
     * @return array{rule: string, line: int}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule->value, 'line' => $this->line];
    }
}
