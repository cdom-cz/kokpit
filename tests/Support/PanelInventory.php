<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Filament\Clusters\Cluster;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Pages\SimplePage;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Discovery of every screen of the admin panel and every plain Livewire component, and the observation of
 * who may open them (AUD-02, D-01, R-12-03-2).
 *
 * The discovery is a port of the logic of tests/Arch/PanelRegistryTest.php (the panel registry with its
 * widget, page and resource configurations and the relation managers of every registered resource, plus a
 * scan of app/Filament), copied here because those are file-level functions of another test file. A class
 * counts when it is concrete and a subclass of Resource, Page, SimplePage, Widget, RelationManager or
 * Cluster. A resource page (list, create, edit, view) is governed by its Resource, except a resource page
 * that carries a rule of its own (the project board), which counts as well.
 *
 * Nothing here decides what the matrix expects: it only finds the classes and observes what the code
 * answers, so a test can compare that with the literal declaration in PartnerMatrix.
 */
final class PanelInventory
{
    /**
     * Everything the admin panel registers or app/Filament contains and that carries an access rule,
     * unique and sorted by class name.
     *
     * @return list<class-string>
     */
    public static function panelClasses(): array
    {
        return self::unique([...self::registeredClasses(), ...array_filter(self::appFilamentClasses(), self::isGoverned(...))]);
    }

    /**
     * Every relation manager of every registered resource, mapped to its owning resource, sorted by manager.
     *
     * @return array<class-string, class-string>
     */
    public static function relationManagers(): array
    {
        $map = [];

        foreach (self::registeredClasses() as $class) {
            if (! is_subclass_of($class, Resource::class)) {
                continue;
            }

            foreach (self::flattenRelationManagers($class::getRelations()) as $manager) {
                $map[$manager] = $class;
            }
        }

        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * The concrete Livewire components under app/Livewire, sorted by class name.
     *
     * @return list<class-string>
     */
    public static function livewireComponents(): array
    {
        $root = app_path('Livewire');
        $components = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), strlen(str_replace(DIRECTORY_SEPARATOR, '/', $root)) + 1, -4);
            $class = 'App\\Livewire\\'.str_replace('/', '\\', $relative);

            if (class_exists($class) && is_subclass_of($class, Component::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $components[] = $class;
            }
        }

