<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Database\AccentInsensitiveSearch;
use App\Domain\Signal\Queries\LinkableTasks;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Concerns\SearchesGloballyWithoutAccents;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TaskResource;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\Support\Canary;

/*
 * Search that ignores diacritics and letter case, on the real PostgreSQL (CONTRIBUTING "Search"): the database
 * pieces of the 2026_10_30 migration, the index use through EXPLAIN, the rollback, the Signal type-ahead, the Filament
 * tables, the global search and the guards that keep the one mechanism. Every name and key is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * The migration of this feature, loaded the way the migrator and the schema tests load one.
 */
function accSearchMigration(): object
{
    return require base_path('database/migrations/2026_10_30_000100_add_accent_insensitive_search.php');
}

function accSearchProject(string $key = 'ATL', string $name = 'Example Atlas'): Project
{
    return Project::factory()->create(['key' => $key, 'name' => $name]);
}

function accSearchTask(Project $project, string $title): Task
{
    return Task::factory()->create(['project_id' => $project->id, 'title' => $title]);
}

/**
 * The references the Signal type-ahead offers for a term.
 *
 * @return list<string>
 */
function accSearchOffered(string $term): array
{
    return array_column((new LinkableTasks)->search($term), 'reference');
}

/**
 * The EXPLAIN text of a query with sequential and plain index scans switched off, so a usable GIN index shows as a
 * Bitmap Index Scan even on a tiny table. SET LOCAL lasts only until the end of the test transaction.
 *
 * @param  Builder<*>  $query
 */
function accSearchPlan(Builder $query): string
{
    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_indexscan = off');
    DB::statement('set local enable_indexonlyscan = off');

    $lines = [];

    foreach (DB::select('explain '.$query->toRawSql()) as $row) {
        $lines[] = (string) array_values((array) $row)[0];
    }

    return implode("\n", $lines);
}

/**
 * The names of the search indexes, sorted.
 *
 * @return list<string>
 */
function accSearchIndexNames(): array
{
    $names = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->pluck('indexname')
        ->filter(static fn ($name): bool => str_ends_with((string) $name, '_search_index'))
        ->map(static fn ($name): string => (string) $name)
        ->sort()
        ->values()
        ->all();

    return $names;
}

/**
 * The titles of the panel global search for a term, category label => list of titles.
 *
 * @return array<string, list<string>>
 */
function accSearchGlobal(string $term): array
{
    $results = Filament::getCurrentOrDefaultPanel()->getGlobalSearchProvider()?->getResults($term);
    $categories = [];

    foreach ($results?->getCategories() ?? [] as $label => $items) {
        foreach ($items as $item) {
            $categories[$label][] = (string) $item->title;
        }
    }

    return $categories;
}

/**
 * The twelve indexes, written out on purpose: the test does not read them from the application.
 *
 * @return list<string>
 */
function accSearchExpectedIndexes(): array
{
    $names = [
        'clients_name_search_index',
        'clients_company_number_search_index',
        'contacts_name_search_index',
        'projects_key_search_index',
        'projects_name_search_index',
        'tasks_reference_search_index',
        'tasks_title_search_index',
        'transactions_counterparty_search_index',
        'documents_name_search_index',
        'documents_description_search_index',
        'invoices_number_search_index',
        'invoices_variable_symbol_search_index',
    ];

    sort($names);

    return $names;
}

describe('the database pieces', function (): void {
    it('has both extensions and an immutable wrapper that folds diacritics and case', function (): void {
        $extensions = DB::table('pg_extension')->whereIn('extname', ['pg_trgm', 'unaccent'])->pluck('extname')->sort()->values()->all();
        $function = DB::selectOne("select provolatile from pg_proc where proname = 'kokpit_search_text'");

        expect($extensions)->toBe(['pg_trgm', 'unaccent'])
            ->and($function->provolatile)->toBe('i')
            ->and(DB::selectOne('select kokpit_search_text(?) as folded', ['Školní ŽLUŤOUČKÝ kůň'])->folded)->toBe('skolni zlutoucky kun')
            ->and(DB::selectOne('select kokpit_search_text(null) as folded')->folded)->toBeNull();
    });

    it('has exactly twelve GIN trigram indexes over the wrapper', function (): void {
        expect(accSearchIndexNames())->toBe(accSearchExpectedIndexes());

        foreach (DB::table('pg_indexes')->whereIn('indexname', accSearchExpectedIndexes())->pluck('indexdef') as $definition) {
            expect((string) $definition)->toContain('USING gin')
                ->toContain('kokpit_search_text(')
                ->toContain('gin_trgm_ops');
        }
    });

    it('uses the indexes for the query the application builds', function (): void {
        $query = Task::query()->where(static function (Builder $match): void {
            AccentInsensitiveSearch::where($match, 'tasks.reference', 'skola');
            AccentInsensitiveSearch::where($match, 'tasks.title', 'skola', 'or');
        });

        $plan = accSearchPlan($query);

        expect($plan)->toContain('Bitmap Index Scan')
            ->toContain('tasks_reference_search_index')
            ->toContain('tasks_title_search_index');
    });

    it('rolls back to no function and no indexes, keeps the extensions and rolls forward again', function (): void {
        $project = accSearchProject();
        accSearchTask($project, 'Example Škola web');

        $migration = accSearchMigration();
        $migration->down();

        $extensions = DB::table('pg_extension')->whereIn('extname', ['pg_trgm', 'unaccent'])->count();

        expect(DB::selectOne("select 1 as present from pg_proc where proname = 'kokpit_search_text'"))->toBeNull()
            ->and(accSearchIndexNames())->toBe([])
            ->and($extensions)->toBe(2);

        $migration->up();

        expect(accSearchIndexNames())->toBe(accSearchExpectedIndexes())
            ->and(accSearchOffered('skola'))->toBe(['ATL-1']);
    });
});

