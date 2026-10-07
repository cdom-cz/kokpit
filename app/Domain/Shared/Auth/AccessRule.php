<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use Attribute;

/**
 * The mandatory access declaration of every Filament Resource, Page, Widget,
 * cluster and relation manager (D-03).
 *
 * It is read by AccessRules and drives the class's own access method, so the
 * declaration cannot disagree with the behaviour. PHP attributes are not
 * inherited: the attribute must sit on each concrete class, and the registry
 * test fails when one is missing.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AccessRule
{
    public function __construct(
        public readonly Audience $audience,
        public readonly string $reason = '',
    ) {}
}
