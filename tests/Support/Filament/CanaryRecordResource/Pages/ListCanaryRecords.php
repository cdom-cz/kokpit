<?php

declare(strict_types=1);

namespace Tests\Support\Filament\CanaryRecordResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Tests\Support\Filament\CanaryRecordResource;

final class ListCanaryRecords extends ListRecords
{
    protected static string $resource = CanaryRecordResource::class;
}
