<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use Filament\Pages\SimplePage;

/**
 * A SimplePage that declares Audience::Guest, the shape of a page reached only by its
 * own signed route (for example an invitation accept page). It uses no Enforces*
 * trait and is never registered in the panel; tests use it to prove that a Guest
 * declaration is always denied by AccessRules and that the registry accepts Guest
 * on an unregistered SimplePage subclass.
 */
#[AccessRule(Audience::Guest, reason: 'Test fixture for a signed guest page outside the panel registry.')]
final class GuestSimplePageFixture extends SimplePage {}
