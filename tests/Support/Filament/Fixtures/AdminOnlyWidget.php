<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Widgets\Widget;

/**
 * A widget declared AdminOnly. Never registered in the panel.
 */
#[AccessRule(Audience::AdminOnly, reason: 'test fixture')]
final class AdminOnlyWidget extends Widget
{
    use EnforcesWidgetAccessRule;

    protected string $view = 'filament-widgets::widget';
}
