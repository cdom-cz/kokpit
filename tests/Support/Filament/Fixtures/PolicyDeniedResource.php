<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Models\Media;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use Filament\Resources\Resource;

/**
 * A resource declared PartnerAllowed over a model whose policy denies a Partner
 * (Media is Admin-only). The declaration says yes, the policy says no: access
 * must be the AND of both. Never registered in the panel.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'test fixture')]
final class PolicyDeniedResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Media::class;
}
