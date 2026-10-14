<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\ApiTokenResource\Pages\ListApiTokens;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The API tokens of the signed-in Admin (AP-01, AP-05).
 *
 * A list page only: tokens are created by the header action of the page and revoked by a
 * row action, so there is no create, edit or view page. The query shows the tokens of the
 * signed-in user and nobody else's. A token never shows its plain text, its hash, an IP
 * address or a user agent here.
 */
#[AccessRule(Audience::AdminOnly, reason: 'API tokens grant access to measured time; only the Admin creates and revokes them.')]
final class ApiTokenResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = PersonalAccessToken::class;

    protected static ?string $slug = 'api-tokens';

    protected static ?int $navigationSort = 92;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.api_tokens.navigation_label');
    }

    public static function getNavigationGroup(): string
    {
        return __('kokpit.api_tokens.navigation_group');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedKey;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.api_tokens.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.api_tokens.plural_model_label');
    }

    /**
     * Tokens are created through the page action, never through a create page.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The tokens of the signed-in user: the morph alias and the user id, so another
     * user's token is not in the list and cannot be reached by a forged record key.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', auth()->id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('kokpit.api_tokens.columns.name'))
                    ->weight('semibold')
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
                TextColumn::make('abilities')
                    ->label(__('kokpit.api_tokens.columns.abilities'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => ApiAbility::tryFrom($state)?->getLabel() ?? $state)
                    ->tooltip(static fn (PersonalAccessToken $record): string => implode(', ', (array) $record->abilities)),
                TextColumn::make('created_at')
                    ->label(__('kokpit.api_tokens.columns.created_at'))
                    ->dateTime('j. n. Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginated([25]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiTokens::route('/'),
        ];
    }
}
