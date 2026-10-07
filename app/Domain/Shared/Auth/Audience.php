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
}
