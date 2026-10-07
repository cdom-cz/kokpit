<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Auth\NotPartnerScoped;
use App\Domain\Shared\Auth\PartnerIsolated;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Reads the isolation declaration of Eloquent models (D-03): a model either
 * implements PartnerIsolated or carries #[NotPartnerScoped] with a reason on
 * the class itself, never both and never neither.
 */
final class ModelDeclaration
{
    /**
     * Every concrete Eloquent model class under app/Domain/<context>/Models.
     *
     * @return list<class-string<Model>>
     */
    public static function appModels(): array
    {
        $root = app_path('Domain');
        $classes = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if ($file->getExtension() !== 'php' || ! str_contains($path, '/Models/')) {
                continue;
            }

            $relative = substr($path, strlen(str_replace(DIRECTORY_SEPARATOR, '/', app_path())) + 1, -4);
            $class = 'App\\'.str_replace('/', '\\', $relative);

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * What is wrong with the isolation declaration of a class; empty when it is fine.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function problems(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $implements = $reflection->implementsInterface(PartnerIsolated::class);
        // Attributes are not inherited: only the one written on the class itself counts.
        $attributes = $reflection->getAttributes(NotPartnerScoped::class);
        $label = $reflection->isAnonymous() ? 'anonymous class' : $class;
        $problems = [];

        if (! $implements && $attributes === []) {
            $problems[] = "{$label} neither implements PartnerIsolated nor carries #[NotPartnerScoped]";
        }

        if ($implements && $attributes !== []) {
            $problems[] = "{$label} both implements PartnerIsolated and carries #[NotPartnerScoped]";
        }

        foreach ($attributes as $attribute) {
            $reason = $attribute->getArguments()['reason'] ?? $attribute->getArguments()[0] ?? '';

            if (! is_string($reason) || trim($reason) === '') {
                $problems[] = "{$label} carries #[NotPartnerScoped] without a reason";
            }
        }

        return $problems;
    }
}
