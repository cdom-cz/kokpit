<?php

declare(strict_types=1);

namespace App\Domain\Shared\Policies;

use App\Domain\Shared\Auth\KokpitPolicy;

/**
 * Admin-only records: the base admits the Admin, and this policy grants a
 * Partner nothing. Registered for the package models that are closed to
 * Partners in Phase 2 (media, tags, activity log, webhook calls).
 */
final class AdminOnlyPolicy extends KokpitPolicy {}
