<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Convention rules over the model layer (R7 and R8). Like the catalogue rules
 * in PgSchema they are pure functions, so a self-check can feed them a
 * synthetic violation and prove they are able to fail.
 */
final class ModelRules
{
    /**
     * R7: the morph map is enforced, its aliases are short snake_case nouns
     * and every mapped class uses HasUuids.
     *
     * @param  array<string, class-string>  $map
     * @return list<string>
     */
    public static function morphMapViolations(array $map, bool $morphMapRequired): array
    {
        $violations = [];

        if ($map === []) {
            $violations[] = 'the morph map is empty';
        }

        if (! $morphMapRequired) {
            $violations[] = 'the morph map is not required (enforceMorphMap is not active)';
        }

        foreach ($map as $alias => $class) {
            if (str_contains((string) $alias, '\\')) {
                $violations[] = "alias '{$alias}' contains a backslash";
            } elseif (preg_match('/^[a-z][a-z0-9_]*$/', (string) $alias) !== 1) {
                $violations[] = "alias '{$alias}' is not snake_case";
            }

            if (! self::usesHasUuids($class)) {
                $violations[] = "{$class} (alias '{$alias}') does not use HasUuids";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * R8: every package model registered in config is the expected HasUuids
     * subclass of the package base class.
     *
     * @param  array<string, array{registered: mixed, expected: class-string, base: class-string}>  $entries
     * @return list<string>
     */
    public static function unregisteredPackageModels(array $entries): array
    {
        $violations = [];

        foreach ($entries as $description => $entry) {
            if ($entry['registered'] !== $entry['expected']) {
                $violations[] = "{$description}: registered ".(is_string($entry['registered']) ? $entry['registered'] : get_debug_type($entry['registered']))." instead of {$entry['expected']}";

                continue;
            }

            if (! is_subclass_of($entry['expected'], $entry['base'])) {
                $violations[] = "{$description}: {$entry['expected']} is not a subclass of {$entry['base']}";
            }

            if (! self::usesHasUuids($entry['expected'])) {
                $violations[] = "{$description}: {$entry['expected']} does not use HasUuids";
            }
        }

        sort($violations);

        return $violations;
    }

    public static function usesHasUuids(string $class): bool
    {
        return class_exists($class) && in_array(HasUuids::class, class_uses_recursive($class), true);
    }
}
