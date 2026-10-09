<?php

declare(strict_types=1);

use App\Livewire\TimeTracking\RequiresAdmin;
use App\Livewire\TimeTracking\TimerBar;
use Livewire\Component;

/*
 * The plain Livewire components under app/Livewire sit outside the Filament registry, so no
 * #[AccessRule] reaches them, and /livewire/update is a public endpoint (UI-SPEC Access and
 * Visibility Contract, research A7). Every concrete component therefore carries the RequiresAdmin
 * trait, and its public properties are scalars, because everything public travels in the snapshot
 * to the browser. The classes come from a scan of the directory, so no list is kept by hand.
 */

/**
 * The concrete Livewire components under app/Livewire.
 *
 * @return list<class-string<Component>>
 */
function livewireComponents(): array
{
    $root = dirname(__DIR__, 2).'/app/Livewire';
    $components = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'App\\Livewire\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, Component::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $components[] = $class;
        }
    }

    sort($components, SORT_STRING);

    return $components;
}

/**
 * The names of the public properties that Livewire or Filament put on a component: the ones of
 * the base classes and of every trait outside the App namespace (the mounted-action state and
 * the like).
 *
 * @param  class-string  $class
 * @return list<string>
 */
function livewireFrameworkProperties(string $class): array
{
    $names = [];
    $sources = [];

    for ($parent = (new ReflectionClass($class))->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
        $sources[] = $parent;
    }

    foreach (class_uses_recursive($class) as $trait) {
        if (! str_starts_with($trait, 'App\\')) {
            $sources[] = new ReflectionClass($trait);
        }
    }

    foreach ($sources as $source) {
        foreach ($source->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $names[$property->getName()] = true;
        }
    }

    return array_keys($names);
}

it('finds the timer bar, so the scan is not vacuous', function (): void {
    expect(livewireComponents())->toContain(TimerBar::class);
});

it('puts the Admin guard on every concrete Livewire component', function (): void {
    foreach (livewireComponents() as $class) {
        expect(in_array(RequiresAdmin::class, class_uses_recursive($class), true))->toBeTrue($class.' must use RequiresAdmin');
    }
});

it('holds only scalars, null and arrays in the public properties of a component', function (): void {
    foreach (livewireComponents() as $class) {
        $framework = livewireFrameworkProperties($class);

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || in_array($property->getName(), $framework, true)) {
                continue;
            }

            $type = $property->getType();
            $label = $class.'::$'.$property->getName();

            expect($type)->not->toBeNull($label.' needs a declared type');

            $parts = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

            foreach ($parts as $part) {
                expect($part instanceof ReflectionNamedType && $part->isBuiltin() && $part->getName() !== 'mixed')
                    ->toBeTrue($label.' must be a scalar, null or array, never a class');
            }
        }
    }
});
