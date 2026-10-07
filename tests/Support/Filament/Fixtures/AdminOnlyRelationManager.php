<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use Filament\Resources\RelationManagers\RelationManager;
use Tests\Support\Filament\CanaryRecordResource;

/**
 * A relation manager declared AdminOnly whose parent check is the canary
 * Resource's access (policy viewAny, which grants a Partner). Never registered.
 */
#[AccessRule(Audience::AdminOnly, reason: 'test fixture')]
final class AdminOnlyRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'siblings';

    protected static ?string $relatedResource = CanaryRecordResource::class;
}
