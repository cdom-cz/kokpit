<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Audit\ActivitySourceLabel;
use App\Domain\Shared\Models\Activity;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Lang;

/**
 * How an activity row reads on screen, shared by the global overview and the
 * per-record history relation manager (D-07).
 *
 * Only `attribute_changes` is rendered. That column is allowlisted at write
 * time, so it can never hold a hidden or secret attribute. The free-form
 * properties payload of a row is deliberately not read here. The subject
 * model is never loaded either: a row shows its stored type alias and the
 * short id only, so a deleted record and a heavy record cost nothing.
 */
final class ActivityPresenter
{
    /**
     * The shared table columns. The overview adds the subject; the history of
     * one record leaves it out because every row has the same subject.
     *
     * @return list<Column>
     */
    public static function columns(bool $withSubject): array
    {
        $columns = [
            TextColumn::make('created_at')
                ->label(__('kokpit.activity.columns.created_at'))
                ->dateTime(),
            TextColumn::make('event')
                ->label(__('kokpit.activity.columns.event'))
                ->badge()
                ->formatStateUsing(static fn (?string $state): string => self::eventLabel($state)),
        ];

        if ($withSubject) {
            $columns[] = TextColumn::make('subject_type')
                ->label(__('kokpit.activity.columns.subject'))
                ->formatStateUsing(static fn (?string $state): string => self::subjectLabel($state))
                ->description(static fn (Activity $record): ?string => self::shortId($record->subject_id));
        }

        $columns[] = TextColumn::make('causer')
            ->label(__('kokpit.activity.columns.causer'))
            ->state(static fn (Activity $record): string => self::causerLabel($record));

        $columns[] = TextColumn::make('attribute_changes')
            ->label(__('kokpit.activity.columns.changes'))
            ->state(static fn (Activity $record): string => self::changes($record))
            ->wrap();

        return $columns;
    }

    /**
     * The allowlisted attribute changes of a row as "name: old -> new", one
     * entry per changed attribute.
     */
    public static function changes(Activity $activity): string
    {
        $changes = $activity->attribute_changes;

        if ($changes === null) {
            return '';
        }

        /** @var array<string, mixed> $new */
        $new = (array) $changes->get('attributes', []);
        /** @var array<string, mixed> $old */
        $old = (array) $changes->get('old', []);

        $parts = [];

        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $name) {
            $name = (string) $name;
            $parts[] = sprintf('%s: %s -> %s', $name, self::scalar($old[$name] ?? null), self::scalar($new[$name] ?? null));
        }

        return implode('; ', $parts);
    }

    public static function subjectLabel(?string $alias): string
    {
        if ($alias === null || $alias === '') {
            return __('kokpit.activity.empty_value');
        }

        return self::translatedOr('kokpit.activity.subjects.'.$alias, $alias);
    }

    /**
     * The user's name, or, for work without a user, the source label.
     */
    public static function causerLabel(Activity $activity): string
    {
        $name = $activity->causer?->getAttribute('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $source = ActivitySourceLabel::tryFrom((string) $activity->getAttribute('source'));

        return $source?->getLabel() ?? __('kokpit.activity.empty_value');
    }

    private static function eventLabel(?string $event): string
    {
        if ($event === null || $event === '') {
            return __('kokpit.activity.empty_value');
        }

        return self::translatedOr('kokpit.activity.events.'.$event, $event);
    }

    /**
     * The translation of a key, or the fallback when the key has none.
     */
    private static function translatedOr(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) __($key) : $fallback;
    }

    /**
     * The last eight characters of the id: the leading part of a UUID v7 is a
     * timestamp, so records created together would share their first characters.
     */
    private static function shortId(mixed $id): ?string
    {
        return is_string($id) && $id !== '' ? '…'.substr($id, -8) : null;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}
