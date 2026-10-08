<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use LogicException;
use ReflectionClass;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Activity logging restricted to an explicit allowlist (D-06).
 *
 * Wraps the package trait and fixes its options: only the attributes named by
 * #[LoggedAttributes] on the model class are logged, only when they changed, and
 * an event that changes none of them writes no row. There is deliberately no
 * hook to widen the list (no log-all, fillable or unguarded option, no
 * per-model override): the package default would write an empty row for every
 * event, and logging everything would leak passwords, tokens and secrets.
 *
 * A model that uses this trait without the attribute, or with an empty list,
 * throws on its first logged event instead of silently logging nothing or
 * everything.
 *
 * Attributes change only through model saves: a bulk query update raises no
 * model event and therefore writes no row.
 */
trait LogsAllowlistedActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::loggedAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->getMorphClass());
    }

    /**
     * The allowlist declared on the concrete model class.
     *
     * @return list<string>
     */
    public static function loggedAttributes(): array
    {
        $declared = (new ReflectionClass(static::class))->getAttributes(LoggedAttributes::class);

        if ($declared === []) {
            throw new LogicException(sprintf(
                '%s logs activity but declares no #[LoggedAttributes([...])] allowlist on the class itself.',
                static::class,
            ));
        }

        // The attribute argument is typed list<string> only by its docblock; a keyed array must still become a list.
        $attributes = array_values($declared[0]->newInstance()->attributes); // @phpstan-ignore arrayValues.list

        if ($attributes === []) {
            throw new LogicException(sprintf(
                '%s declares an empty #[LoggedAttributes] allowlist; list at least one attribute or stop logging.',
                static::class,
            ));
        }

        return $attributes;
    }
}
