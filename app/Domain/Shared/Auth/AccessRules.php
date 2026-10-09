<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use ReflectionClass;

/**
 * Fail-closed evaluation of #[AccessRule] (D-03).
 *
 * No attribute means nobody gets in, not even the Admin: a new panel class is
 * denied until it declares who it is for.
 */
final class AccessRules
{
    /**
     * The rule declared on that class itself, or null. Attributes are not
     * inherited, so a subclass of a declared class is undeclared.
     *
     * @param  class-string|string  $class
     */
    public static function for(string $class): ?AccessRule
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(AccessRule::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @param  class-string|string  $class
     */
    public static function allows(string $class): bool
    {
        $rule = self::for($class);

        if ($rule === null) {
            return false;
        }

        $context = app(PartnerContext::class);

        return match ($rule->audience) {
            Audience::AdminOnly => $context->isAdmin(),
            Audience::PartnerAllowed => $context->isAdmin() || $context->partnerClientId() !== null,
            Audience::Guest => false,
        };
    }
}
