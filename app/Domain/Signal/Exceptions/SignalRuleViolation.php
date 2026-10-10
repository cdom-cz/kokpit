<?php

declare(strict_types=1);

namespace App\Domain\Signal\Exceptions;

use RuntimeException;

/**
 * A planner rule refused the operation (a full day, a locked day, a fourth goal ...). The message is
 * already translated and meant for the user, so the screens show it as a notification as it is.
 */
final class SignalRuleViolation extends RuntimeException
{
    public static function because(string $translationKey, array $replace = []): self
    {
        return new self(__('kokpit.signal.errors.'.$translationKey, $replace));
    }
}
