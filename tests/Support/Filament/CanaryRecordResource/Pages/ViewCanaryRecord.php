<?php

declare(strict_types=1);

namespace Tests\Support\Filament\CanaryRecordResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Tests\Support\Filament\CanaryRecordResource;

final class ViewCanaryRecord extends ViewRecord
{
    protected static string $resource = CanaryRecordResource::class;
}
