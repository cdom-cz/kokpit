<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

/**
 * Who a panel class is meant for (D-03). A class that is open to nobody simply
 * carries no #[AccessRule] and is denied.
 */
enum Audience: string
{
    case AdminOnly = 'admin_only';
    case PartnerAllowed = 'partner_allowed';

    /**
     * A page reached only by its own signed route, never through the panel's access
     * checks (for example an invitation accept page). AccessRules::allows() always
     * denies it, so a class declaring Guest uses no Enforces* trait and is never
     * registered in the panel; the registry test restricts it to a SimplePage
     * subclass that the panel does not register.
     */
    case Guest = 'guest';
}
