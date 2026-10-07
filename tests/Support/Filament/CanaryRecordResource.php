<?php

declare(strict_types=1);

namespace Tests\Support\Filament;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Tests\Support\CanaryRecord;
use Tests\Support\Filament\CanaryRecordResource\Pages\ListCanaryRecords;
use Tests\Support\Filament\CanaryRecordResource\Pages\ViewCanaryRecord;

/**
 * The test-only panel surface of the canary harness (D-04). It is registered in
 * the panel only when `kokpit.canary_harness` is on and this class exists, so it
 * can never be reached in production.
 *
 * Its query is the model's own: the global PartnerScope is not removed, so the
 * list, the record routes, Livewire state and global search all run through the
 * same fail-closed path a real resource would. `secret` is the record title and
 * therefore searchable.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'test-only canary surface')]
final class CanaryRecordResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = CanaryRecord::class;

    protected static ?string $recordTitleAttribute = 'secret';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('secret')->searchable(),
            TextColumn::make('client_id'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('secret'),
            TextEntry::make('client_id'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCanaryRecords::route('/'),
            'view' => ViewCanaryRecord::route('/{record}'),
        ];
    }
}
