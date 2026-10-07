<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Widgets\Widget;

/**
 * A widget declared PartnerAllowed. Never registered in the panel.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'test fixture')]
final class PartnerAllowedWidget extends Widget
{
    use EnforcesWidgetAccessRule;

    protected string $view = 'filament-widgets::widget';
}
