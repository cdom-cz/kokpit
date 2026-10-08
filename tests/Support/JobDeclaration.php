<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use FilesystemIterator;
use Illuminate\Foundation\Bus\Dispatchable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Reads the job contract of queued classes (D-10): every dispatchable job
 * extends KokpitJob, and every concrete KokpitJob declares #[Idempotent] with
 * a written explanation on the class itself.
 */
final class JobDeclaration
{
    /**
     * Every class found in the PHP files below a directory, named from the path
     * (PSR-4: the directory is the root of the namespace). Interfaces, traits
     * and files that declare no class of that name are left out.
     *
     * @return list<class-string>
     */
    public static function classesIn(string $directory, string $namespace): array
    {
        $root = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $directory), '/');
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', substr($path, strlen($root) + 1, -4));

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * The concrete classes of a list that extend KokpitJob.
     *
     * @param  list<class-string>  $classes
     * @return list<class-string<KokpitJob>>
     */
    public static function concreteJobs(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => is_subclass_of($class, KokpitJob::class) && ! (new ReflectionClass($class))->isAbstract(),
        ));
    }

    /**
     * The concrete classes of a list that use the Dispatchable bus trait.
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function dispatchables(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => ! (new ReflectionClass($class))->isAbstract()
                && in_array(Dispatchable::class, class_uses_recursive($class), true),
        ));
    }

    /**
     * What is wrong with the job declaration of a class; empty when it is fine.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function problems(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $label = $reflection->isAnonymous() ? 'anonymous class' : $class;
        $problems = [];

        if (in_array(Dispatchable::class, class_uses_recursive($class), true) && ! $reflection->isSubclassOf(KokpitJob::class)) {
            $problems[] = "{$label} is dispatchable but does not extend KokpitJob";
        }

        if (! $reflection->isSubclassOf(KokpitJob::class) || $reflection->isAbstract()) {
            return $problems;
        }

        // Attributes are not inherited: only the one written on the class itself counts.
        $attributes = $reflection->getAttributes(Idempotent::class);

        if ($attributes === []) {
            $problems[] = "{$label} extends KokpitJob without #[Idempotent]";
        }

        foreach ($attributes as $attribute) {
            $how = $attribute->getArguments()['how'] ?? $attribute->getArguments()[0] ?? '';

            if (! is_string($how) || trim($how) === '') {
                $problems[] = "{$label} carries #[Idempotent] without an explanation";
            }
        }

        return $problems;
    }
}
