<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Exceptions;

use App\Domain\Knowledge\Guard\SecretFinding;
use App\Domain\Knowledge\Guard\Severity;
use RuntimeException;

/**
 * The secret guard refused a save (SEC-01, SEC-02). It carries the findings (rule and line, never the matched text) and
 * the sha256 of the normalised body that was refused, so a screen can bind an acknowledgment to exactly that text. The
 * message is fixed and names no rule, line or text.
 */
final class SecretGuardRefused extends RuntimeException
{
    /**
     * @param  list<SecretFinding>  $findings
     */
    public function __construct(
        public readonly array $findings,
        public readonly string $normalisedBodyHash,
    ) {
        parent::__construct('The secret guard refused the save.');
    }

    /**
     * Whether any finding is Blocking: no acknowledgment can pass such a body (D-01).
     */
    public function hasBlocking(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity() === Severity::Blocking) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an acknowledgment can pass the save: no Blocking finding and at least one Ambiguous one.
     */
    public function isAcknowledgeable(): bool
    {
        if ($this->hasBlocking()) {
            return false;
        }

        foreach ($this->findings as $finding) {
            if ($finding->severity() === Severity::Ambiguous) {
                return true;
            }
        }

        return false;
    }
}
