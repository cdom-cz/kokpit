<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Audit\ActivitySourceLabel;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Models\Activity;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Support\ActivityPresenter;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * The global, read-only audit trail for the Admin (D-07).
 *
 * Nothing here creates, edits or deletes a row, and nothing loads the subject
 * model of a row: the causer is the only relation that is eager-loaded.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The audit trail shows who changed what; a Partner never sees it.')]
final class ActivityResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity';

    protected static ?int $navigationSort = 80;

    /** The value of the user filter that selects rows without a user. */
    private const string NO_USER = 'none';

    public static function getNavigationLabel(): string
    {
        return __('kokpit.activity.navigation_label');
    }

    public static function getNavigationGroup(): string
    {
        return __('kokpit.activity.navigation_group');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedClock;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.activity.title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.activity.title');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('causer'))
            ->columns(ActivityPresenter::columns(withSubject: true))
            ->filters(self::filters())
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->emptyStateHeading(__('kokpit.activity.empty_heading'))
            ->emptyStateDescription(__('kokpit.activity.empty_description'));
    }

    /**
     * Event, source, subject type, user (or no user) and a date range in
     * Europe/Prague days (D-07, D-08).
     *
     * @return list<SelectFilter|Filter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('event')
                ->label(__('kokpit.activity.filters.event'))
                ->options(static fn (): array => [
                    'created' => __('kokpit.activity.events.created'),
                    'updated' => __('kokpit.activity.events.updated'),
                    'deleted' => __('kokpit.activity.events.deleted'),
                ]),
            SelectFilter::make('source')
                ->label(__('kokpit.activity.filters.source'))
                ->options(static fn (): array => collect(ActivitySourceLabel::cases())
                    ->mapWithKeys(static fn (ActivitySourceLabel $label): array => [$label->value => $label->getLabel()])
                    ->all()),
            SelectFilter::make('subject_type')
                ->label(__('kokpit.activity.filters.subject_type'))
                ->options(static fn (): array => self::subjectTypeOptions()),
            SelectFilter::make('causer')
                ->label(__('kokpit.activity.filters.causer'))
                ->options(static fn (): array => self::causerOptions())
                ->query(static function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    if (! is_string($value) || $value === '') {
                        return $query;
                    }

                    return $value === self::NO_USER
                        ? $query->whereNull('causer_id')
                        : $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $value);
                }),
            Filter::make('created_at')
                ->schema([
                    DatePicker::make('created_from')->label(__('kokpit.activity.filters.created_from')),
                    DatePicker::make('created_until')->label(__('kokpit.activity.filters.created_until')),
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    $from = self::displayDayStart($data['created_from'] ?? null);
                    $until = self::displayDayStart($data['created_until'] ?? null);

                    return $query
                        ->when($from, static fn (Builder $query, CarbonImmutable $from): Builder => $query->where('created_at', '>=', $from->utc()->toIso8601String()))
                        ->when($until, static fn (Builder $query, CarbonImmutable $until): Builder => $query->where('created_at', '<', $until->addDay()->utc()->toIso8601String()));
                }),
        ];
    }

    /**
     * The morph aliases of the models that log activity, computed from the
     * active morph map.
     *
     * @return array<string, string>
     */
    private static function subjectTypeOptions(): array
    {
        $options = [];

        foreach (Relation::morphMap() as $alias => $class) {
            if (class_exists($class) && in_array(LogsAllowlistedActivity::class, class_uses_recursive($class), true)) {
                $options[$alias] = ActivityPresenter::subjectLabel($alias);
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function causerOptions(): array
    {
        /** @var array<string, string> $users */
        $users = User::query()->orderBy('name')->pluck('name', 'id')->all();

        return [self::NO_USER => __('kokpit.activity.no_user')] + $users;
    }

    /**
     * Midnight of a Y-m-d day in the display time zone, or null for anything
     * that is not a real calendar day.
     */
    private static function displayDayStart(mixed $day): ?CarbonImmutable
    {
        if (! is_string($day) || $day === '') {
            return null;
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $day, FilamentTimezone::get());
        } catch (Throwable) {
            return null;
        }

        return $start instanceof CarbonImmutable && $start->format('Y-m-d') === $day ? $start : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
