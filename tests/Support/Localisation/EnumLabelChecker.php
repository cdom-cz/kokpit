<?php

declare(strict_types=1);

namespace Tests\Support\Localisation;

use BackedEnum;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\App;
use UnitEnum;

/**
 * Reports enum cases whose label is not a real translation.
 *
 * Laravel's translator returns the key itself when a translation is missing, so
 * a missing Czech label shows up as "enums.something.case" on screen. The
 * checker flags a label that is empty, starts with "enums." or looks like a
 * dotted translation key.
 */
final class EnumLabelChecker
{
    /**
     * @param  list<class-string>  $enumClasses
     * @return list<string> one line per problem, empty when every label is translated
     */
    public static function problems(array $enumClasses, string $locale = 'cs'): array
    {
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            $problems = [];

            foreach ($enumClasses as $class) {
                if (! enum_exists($class) || ! is_subclass_of($class, HasLabel::class)) {
                    $problems[] = sprintf('%s is not an enum implementing HasLabel', $class);

                    continue;
                }

                foreach ($class::cases() as $case) {
                    $problem = self::problem($case);

                    if ($problem !== null) {
                        $problems[] = sprintf('%s::%s %s', $class, $case->name, $problem);
                    }
                }
            }

            return $problems;
        } finally {
            App::setLocale($previous);
        }
    }

    private static function problem(UnitEnum $case): ?string
    {
        if (! $case instanceof HasLabel) {
            return 'has no label';
        }

        $label = $case->getLabel();

        if ($label instanceof Htmlable) {
            $label = $label->toHtml();
        }

        $label = trim((string) $label);

        if ($label === '') {
            return 'has an empty label';
        }

        if (str_starts_with($label, 'enums.')) {
            return sprintf('shows the translation key "%s"', $label);
        }

        if (preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/D', $label) === 1) {
            return sprintf('shows what looks like the translation key "%s"', $label);
        }

        if ($case instanceof BackedEnum && $label === (string) $case->value) {
            return sprintf('shows its raw value "%s" instead of a translated label', $label);
        }

        return null;
    }
}
