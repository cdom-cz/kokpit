<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\AccessRules;
use App\Filament\Pages\Dashboard;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Tests\Support\Filament\Fixtures\UndeclaredPage;

/*
 * D-03: every Filament Resource, Page, Widget, cluster and relation manager declares
 * #[AccessRule] on the class itself. The classes come from the admin panel's registry
 * and from a scan of app/Filament, so a class that is not yet registered cannot hide
 * and there is no hand-kept list.
 */

/**
 * @param  array<mixed>  $classes
 * @return list<class-string>
 */
function panelRegistryUnique(array $classes): array
{
    /** @var list<class-string> $unique */
    $unique = array_values(array_unique(array_filter($classes, 'is_string')));
    sort($unique, SORT_STRING);

    return $unique;
}

/**
 * Relation managers with groups and configurations flattened.
 *
 * @param  array<mixed>  $relations
 * @return list<class-string>
 */
function panelRegistryRelationManagers(array $relations): array
{
    $managers = [];

    foreach ($relations as $relation) {
        if ($relation instanceof RelationGroup) {
            array_push($managers, ...panelRegistryRelationManagers($relation->getManagers()));
        } elseif ($relation instanceof RelationManagerConfiguration) {
            $managers[] = $relation->relationManager;
        } elseif (is_string($relation)) {
            $managers[] = $relation;
        }
    }

    return $managers;
}

/**
 * Everything the admin panel registers, with the relation managers of every
 * registered resource.
 *
 * @return list<class-string>
 */
function panelRegistryPanelClasses(): array
{
    $panel = Filament::getPanel('admin');
    $classes = [
        ...$panel->getResources(),
        ...$panel->getPages(),
        ...$panel->getClusters(),
    ];

    foreach ($panel->getWidgets() as $widget) {
        $classes[] = $widget instanceof WidgetConfiguration ? $widget->widget : $widget;
    }

    foreach ($panel->getPageConfigurations() as $configuration) {
        $classes[] = $configuration->page;
    }

    foreach ($panel->getResourceConfigurations() as $configuration) {
        $classes[] = $configuration->resource;
    }

    foreach ($classes as $class) {
        if (is_subclass_of($class, Resource::class)) {
            array_push($classes, ...panelRegistryRelationManagers($class::getRelations()));
        }
    }

    return panelRegistryUnique($classes);
}

/**
 * Resource pages (list, create, edit, view) are governed by their Resource and its
 * policy; every other concrete subclass of the governed bases needs a declaration.
 */
function panelRegistryIsGoverned(string $class): bool
{
    if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
        return false;
    }

    if (is_subclass_of($class, ResourcePage::class)) {
        return false;
    }

    foreach ([Resource::class, Page::class, Widget::class, RelationManager::class, Cluster::class] as $base) {
        if (is_subclass_of($class, $base)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<class-string>
 */
function panelRegistryAppClasses(): array
{
    $root = app_path('Filament');

    if (! is_dir($root)) {
        return [];
    }

    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
        $relative = substr($path, strlen(str_replace(DIRECTORY_SEPARATOR, '/', app_path())) + 1, -4);
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (panelRegistryIsGoverned($class)) {
            $classes[] = $class;
        }
    }

    return panelRegistryUnique($classes);
}

/**
 * The classes among the given ones without an #[AccessRule] of their own, sorted by
 * class name so the failure output is identical across runs.
 *
 * @param  list<string>  $classes
 * @return list<string>
 */
function panelRegistryUndeclared(array $classes): array
{
    return panelRegistryUnique(array_filter(
        $classes,
        static fn (string $class): bool => AccessRules::for($class) === null,
    ));
}

it('declares an access rule on every class the panel registers and every concrete class under app/Filament', function (): void {
    $undeclared = panelRegistryUndeclared(panelRegistryUnique([...panelRegistryPanelClasses(), ...panelRegistryAppClasses()]));

    expect($undeclared)->toBe([], "Filament classes without #[AccessRule]:\n".implode("\n", $undeclared));
});

it('does not pass vacuously: the registry and the directory scan both see the dashboard', function (): void {
    expect(panelRegistryPanelClasses())->toContain(Dashboard::class)
        ->and(panelRegistryAppClasses())->toContain(Dashboard::class);
});

it('reports an undeclared class', function (): void {
    expect(panelRegistryUndeclared([Dashboard::class, UndeclaredPage::class]))->toBe([UndeclaredPage::class]);
});

it('reports a subclass of a declared class because attributes are not inherited', function (): void {
    $subclass = new class extends Dashboard {};

    expect(panelRegistryUndeclared([Dashboard::class, $subclass::class]))->toBe([$subclass::class]);
});

it('sorts the offenders by class name whatever the input order', function (): void {
    $other = new class extends Dashboard {};
    $input = [UndeclaredPage::class, $other::class, Dashboard::class];

    $forward = panelRegistryUndeclared($input);
    $sorted = $forward;
    sort($sorted, SORT_STRING);

    expect($forward)->toBe($sorted)
        ->and(panelRegistryUndeclared(array_reverse($input)))->toBe($forward)
        ->and($forward)->toHaveCount(2);
});
