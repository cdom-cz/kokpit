<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\RelationManagers\ActivityHistoryRelationManager;
use App\Filament\Resources\ActivityResource;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Widgets\TableWidget;
use FilesystemIterator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Support\Filament\CanaryRecordResource;

/**
 * Drives the leak channels of the entity x channel matrix as Partner A (AUD-01; D-07, D-12; R-12-05-2).
 *
 * seed() builds the two-client scene: two clients, one canary string for each, the fixtures of every registered
 * entity for both (CanaryRegistry::seedAll), Partner A (a Partner of client A) and the Admin that owns the
 * planner rows. blob($channel, $model) then returns everything Partner A can obtain through one channel for one
 * entity as a single string, which ChannelMatrixTest searches for the needles of client B and, for a `none`
 * cell, of client A. A channel without a driver throws, so a channel listed in ChannelMatrix but not in DRIVEN
 * is visibly not walked yet and never silently passes.
 *
 * The test calls CanaryRegistry::cleanup() in afterEach; the scene lives inside the test transaction.
 */
final class ChannelProbe
{
    /**
     * The channels that have a driver here. Plans 12-06 and 12-07 add ui, downloads, exports and notifications.
     *
     * @var list<string>
     */
    public const array DRIVEN = ['search', 'selects', 'api', 'activity'];

    /**
     * The prefix every canary string starts with: a search for it reaches the rows of both clients.
     */
    private const string COMMON_PREFIX = 'CANARY';

    /**
     * The model each path parameter of an api/v1 route stands for (the parameters that have a canary row).
     *
     * @var array<string, class-string>
     */
    private const array API_PARAMETER_MODELS = [
        'timeEntry' => TimeEntry::class,
        'client' => Client::class,
        'project' => Project::class,
        'task' => Task::class,
        'transaction' => Transaction::class,
        'category' => TransactionCategory::class,
    ];

    /** @var array<string, mixed> memo of the per-test results that do not depend on the entity */
    private array $memo = [];

    private function __construct(
        private readonly string $clientA,
        private readonly string $clientB,
        private readonly string $canaryA,
        private readonly string $canaryB,
        private readonly User $partnerA,
        private readonly User $admin,
    ) {}

    /**
     * Sets the admin panel, prepares the registry, creates two clients with one canary each, seeds every
     * registered entity for both, and creates Partner A and the Admin.
     */
    public static function seed(): self
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CanaryRegistry::prepare();

        [$clientA, $clientB] = Canary::twoClients();
        $canaryA = Canary::canary('channel_a');
        $canaryB = Canary::canary('channel_b');

        CanaryRegistry::seedAll($clientA, $canaryA);
        CanaryRegistry::seedAll($clientB, $canaryB);