describe('the builder', function (): void {
    it('escapes the characters of a pattern and refuses an unknown boolean', function (): void {
        expect(AccentInsensitiveSearch::pattern('100%'))->toBe('%100!%%')
            ->and(AccentInsensitiveSearch::pattern('a_b'))->toBe('%a!_b%')
            ->and(AccentInsensitiveSearch::pattern('what!'))->toBe('%what!!%')
            ->and(static fn () => AccentInsensitiveSearch::where(Task::query(), 'tasks.title', 'x', 'xor'))->toThrow(InvalidArgumentException::class);
    });

    it('builds the predicate with the quoted column and a bound pattern', function (): void {
        $query = AccentInsensitiveSearch::where(Task::query(), 'tasks.title', 'skola');

        expect($query->toSql())->toContain('kokpit_search_text("tasks"."title") like kokpit_search_text(?) escape \'!\'')
            ->and($query->getBindings())->toBe(['%skola%']);
    });
});

describe('the Signal type-ahead', function (): void {
    it('finds the Škola task for a term without diacritics, with them and in capitals', function (): void {
        $project = accSearchProject();
        accSearchTask($project, 'Example Škola web');
        accSearchTask($project, 'Example login fix');

        foreach (['skola', 'Škola', 'ŠKOLA', 'škola'] as $term) {
            expect(accSearchOffered($term))->toBe(['ATL-1']);
        }
    });

    it('finds a task through the name of its project', function (): void {
        accSearchTask(accSearchProject('SUM', 'Example Šumava'), 'Example plain title');
        accSearchTask(accSearchProject('OTH', 'Example Other'), 'Example plain title');

        expect(accSearchOffered('sumava'))->toBe(['SUM-1'])
            ->and(accSearchOffered('ŠUMAVA'))->toBe(['SUM-1']);
    });
});

describe('the global search', function (): void {
    it('finds a client, a project and a task whatever the diacritics and the letter case', function (): void {
        Client::factory()->create(['name' => 'Example Školní jídelna']);
        Client::factory()->create(['name' => 'Example Plain client']);
        accSearchTask(accSearchProject('SUM', 'Example Šumava web'), 'Example plain title');
        accSearchTask(accSearchProject('ATL', 'Example Atlas'), 'Example Žlutý kůň');

        foreach (['skolni', 'ŠKOLNÍ', 'Školní'] as $term) {
            expect(accSearchGlobal($term))->toBe(['Klienti' => ['Example Školní jídelna']]);
        }

        expect(accSearchGlobal('sumava'))->toBe(['Projekty' => ['SUM · Example Šumava web']])
            ->and(accSearchGlobal('zluty kun'))->toBe(['Úkoly' => ['ATL-1 · Example Žlutý kůň']])
            ->and(accSearchGlobal('ŽLUTÝ KŮŇ'))->toBe(['Úkoly' => ['ATL-1 · Example Žlutý kůň']])
            ->and(accSearchGlobal('zluty banana'))->toBe([]);
    });

    it('searches through the index expression', function (): void {
        accSearchTask(accSearchProject(), 'Example Žlutý kůň');

        DB::enableQueryLog();
        accSearchGlobal('zluty');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $matching = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'kokpit_search_text("tasks"."reference")')
            && str_contains($sql, 'kokpit_search_text("tasks"."title")'));

        expect($matching)->not->toBe([]);
    });

    it('finds nothing for a Partner, whatever the diacritics', function (): void {
        $client = Client::factory()->create(['name' => 'Example Školní partner']);
        $project = app(CreateProject::class)->handle($client, [
            'name' => 'Example Šumava partner',
            'key' => 'PSK',
            'billing_type' => 'hourly',
            'client_visible' => true,
        ]);
        app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example Žlutý partner']);

        $terms = ['skolni', 'sumava', 'zluty'];

        // The control: the Admin finds each term, so the empty Partner answer is the rule and not an empty index.
        foreach ($terms as $term) {
            expect(accSearchGlobal($term))->not->toBe([]);
        }

        $this->actingAs(Canary::partnerFor($client->id));

        foreach ($terms as $term) {
            expect(accSearchGlobal($term))->toBe([]);
        }
    });

    it('is built from the trait on every globally searchable resource', function (): void {
        $searchable = array_values(array_filter(
            Filament::getResources(),
            static fn (string $resource): bool => $resource::canGloballySearch(),
        ));
        sort($searchable);

        $expected = [ClientResource::class, ProjectResource::class, TaskResource::class];
        sort($expected);

        expect($searchable)->toBe($expected);

        foreach ($searchable as $resource) {
            expect(class_uses_recursive($resource))->toContain(SearchesGloballyWithoutAccents::class);
        }
    });
});

describe('the guards', function (): void {
    it('has no ilike string in the application', function (): void {
        $files = 0;
        $offenders = [];

        foreach (Finder::create()->files()->name('*.php')->in(base_path('app')) as $file) {
            $files++;

            foreach (token_get_all($file->getContents()) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && stripos($token[1], 'ilike') !== false) {
                    $offenders[] = $file->getRelativePathname();
                }
            }
        }

        expect($files)->toBeGreaterThan(20)
            ->and($offenders)->toBe([]);
    });
});
