<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * A relation manager declared PartnerAllowed whose parent check (the related
 * resource's policy) denies a Partner. Access must be the AND of both. Never
 * registered.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'test fixture')]
final class PolicyDeniedRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'media';

    protected static ?string $relatedResource = PolicyDeniedResource::class;
}
