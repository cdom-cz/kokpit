<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use Filament\Pages\Page;

/**
 * A page that declares no #[AccessRule] and does not use the enforcing trait.
 * It is never registered in the panel; tests use it to prove that the registry
 * check reports an undeclared class and that Filament's own default is open.
 */
final class UndeclaredPage extends Page {}
