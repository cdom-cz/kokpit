<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\StoreGeneratedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\FinanceOverviewPage;
use App\Filament\Pages\TimeReportPage;
use App\Filament\Pages\TimesheetPage;
use App\Filament\RelationManagers\ActivityHistoryRelationManager;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\DocumentArchiveResource;
use App\Filament\Resources\ReportExportResource;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
     * The channels that have a driver here. Plan 12-07 adds notifications.
     *
     * @var list<string>
     */
    public const array DRIVEN = ['search', 'selects', 'api', 'activity', 'ui', 'downloads', 'exports'];

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

    /**
     * The entities a page of no resource shows a Partner, by route name. The Partner dashboard holds two table
     * widgets (PartnerTasks and PartnerProjects), so it shows tasks and projects and nothing else. A page that is
     * not listed here is attributed to every entity, so a new page that shows a canary fails every cell it does not
     * belong to until it is listed on purpose.
     *
     * @var array<string, list<class-string>>
     */
    private const array PAGE_OWNERS = [
        'filament.admin.pages.dashboard' => [Project::class, Task::class],
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
            'ui' => $this->uiBlob($model),
            'downloads' => $this->compose($this->downloadSegments(), $model),
            'exports' => $this->compose($this->exportSegments(), $model),
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

    /**
     * Everything Partner A can open in the panel for one entity, as a single string: the bodies of every GET
     * route of the admin panel and the rendered Partner list tables after a search for every needle. A response
     * is attributed to the entities it belongs to (see compose()).
     *
     * @param  class-string  $model
     */
    private function uiBlob(string $model): string
    {
        return $this->compose($this->uiSegments(), $model);
    }

    /**
     * Turns the attributed segments of a channel into the blob of one entity. Two entities can carry the same
     * canary text (a project name, a task title and a client name are all the canary), so the text alone cannot
     * say which entity a page shows; a response is attributed to the entities it belongs to (a resource page to the
     * model of the resource, a file route to the model of the file, a page of no resource to every entity) and only
     * the segments of the entity go into its blob. Two rules keep the attribution from hiding anything:
     * a needle or the id of client B anywhere in any segment puts every segment into the blob, because client B has
     * no legitimate trace in any response; and a needle of client A that differs from the canary text (a Stripe id,
     * a Monday, an owner id: it identifies its entity wherever it appears) is added when it appears anywhere.
     *
     * @param  list<array{owners: list<class-string>|null, text: string}>  $segments
     * @param  class-string  $model
     */
    private function compose(array $segments, string $model): string
    {
        $all = implode("\n", array_column($segments, 'text'));
        $lines = [];

        foreach ($segments as $segment) {
            if ($segment['owners'] === null || in_array($model, $segment['owners'], true)) {
                $lines[] = $segment['text'];
            }
        }

        foreach ([...$this->needles($model, 'b'), $this->clientB] as $foreign) {
            if (str_contains($all, $foreign)) {
                $lines[] = $all;

                break;
            }
        }

        foreach ($this->needles($model, 'a') as $needle) {
            if ($needle !== $this->canaryA && str_contains($all, $needle)) {
                $lines[] = $needle;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The UI walk as Partner A, once per test: every GET route of the panel (record routes with the client B
     * record and with the client A record, file routes with signed URLs for the files of both clients), then every
     * list page whose resource Partner A can access, mounted with Livewire and searched for every needle. A status
     * outside 200, 302, 403, 404 (never 419 or 500) fails with the route named, and so does a client B record
     * route that is not refused.
     *
     * @return list<array{owners: list<class-string>|null, text: string}>
     */
    private function uiSegments(): array
    {
        if (isset($this->memo['ui'])) {
            /** @var list<array{owners: list<class-string>|null, text: string}> $memoised */
            $memoised = $this->memo['ui'];

            return $memoised;
        }

        $test = test();
        $this->storeInvoicePdfs();
        $test->actingAs($this->partnerA);

        $resources = $this->walkResourceMap();
        $segments = [];
        $offenders = [];

        foreach ($this->walkRoutes() as $route) {
            $record = $this->walkHasRecord($route);
            $sides = $record || $this->walkIsFileRoute($route) ? ['b', 'a'] : ['a'];

            foreach ($sides as $side) {
                $url = $this->walkUrl($route, $record ? $this->walkRecordId($route, $resources, $side) : '', $side);
                $response = $test->get($url);
                $status = $response->getStatusCode();

                if (! in_array($status, [200, 302, 403, 404], true)) {
                    $offenders[] = "{$route->getName()} ({$side}) answered {$status}";
                }

                if ($side === 'b' && ($record || $this->walkIsFileRoute($route)) && ! in_array($status, [403, 404], true)) {
                    $offenders[] = "{$route->getName()} did not refuse the client B record, it answered {$status}";
                }

                $segments[] = ['owners' => $this->walkOwners($route), 'text' => $this->describe($response)];
            }
        }

        Assert::assertSame([], $offenders, "The panel answered Partner A unexpectedly:\n".implode("\n", $offenders));

        $this->actAs($this->partnerA);
        array_push($segments, ...$this->walkLists());

        return $this->memo['ui'] = $segments;
    }

    /**
     * Every list page whose resource Partner A can access, mounted with Livewire and searched for every needle of
     * both sides and for the common prefix; the rendered table of each search goes into the segment of the model
     * of the resource. The one echo of the search term (the active search badge, "Search: term") is removed from the
     * HTML, otherwise every searched needle would read as a hit; a row that shows the term is not touched.
     *
     * @return list<array{owners: list<class-string>|null, text: string}>
     */
    private function walkLists(): array
    {
        $segments = [];
        $terms = $this->searchTerms();
        $indicator = __('filament-tables::table.fields.search.indicator').': ';

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $pages = $resource::getPages();

            if (! $resource::canAccess() || ! isset($pages['index'])) {
                continue;
            }

            $component = Livewire::test($pages['index']->getPage());
            $html = [$component->html(stripInitialData: true)];

            foreach ($terms as $term) {
                $component->searchTable($term);
                $html[] = str_replace($indicator.$term, '', $component->html(stripInitialData: true));
            }

            $segments[] = ['owners' => $this->ownersOfModel($resource::getModel()), 'text' => implode("\n", $html)];
        }

        return $segments;
    }

    /**
     * The entities a response of a route belongs to: the model of a resource route, the model of a file route, the
     * entities listed in PAGE_OWNERS for a page of no resource; null (every entity) for any other page, so that a
     * page cannot show a canary unattributed and pass.
     *
     * @return list<class-string>|null
     */
    private function walkOwners(LaravelRoute $route): ?array
    {
        $name = (string) $route->getName();

        if ($name === DocumentUrl::ROUTE) {
            return [Document::class];
        }

        if ($name === DocumentUrl::ARCHIVE_ROUTE) {
            return [DocumentArchive::class];
        }

        if ($name === InvoicePdfUrl::ROUTE) {
            return [Invoice::class, InvoiceItem::class];
        }

        $slug = $this->walkResourceSlug($route);

        if ($slug === null) {
            return self::PAGE_OWNERS[$name] ?? null;
        }

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if ($resource::getSlug() === $slug) {
                return $this->ownersOfModel($resource::getModel());
            }
        }

        throw new LogicException("The panel has no resource with the slug {$slug} (route {$name}).");
    }

    /**
     * The attribution of a resource model: the model itself when it is a registered entity, else null (every
     * entity), so a resource that shows an unregistered model is checked against every cell.
     *
     * @param  class-string  $model
     * @return list<class-string>|null
     */
    private function ownersOfModel(string $model): ?array
    {
        return array_key_exists($model, CanaryRegistry::fixtures()) ? [$model] : null;
    }

    /**
     * A response as text: the Content-Disposition header when there is one, and the body, streamed or sent as a
     * file included.
     */
    private function describe(TestResponse $response): string
    {
        $base = $response->baseResponse;
        $body = match (true) {
            $base instanceof StreamedResponse => $response->streamedContent(),
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            default => (string) $response->getContent(),
        };
        $disposition = $response->headers->get('Content-Disposition');

        return ($disposition === null ? '' : "Content-Disposition: {$disposition}\n").$body;
    }

    /**
     * Every GET route of the admin panel except the guest-only authentication routes (login, which redirects a
     * signed-in user, and the logout POST); copied from RouteWalkTest (D-08), sorted by route name.
     *
     * @return list<LaravelRoute>
     */
    private function walkRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName() ?? '';

            if (! str_starts_with($name, 'filament.admin.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($name, ['filament.admin.auth.login', 'filament.admin.auth.logout'], true)) {
                continue;
            }

            $routes[] = $route;
        }

        usort($routes, static fn (LaravelRoute $a, LaravelRoute $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

        return $routes;
    }

    /**
     * The URL of a walked route for the signed-in user. A route with a {record} parameter gets the given record id;
     * the file route (a {document} parameter) gets the visible canary document of the side, the archive route (an
     * {archive} parameter) the canary archive, the invoice PDF route (an {invoice} parameter) the issued canary
     * invoice, all signed for the signed-in user, so that the walk reaches the controller. Any other parameter
     * fails the walk, so a new kind of route is looked at on purpose.
     */
    public function walkUrl(LaravelRoute $route, string $recordId, string $side = 'a'): string
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            if ($parameter === 'document' && $route->getName() === DocumentUrl::ROUTE) {
                return DocumentUrl::download($this->walkDocument($side), $this->walkUser());
            }

            if ($parameter === 'archive' && $route->getName() === DocumentUrl::ARCHIVE_ROUTE) {
                return DocumentUrl::archive($this->walkArchive($side), $this->walkUser());
            }

            if ($parameter === 'invoice' && $route->getName() === InvoicePdfUrl::ROUTE) {
                return InvoicePdfUrl::download($this->walkInvoice($side), $this->walkUser());
            }

            if ($parameter !== 'record') {
                throw new LogicException("The route walk does not know the parameter [{$parameter}] of route [{$route->getName()}]; teach it how to fill it.");
            }

            $parameters['record'] = $recordId;
        }

        return route((string) $route->getName(), $parameters);
    }

    private function walkUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('The route walk signs file routes for the signed-in user; sign in before walking.');
        }

        return $user;
    }

    private function walkIsFileRoute(LaravelRoute $route): bool
    {
        return in_array($route->getName(), [DocumentUrl::ROUTE, DocumentUrl::ARCHIVE_ROUTE, InvoicePdfUrl::ROUTE], true);
    }

    private function walkHasRecord(LaravelRoute $route): bool
    {
        return in_array('record', $route->parameterNames(), true);
    }

    /**
     * The visible canary document of a side: the file the Document fixture attaches under the canary name (the
     * hidden twin and the other files carry a suffix).
     */
    private function walkDocument(string $side, string $suffix = ''): Document
    {
        $name = $this->canary($side).$suffix.'.txt';

        return app(PartnerContext::class)->runAsSystem(
            static fn (): Document => Document::query()->where('name', $name)->firstOrFail(),
        );
    }

    /**
     * The issued canary invoice of a side: the one with a number, found by its printed note (the draft twin has none).
     */
    private function walkInvoice(string $side): Invoice
    {
        $canary = $this->canary($side);

        return app(PartnerContext::class)->runAsSystem(
            static fn (): Invoice => Invoice::query()->where('note', $canary)->whereNotNull('number')->firstOrFail(),
        );
    }

    /**
     * The canary ZIP archive of a side: the fixture row whose summary is the canary.
     */
    private function walkArchive(string $side): DocumentArchive
    {
        $canary = $this->canary($side);

        return app(PartnerContext::class)->runAsSystem(
            static fn (): DocumentArchive => DocumentArchive::query()->where('summary', $canary)->firstOrFail(),
        );
    }

    /**
     * Stores a fictional PDF for the issued canary invoice of each side and links it from the Admin-only row, the way
     * issuing does, so that the invoice PDF route can be served; once per test. Returns the bytes by side.
     *
     * @return array<string, string>
     */
    public function storeInvoicePdfs(): array
    {
        if (isset($this->memo['pdfs'])) {
            /** @var array<string, string> $memoised */
            $memoised = $this->memo['pdfs'];

            return $memoised;
        }

        $bytes = [];

        foreach (['a', 'b'] as $side) {
            $invoice = $this->walkInvoice($side);
            $bytes[$side] = implode("\n", ['%PDF-1.4', '%fictional '.Str::random(8), '%%EOF', '']);

            app(PartnerContext::class)->runAsSystem(function () use ($invoice, $side, $bytes): void {
                $document = app(StoreGeneratedDocument::class)->handle($this->admin, $invoice, $bytes[$side], 'faktura-fictional.pdf');

                InvoiceAdmin::query()->where('invoice_id', $invoice->getKey())->firstOrFail()
                    ->forceFill(['pdf_document_id' => $document->getKey()])->save();
            });
        }

        return $this->memo['pdfs'] = $bytes;
    }

    /**
     * Every resource that has record routes: who may open its record routes and the record id to request for each
     * side (client A is the signed-in Partner, client B the other client). The walk fails on a resource that is not
     * listed, so a new resource is looked at on purpose; copied from RouteWalkTest (D-08).
     *
     * @return array<string, array{partner: bool, a: string, b: string}>
     */
    private function walkResourceMap(): array
    {
        $find = fn (string $side): array => app(PartnerContext::class)->runAsSystem(function () use ($side): array {
            $canary = $this->canary($side);
            $project = Project::query()->where('name', $canary)->firstOrFail();
            $task = (string) Task::query()->where('title', $canary)->firstOrFail()->reference;
            $invoice = (string) Invoice::query()->where('note', $canary)->whereNotNull('number')->firstOrFail()->getKey();

            return [
                'record' => (string) CanaryRecord::query()->where('secret', $canary)->withoutGlobalScopes()->firstOrFail()->getKey(),
                'project' => (string) $project->getKey(),
                'client' => (string) $project->client_id,
                'task' => $task,
                'entry' => (string) TimeEntry::query()->where('description', $canary)->firstOrFail()->getKey(),
                'transaction' => (string) Transaction::query()->where('counterparty', $canary)->firstOrFail()->getKey(),
                'invoice' => $invoice,
                'card' => (string) CardPayment::query()->where('resolution_note', $canary)->firstOrFail()->getKey(),
            ];
        });
        $a = $find('a');
        $b = $find('b');
        $row = static fn (bool $partner, string $key): array => ['partner' => $partner, 'a' => $a[$key], 'b' => $b[$key]];

        return [
            'canary-records' => $row(true, 'record'),
            'my-projects' => $row(true, 'project'),
            'projects' => $row(false, 'project'),
            'clients' => $row(false, 'client'),
            'tasks' => $row(false, 'task'),
            'time-entries' => $row(false, 'entry'),
            'transactions' => $row(false, 'transaction'),
            'invoices' => $row(false, 'invoice'),
            'card-payments' => $row(false, 'card'),
            'my-invoices' => $row(true, 'invoice'),
            'my-tasks' => $row(true, 'task'),
        ];
    }

    /**
     * The resource slug of a route name such as filament.admin.resources.my-projects.view, or null for a route that
     * does not belong to a resource.
     */
    private function walkResourceSlug(LaravelRoute $route): ?string
    {
        $name = (string) $route->getName();
        $prefix = 'filament.admin.resources.';

        if (! str_starts_with($name, $prefix)) {
            return null;
        }

        $rest = substr($name, strlen($prefix));

        return substr($rest, 0, (int) strrpos($rest, '.'));
    }

    /**
     * The record id to request on a record route for the given side ('a' or 'b'). An unknown resource throws, like an
     * unknown route parameter does.
     *
     * @param  array<string, array{partner: bool, a: string, b: string}>  $resources
     */
    public function walkRecordId(LaravelRoute $route, array $resources, string $side): string
    {
        $slug = $this->walkResourceSlug($route);

        if ($slug === null || ! isset($resources[$slug])) {
            throw new LogicException("The route walk does not know the resource of route [{$route->getName()}]; teach walkResourceMap() who may open it and which record ids to request.");
        }

        return $resources[$slug][$side];
    }

    /**
     * The file routes as Partner A, once per test: the signed document link of the client A visible file, of its
     * hidden twin and of the client B visible file, the signed archive link of both canary archives, the signed
     * invoice PDF link of both issued canary invoices (a fictional PDF is stored for each first), and the report
     * export list mounted with Livewire. The own visible file and the own invoice PDF are served; everything else
     * must be refused with 403 or 404, so the statuses are asserted here and the bodies and Content-Disposition
     * headers go into the segments of the entity the file belongs to.
     *
     * @return list<array{owners: list<class-string>|null, text: string}>
     */
    private function downloadSegments(): array
    {
        if (isset($this->memo['downloads'])) {
            /** @var list<array{owners: list<class-string>|null, text: string}> $memoised */
            $memoised = $this->memo['downloads'];

            return $memoised;
        }

        $test = test();
        $this->storeInvoicePdfs();
        $this->storeArchives();
        $test->actingAs($this->partnerA);

        $urls = $this->downloadUrls($this->partnerA);
        $hidden = DocumentUrl::download($this->walkDocument('a', '-hidden'), $this->partnerA);
        $requests = [
            ['document a', $urls['document a'], [Document::class], true],
            ['document a (hidden twin)', $hidden, [Document::class], false],
            ['document b', $urls['document b'], [Document::class], false],
            ['archive a', $urls['archive a'], [DocumentArchive::class], false],
            ['archive b', $urls['archive b'], [DocumentArchive::class], false],
            ['invoice pdf a', $urls['invoice a'], [Invoice::class, InvoiceItem::class], true],
            ['invoice pdf b', $urls['invoice b'], [Invoice::class, InvoiceItem::class], false],
        ];
        $segments = [];
        $offenders = [];

        foreach ($requests as [$label, $url, $owners, $served]) {
            $response = $test->get($url);
            $status = $response->getStatusCode();

            if ($served ? $status !== 200 : ! in_array($status, [403, 404], true)) {
                $offenders[] = "{$label} answered {$status}, expected ".($served ? '200' : '403 or 404');
            }

            $segments[] = ['owners' => $owners, 'text' => $this->describe($response)];
        }

        $exports = Livewire::test(ReportExportResource::getPages()['index']->getPage());

        try {
            $exports->assertForbidden();
        } catch (\Throwable $e) {
            $offenders[] = 'the report export list (and so its download action) did not refuse Partner A: '.$e->getMessage();
        }

        $segments[] = ['owners' => [ReportExport::class], 'text' => 'report export list refused'];

        Assert::assertSame([], $offenders, "A file route answered Partner A unexpectedly:\n".implode("\n", $offenders));

        return $this->memo['downloads'] = $segments;
    }

    /**
     * The signed URLs of the canary files for one user, keyed "{kind} {side}": the visible document, the canary
     * archive and the issued invoice's PDF of each side. A control signs them for the Admin.
     *
     * @return array<string, string>
     */
    public function downloadUrls(User $user): array
    {
        $urls = [];

        foreach (['a', 'b'] as $side) {
            $urls["document {$side}"] = DocumentUrl::download($this->walkDocument($side), $user);
            $urls["archive {$side}"] = DocumentUrl::archive($this->walkArchive($side), $user);
            $urls["invoice {$side}"] = InvoicePdfUrl::download($this->walkInvoice($side), $user);
        }

        return $urls;
    }

    /**
     * Writes bytes for the two canary archives, so that a refusal of the archive route comes from the owner scope
     * and not from a missing file; once per test.
     */
    public function storeArchives(): void
    {
        if (isset($this->memo['archives'])) {
            return;
        }

        foreach (['a', 'b'] as $side) {
            Storage::disk()->put((string) $this->walkArchive($side)->path, 'Fictional archive bytes');
        }

        $this->memo['archives'] = true;
    }

    /**
     * The export paths as Partner A, once per test: the URL of every page and resource that produces or lists an
     * export (the time report, the report export list, the document archive list, the finance overview and the
     * timesheet) must answer 403; TimeReportPage mounted with Livewire must be refused; the two closed Filament
     * export and import download routes, requested with fresh ids, must answer their declared status (404, plan
     * 12-02). Every answer goes into every blob: the answers are refusals, so no entity may show in any of them.
     *
     * @return list<array{owners: list<class-string>|null, text: string}>
     */
    private function exportSegments(): array
    {
        if (isset($this->memo['exports'])) {
            /** @var list<array{owners: list<class-string>|null, text: string}> $memoised */
            $memoised = $this->memo['exports'];

            return $memoised;
        }

        $test = test();
        $test->actingAs($this->partnerA);

        $segments = [];
        $offenders = [];

        foreach ($this->exportUrls() as $label => $url) {
            $response = $test->get($url);

            if ($response->getStatusCode() !== 403) {
                $offenders[] = "{$label} answered {$response->getStatusCode()}, expected 403";
            }

            $segments[] = ['owners' => null, 'text' => $this->describe($response)];
        }

        try {
            Livewire::test(TimeReportPage::class)->assertForbidden();
        } catch (\Throwable $e) {
            $offenders[] = 'TimeReportPage mounted with Livewire did not refuse Partner A: '.$e->getMessage();
        }

        foreach (RouteRules::closedRoutes() as $key => $rule) {
            $route = Route::getRoutes()->getByName($key)
                ?? throw new LogicException("The closed route {$key} of RouteRules is not registered.");
            $response = $test->get(RouteInventory::path($route));

            if ($response->getStatusCode() !== $rule['status']) {
                $offenders[] = "{$key} answered {$response->getStatusCode()}, expected {$rule['status']}";
            }

            $segments[] = ['owners' => null, 'text' => $this->describe($response)];
        }

        Assert::assertSame([], $offenders, "An export path answered Partner A unexpectedly:\n".implode("\n", $offenders));

        return $this->memo['exports'] = $segments;
    }

    /**
     * The URLs of the pages and resources that produce or list an export, keyed by a label.
     *
     * @return array<string, string>
     */
    public function exportUrls(): array
    {
        return [
            'time report' => TimeReportPage::getUrl(),
            'report exports' => ReportExportResource::getUrl('index'),
            'document archives' => DocumentArchiveResource::getUrl('index'),
            'finance overview' => FinanceOverviewPage::getUrl(),
            'timesheet' => TimesheetPage::getUrl(),
        ];
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
