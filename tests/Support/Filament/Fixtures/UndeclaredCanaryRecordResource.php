<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Filament\Concerns\EnforcesResourceAccessRule;
use Filament\Resources\Resource;
use Tests\Support\CanaryRecord;

/**
 * A copy of the canary resource with the attribute removed. The policy grants
 * viewAny on CanaryRecord, yet access must be denied because the class declares
 * nothing. Never registered in the panel.
 */
final class UndeclaredCanaryRecordResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = CanaryRecord::class;
}
