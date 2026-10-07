<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use Attribute;

/**
 * Declares that a model is deliberately not partner scoped, with the reason.
 *
 * Reserved for the models authentication itself reads: a scope on them would
 * recurse (the scope checks roles) or lock everybody out. The architecture test
 * requires every model to either implement PartnerIsolated or carry this
 * attribute on the class itself, with a non-empty reason.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NotPartnerScoped
{
    public function __construct(public readonly string $reason) {}
}