        return new self($clientA, $clientB, $canaryA, $canaryB, Canary::partnerFor($clientA), CanaryRegistry::plannerOwner());
    }

    public function clientId(string $side): string
    {
        return $this->side($side, $this->clientA, $this->clientB);
    }

    public function canary(string $side): string
    {
        return $this->side($side, $this->canaryA, $this->canaryB);
    }

    /**
     * The needles of one entity for one side: the strings that identify its canary row in any output.
     *
     * @param  class-string  $model
     * @return list<string>
     */
    public function needles(string $model, string $side): array
    {
        return CanaryRegistry::needles($model, $this->canary($side));
    }

    public function partnerA(): User
    {
        return $this->partnerA;
    }

    public function admin(): User
    {
        return $this->admin;
    }

    /**
     * Signs the user in on the web guard, the way the test case's actingAs() does.
     */
    public function actAs(User $user): void
    {
        Auth::guard('web')->setUser($user);
        Auth::shouldUse('web');
    }

    /**
     * Everything Partner A can obtain through the channel for the entity, as one string.
     *
     * @param  class-string  $model
     */
    public function blob(string $channel, string $model): string
    {
        $this->actAs($this->partnerA);

        return match ($channel) {
            'search' => $this->searchBlob($model),
            'selects' => $this->selectsBlob($model),
            'api' => $this->apiBlob($model),
            'activity' => $this->activityBlob(),
            default => throw new LogicException("The channel {$channel} has no driver in ChannelProbe yet; it is not in ChannelProbe::DRIVEN."),
        };
    }

    /**
     * Partner A's global search for each needle of both sides and for the common canary prefix, as the text of
     * the results that belong to the entity: the resources of the entity's model, their category title, result
     * title, details and URL. A result is attributed to the model of the resource that produced it, because two
     * entities can carry the same canary text (a project name and a task title), so the canary text alone cannot
     * tell which entity a result shows (R-12-05-3).
     *
     * @param  class-string  $model
     */
    private function searchBlob(string $model): string
    {
        $terms = array_values(array_unique([
            ...$this->needles($model, 'a'),
            ...$this->needles($model, 'b'),
            self::COMMON_PREFIX,
        ]));
        $lines = [];

        foreach ($terms as $term) {
            array_push($lines, ...($this->search($this->partnerA, $term)[$model] ?? []));
        }

        return implode("\n", $lines);
    }

    /**
     * What the global search of the panel shows a user for one term, as text lines grouped by the model of the
     * resource that produced them. The panel's provider is the real channel; the test-only canary resource does
     * not opt in to global search, so it is asked directly, the way RouteWalkTest does, because it is the one
     * Partner-readable resource that carries nothing but the canary.
     *
     * @return array<class-string, list<string>>
     */
    public function search(User $user, string $term): array
    {
        $key = 'search:'.$user->getKey().':'.$term;

        if (isset($this->memo[$key])) {
            /** @var array<class-string, list<string>> $memoised */
            $memoised = $this->memo[$key];

            return $memoised;
        }

        $this->actAs($user);

        $byModel = [];
        $results = Filament::getGlobalSearchProvider()?->getResults($term);

        if ($results instanceof GlobalSearchResults) {
            foreach ($results->getCategories() as $category => $items) {
                foreach ($this->modelsOfCategory((string) $category) as $model) {
                    $byModel[$model][] = (string) $category;

                    foreach ($items as $item) {
                        array_push($byModel[$model], ...$this->resultLines($item));
                    }
                }
            }
        }

        if (config('kokpit.canary_harness') === true && class_exists(CanaryRecordResource::class)) {
            foreach (CanaryRecordResource::getGlobalSearchResults($term) as $item) {
                $byModel[CanaryRecord::class] ??= [];
                array_push($byModel[CanaryRecord::class], ...$this->resultLines($item));
            }
        }

        return $this->memo[$key] = $byModel;
    }

    /**
     * The models of the panel resources whose plural label is the category title (the provider keys a category
     * by that label).
     *
     * @return list<class-string>
     */
    private function modelsOfCategory(string $category): array
    {
        $models = [];

        foreach (Filament::getResources() as $resource) {
            if ($resource::getPluralModelLabel() === $category) {
                $models[] = $resource::getModel();
            }
        }

        if ($models === []) {
            throw new LogicException("The global search category {$category} belongs to no resource of the panel.");
        }

        return array_values(array_unique($models));
    }

    /**
     * @return list<string>
     */
    private function resultLines(GlobalSearchResult $item): array
    {
        $lines = [$this->text($item->title), $item->url];

        foreach ($item->details as $label => $value) {
            $lines[] = $label.' '.$value;
        }

        return $lines;
    }

    /**
     * The REST API as Partner A: every route under api/v1/ is requested with a Partner token that carries every
     * ability and, in a second pass, without a token; a route with a path parameter whose model has a canary row is
     * requested once more with the real key of the client A row and of the client B row. Every answer must be
     * 401 (the API is Admin-only), and every body goes into the blob. A route with a parameter of another model
     * is not part of the blob of this entity, because the answers of a route belong to the model it serves.
     *
     * @param  class-string  $model
     */
    private function apiBlob(string $model): string
    {
        $test = test();
        $token = ApiFixtures::token($this->partnerA, ApiAbility::values());
        $keys = $this->apiRecordKeys();
        $bodies = [];
        $offenders = [];

        foreach (RouteInventory::routes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $parameters = $this->routeParameters($route);

            if ($parameters === []) {
                $paths = [RouteInventory::path($route)];
            } elseif (count($parameters) === 1 && isset(self::API_PARAMETER_MODELS[$parameters[0]]) && isset($keys[$parameters[0]])) {
                if (self::API_PARAMETER_MODELS[$parameters[0]] !== $model) {
                    continue;
                }

                $paths = array_map(
                    static fn (string $side): string => '/'.preg_replace('/\{[^}]+\}/', $keys[$parameters[0]][$side], $route->uri()),
                    ['a', 'b'],
                );
            } else {
                continue;
            }

            foreach ($paths as $path) {
                foreach (['partner token', 'no token'] as $pass) {
                    Auth::forgetGuards();
                    $test->flushHeaders();

                    $response = $pass === 'partner token'
                        ? $test->withToken($token)->json(RouteInventory::method($route), $path)
                        : $test->json(RouteInventory::method($route), $path);

                    $bodies[] = (string) $response->getContent();

                    if ($response->getStatusCode() !== 401) {
                        $offenders[] = RouteInventory::method($route).' '.$path.' with '.$pass.' answered '.$response->getStatusCode().' instead of 401';
                    }
                }
            }
        }

        $test->flushHeaders();
        Auth::forgetGuards();

        Assert::assertSame([], $offenders, "The API answered Partner A something other than 401:\n".implode("\n", $offenders));

        return implode("\n", $bodies);
    }

    /**
     * The path parameters of a route, in order.
     *
     * @return list<string>
     */
    private function routeParameters(LaravelRoute $route): array
    {
        return array_values($route->parameterNames());
    }

    /**
     * The real key of the canary row of each side for the models the API addresses by a path parameter, found the
     * way RouteWalkTest finds them (a time entry by description, a project by name, a task by title and
     * reference, a transaction by counterparty, a category by name). The client key is the client id.
     *
     * @return array<string, array{a: string, b: string}>
     */
    private function apiRecordKeys(): array
    {
        $find = fn (string $side): array => app(PartnerContext::class)->runAsSystem(fn (): array => [
            'timeEntry' => (string) TimeEntry::query()->where('description', $this->canary($side))->firstOrFail()->getKey(),
            'client' => $this->clientId($side),
            'project' => (string) Project::query()->where('name', $this->canary($side))->firstOrFail()->getKey(),
            'task' => (string) Task::query()->where('title', $this->canary($side))->firstOrFail()->reference,
            'transaction' => (string) Transaction::query()->where('counterparty', $this->canary($side))->firstOrFail()->getKey(),
            'category' => (string) TransactionCategory::query()->where('name', $this->canary($side))->firstOrFail()->getKey(),
        ]);
        $a = $find('a');
        $b = $find('b');
        $keys = [];

        foreach ($a as $parameter => $key) {
            $keys[$parameter] = ['a' => $key, 'b' => $b[$parameter]];
        }

        return $keys;
    }

    /**
     * The activity log as Partner A: the raw attributes of every row of the scoped Activity query (expected none);
     * every concrete history relation manager under app/Filament must refuse Partner A on the client A canary row
     * of its owning model; and the Activity resource index is requested, its body going into the blob. The log is
     * the same for every entity, so the blob does not depend on the model.
     */
    private function activityBlob(): string
    {
        $test = test();
        $rows = Activity::query()->get()->map(static fn (Activity $row): array => $row->getAttributes())->all();
        $offenders = [];

        foreach ($this->historyManagers() as $manager) {
            $owner = PanelInventory::canaryRecord(PanelInventory::ownerModel($manager), $this->canaryA);

            if ($manager::canViewForRecord($owner, PanelInventory::pageClassOf(PanelInventory::owningResource($manager)))) {
                $offenders[] = "{$manager} admits Partner A on the client A canary row of ".$owner::class;
            }
        }

        Assert::assertSame([], $offenders, "A history relation manager admitted Partner A:\n".implode("\n", $offenders));

        $this->actAs($this->partnerA);
        $response = $test->get(ActivityResource::getUrl('index'));

        Assert::assertContains($response->getStatusCode(), [403, 404], 'The Activity resource index must refuse Partner A, answered '.$response->getStatusCode());

        return json_encode($rows, JSON_THROW_ON_ERROR)."\n".(string) $response->getContent();
    }

    /**
     * Every concrete subclass of ActivityHistoryRelationManager under app/Filament, found by scanning the directory
     * (not only the ones a resource registers), sorted by class name.
     *
     * @return list<class-string<ActivityHistoryRelationManager>>
     */
    private function historyManagers(): array
    {
        $root = app_path('Filament');
        $managers = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), strlen(str_replace(DIRECTORY_SEPARATOR, '/', $root)) + 1, -4);
            $class = 'App\\Filament\\'.str_replace('/', '\\', $relative);

            if (class_exists($class) && is_subclass_of($class, ActivityHistoryRelationManager::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $managers[] = $class;
            }
        }

        sort($managers, SORT_STRING);

        return $managers;
    }

    /**
     * The selects of the panel as Partner A: the options (and, for a searchable select, the search results for every
     * needle of every entity and for the common prefix) of every select and select filter that Partner A can open.
     * The text of an option is "key label". An option whose key is the primary key of a row of a registered entity
     * belongs to that entity, because two entities can carry the same canary text (a project name and a task
     * title), so the text alone cannot say which entity an option shows (R-12-05-3); an option of no known entity
     * belongs to every entity, so a canary in an option nobody can attribute fails every row.
     *
     * @param  class-string  $model
     */
    private function selectsBlob(string $model): string
    {
        $lines = [];

        foreach ($this->selectOptions() as $option) {
            if ($option['owner'] === null || $option['owner'] === $model) {
                $lines[] = $option['text'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Every option Partner A can reach through a select, once per test.
     *
     * @return list<array{owner: class-string|null, text: string}>
     */
    private function selectOptions(): array
    {
        if (isset($this->memo['selects'])) {
            /** @var list<array{owner: class-string|null, text: string}> $memoised */
            $memoised = $this->memo['selects'];

            return $memoised;
        }

        $this->actAs($this->partnerA);

        $terms = $this->searchTerms();
        $owners = $this->keyOwners();
        $options = [];

        foreach ($this->reachableSelects() as $select) {
            $found = $this->flattenOptions($select->getOptions());

            if ($select->isSearchable()) {
                foreach ($terms as $term) {
                    $found += $this->flattenOptions($select->getSearchResults($term));
                }
            }

            foreach ($found as $key => $label) {
                $options[] = ['owner' => $owners[(string) $key] ?? null, 'text' => $key.' '.$label];
            }
        }

        return $this->memo['selects'] = $options;
    }

    /**
     * Every select Partner A can open: the select filters of the list page of every resource Partner A may access,
     * the form selects of its create page when Partner A may create, the same for every relation manager of those
     * resources mounted on the client A canary row of the owning model, and the filters of every table widget Partner
     * A may see.
     *
     * @return list<Select>
     */
    private function reachableSelects(): array
    {
        $selects = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! $resource::canAccess()) {
                continue;
            }

            $pages = $resource::getPages();

            if (isset($pages['index'])) {
                array_push($selects, ...$this->filterSelects(Livewire::test($pages['index']->getPage())->instance()));
            }

            if (isset($pages['create']) && $resource::canCreate()) {
                $form = Livewire::test($pages['create']->getPage())->instance()->getSchema('form');

                array_push($selects, ...($form instanceof Schema ? $this->selectsOf($form) : []));
            }

            foreach (PanelInventory::relationManagers() as $manager => $owningResource) {
                if ($owningResource !== $resource) {
                    continue;
                }

                $owner = PanelInventory::canaryRecord($resource::getModel(), $this->canaryA);
                $pageClass = PanelInventory::pageClassOf($resource);

                if ($manager::canViewForRecord($owner, $pageClass)) {
                    array_push($selects, ...$this->filterSelects(Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $pageClass])->instance()));
                }
            }
        }

        foreach (PanelInventory::panelClasses() as $class) {
            if (is_subclass_of($class, TableWidget::class) && $class::canView()) {
                array_push($selects, ...$this->filterSelects(Livewire::test($class)->instance()));
            }
        }

        return $selects;
    }

    /**
     * The selects in the filter form of a table component; none for a component without a table.
     *
     * @return list<Select>
     */
    private function filterSelects(object $component): array
    {
        return $component instanceof HasTable ? $this->selectsOf($component->getTableFiltersForm()) : [];
    }

    /**
     * @return list<Select>
     */
    private function selectsOf(Schema $schema): array
    {
        return array_values(array_filter($schema->getFlatFields(withHidden: true), static fn (mixed $field): bool => $field instanceof Select));
    }

    /**
     * The options of a select as key => label, grouped options flattened.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, string>
     */
    private function flattenOptions(array $options): array
    {
        $flat = [];

        foreach ($options as $key => $label) {
            if (is_array($label)) {
                $flat += $this->flattenOptions($label);

                continue;
            }

            $flat[(string) $key] = $label instanceof Htmlable ? $label->toHtml() : (string) $label;
        }

        return $flat;
    }

    /**
     * Every needle of every entity for both sides, and the common prefix: the terms a searchable select is asked.
     *
     * @return list<string>
     */
    private function searchTerms(): array
    {
        $terms = [self::COMMON_PREFIX];

        foreach (array_keys(CanaryRegistry::fixtures()) as $model) {
            foreach (['a', 'b'] as $side) {
                array_push($terms, ...$this->needles($model, $side));
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Primary key => model for the rows of every registered entity, so an option can be attributed to the entity it
     * shows. Read as system with the scopes off: it is a lookup of the test, not a channel.
     *
     * @return array<string, class-string>
     */
    private function keyOwners(): array
    {
        return app(PartnerContext::class)->runAsSystem(function (): array {
            $owners = [];

            foreach (array_keys(CanaryRegistry::fixtures()) as $model) {
                /** @var Model $instance */
                $instance = new $model;

                foreach ($model::query()->withoutGlobalScopes()->pluck($instance->getKeyName()) as $key) {
                    $owners[(string) $key] ??= $model;
                }
            }

            return $owners;
        });
    }

    private function text(string|Htmlable $value): string
    {
        return $value instanceof Htmlable ? $value->toHtml() : $value;
    }

    private function side(string $side, string $a, string $b): string
    {
        return match ($side) {
            'a' => $a,
            'b' => $b,
            default => throw new LogicException("A side is 'a' or 'b', got {$side}."),
        };
    }
}
