<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Shared\Auth\KokpitPolicy;

/**
 * Accounts are managed by the Admin only (US-02, D-04): the base admits the Admin
 * and this policy grants a Partner nothing, not even its own record. Its purpose
 * is to give the panel's strict authorization a policy to ask for the accounts
 * tab; no screen lists, edits or deletes users outside that tab.
 */
final class UserPolicy extends KokpitPolicy {}