        return self::unique($components);
    }

    /**
     * Whether the signed-in user (the one the test acts as) may open the class (R-12-03-2): a Resource, a
     * non-resource Page, a resource page with a rule of its own and a cluster by canAccess(); a Widget by
     * canView(); a relation manager by canViewForRecord() on the given owner record and the view page of the
     * owning resource (else its edit page); a guest page (a class declaring the guest audience) through the declaration, which denies it to everyone
     * by design. The admin panel is made current first.
     *
     * @param  class-string  $class
     */
    public static function observedAccess(string $class, ?Model $owner = null): bool
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        if (AccessRules::for($class)?->audience === Audience::Guest) {
            return AccessRules::allows($class);
        }

        if (is_subclass_of($class, RelationManager::class)) {
            if (! $owner instanceof Model) {
                throw new LogicException("{$class} is a relation manager and needs the owner record it is shown on.");
            }

            return $class::canViewForRecord($owner, self::pageClassOf(self::owningResource($class)));
        }

        if (is_subclass_of($class, Widget::class)) {
            return $class::canView();
        }

        if (is_subclass_of($class, Resource::class) || is_subclass_of($class, Page::class) || is_subclass_of($class, ResourcePage::class) || is_subclass_of($class, Cluster::class)) {
            return $class::canAccess();
        }

        throw new LogicException("{$class} is not a Resource, Page, Widget, Cluster or relation manager of the panel.");
    }

    /**
     * The URL a request can open for a class, or null for a class that has no page of its own (a widget, a relation
     * manager, a guest page). A resource opens at its index page; a resource page with a rule of its own (the
     * project board) opens on the canary record of the owning resource's model; any other page at its own route.
     *
     * @param  class-string  $class
     */
    public static function requestUrl(string $class, string $canary): ?string
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        if (is_subclass_of($class, Resource::class)) {
            return $class::getUrl('index');
        }

        if (is_subclass_of($class, ResourcePage::class)) {
            $resource = $class::getResource();

            foreach ($resource::getPages() as $name => $registration) {
                if ($registration->getPage() === $class) {
                    return $resource::getUrl($name, ['record' => self::canaryRecord($resource::getModel(), $canary)]);
                }
            }

            throw new LogicException("{$class} is not a page of {$resource}; teach PanelInventory how to open it.");
        }

        if (is_subclass_of($class, SimplePage::class) || ! is_subclass_of($class, Page::class)) {
            return null;
        }

        return $class::getUrl();
    }

    /**
     * The resource that shows a relation manager.
     *
     * @param  class-string  $manager
     * @return class-string<resource>
     */
    public static function owningResource(string $manager): string
    {
        return self::relationManagers()[$manager]
            ?? throw new LogicException("{$manager} is not a relation manager of any registered resource; register it in a resource or teach PanelInventory where it is shown.");
    }

    /**
     * The model class of the resource that owns a relation manager.
     *
     * @param  class-string  $manager
     * @return class-string<Model>
     */
    public static function ownerModel(string $manager): string
    {
        return self::owningResource($manager)::getModel();
    }

    /**
     * The page the relation manager is asked on: the view page of the owning resource, else its edit page.
     *
     * @param  class-string<resource>  $resource
     * @return class-string
     */
    public static function pageClassOf(string $resource): string
    {
        $pages = $resource::getPages();

        foreach (['view', 'edit'] as $name) {
            if (isset($pages[$name])) {
                return $pages[$name]->getPage();
            }
        }

        throw new LogicException("{$resource} has neither a view nor an edit page; teach PanelInventory which page its relation managers are asked on.");
    }

    /**
     * The visible canary row of a model: the one the CanaryRegistry fixture wrote for that canary. Found the
     * way the route walk finds it, always as system, because the scopes hide the row from a Partner. An
     * unknown model throws.
     *
     * @param  class-string<Model>  $model
     */
    public static function canaryRecord(string $model, string $canary): Model
    {
        return app(PartnerContext::class)->runAsSystem(static fn (): Model => match ($model) {
            Project::class => Project::query()->where('name', $canary)->firstOrFail(),
            Task::class => Task::query()->where('title', $canary)->firstOrFail(),
            TimeEntry::class => TimeEntry::query()->where('description', $canary)->firstOrFail(),
            Transaction::class => Transaction::query()->where('counterparty', $canary)->firstOrFail(),
            Invoice::class => Invoice::query()->where('note', $canary)->whereNotNull('number')->firstOrFail(),
            CardPayment::class => CardPayment::query()->where('resolution_note', $canary)->firstOrFail(),
            CanaryRecord::class => CanaryRecord::query()->where('secret', $canary)->withoutGlobalScopes()->firstOrFail(),
            Client::class => Client::query()->whereKey(Project::query()->where('name', $canary)->firstOrFail()->client_id)->firstOrFail(),
            default => throw new LogicException("PanelInventory::canaryRecord() does not know the model {$model}; teach it how the fixture finds the canary row of that model."),
        });
    }

    /**
     * Everything the admin panel registers, with the relation managers of every registered resource.
     *
     * @return list<class-string>
     */
    private static function registeredClasses(): array
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
                array_push($classes, ...self::flattenRelationManagers($class::getRelations()));
            }
        }

        return self::unique($classes);
    }

    /**
     * Relation managers with groups and configurations flattened.
     *
     * @param  array<mixed>  $relations
     * @return list<class-string>
     */
    private static function flattenRelationManagers(array $relations): array
    {
        $managers = [];

        foreach ($relations as $relation) {
            if ($relation instanceof RelationGroup) {
                array_push($managers, ...self::flattenRelationManagers($relation->getManagers()));
            } elseif ($relation instanceof RelationManagerConfiguration) {
                $managers[] = $relation->relationManager;
            } elseif (is_string($relation)) {
                $managers[] = $relation;
            }
        }

        /** @var list<class-string> $managers */
        return $managers;
    }

    /**
     * Whether a class under app/Filament needs a row: concrete, a subclass of a governed base, and not a plain
     * resource page unless it has a rule of its own.
     */
    private static function isGoverned(string $class): bool
    {
        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            return false;
        }

        if (is_subclass_of($class, ResourcePage::class)) {
            return AccessRules::for($class) !== null || in_array(EnforcesPageAccessRule::class, class_uses_recursive($class), true);
        }

        foreach ([Resource::class, Page::class, SimplePage::class, Widget::class, RelationManager::class, Cluster::class] as $base) {
            if (is_subclass_of($class, $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every class that exists under app/Filament.
     *
     * @return list<class-string>
     */
    private static function appFilamentClasses(): array
    {
        $root = app_path('Filament');

        if (! is_dir($root)) {
            return [];
        }

        $classes = [];
        $prefix = strlen(str_replace(DIRECTORY_SEPARATOR, '/', app_path())) + 1;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), $prefix, -4);
            $class = 'App\\'.str_replace('/', '\\', $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return self::unique($classes);
    }

    /**
     * @param  array<mixed>  $classes
     * @return list<class-string>
     */
    private static function unique(array $classes): array
    {
        /** @var list<class-string> $unique */
        $unique = array_values(array_unique(array_filter($classes, 'is_string')));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
