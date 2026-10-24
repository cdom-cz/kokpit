<?php

declare(strict_types=1);

use App\Domain\Identity\ApiAbility;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\Activity;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Notifications\TaskChangedNotification;
use App\Domain\Tasks\Notifications\TaskCommentedNotification;
use App\Domain\Tasks\Notifications\TaskEscalatedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;
use Tests\Support\AuditDeclaration;
use Tests\Support\CanaryRecord;
use Tests\Support\CanaryRegistry;
use Tests\Support\ChannelMatrix;
use Tests\Support\ChannelProbe;
use Tests\Support\ModelDeclaration;

/*
 * AUD-01, D-07, D-08, D-09, D-12, R-12-05-1/2/3: the entity x channel canary matrix.
 *
 * tests/Support/ChannelMatrix.php gives every CanaryRegistry entity one cell for each of the eight channels (ui,
 * search, selects, exports, notifications, activity, downloads, api): `foreign` (Partner A may see the own rows,
 * never client B's), `none` (Partner A sees the rows of neither client) or not-applicable with a written reason.
 * An entity without a row, a row without an entity, a missing channel, an unknown value and an empty reason all
 * fail the build. A parametrised test then walks every entity through every channel that has a driver in
 * tests/Support/ChannelProbe.php (ChannelProbe::DRIVEN): it seeds two clients with distinguishable canaries, runs
 * the channel as Partner A and asserts that no needle of client B and no client B id appears, and, for a `none`
 * cell, no needle of client A either. Each driven channel has a control proving that the channel reaches real
 * data, so a refusal is not vacuous.
 *
 * The existing tests/Isolation files stay where they are and unchanged (D-08); a leak found here is fixed with the
 * failing row as its regression (D-09); every run checks every entity and channel (D-12).
 */

beforeEach(function (): void {
    $this->probe = ChannelProbe::seed();
});

afterEach(function (): void {
    CanaryRegistry::cleanup();
});

/**
 * The PartnerIsolated models of the application plus the test-only canary model, sorted.
 *
 * @return list<class-string<Model>>
 */
function auditChannelIsolatedModels(): array
{
    $models = array_values(array_filter(
        ModelDeclaration::appModels(),
        static fn (string $class): bool => is_subclass_of($class, PartnerIsolated::class),
    ));
    $models[] = CanaryRecord::class;
    sort($models, SORT_STRING);

    return $models;
}

/**
 * The entities that have no row and the rows that have no entity, one line each; empty when they match. Pure over
 * its arguments, so the self-tests can feed it planted classes.
 *
 * @param  list<string>  $entities  the classes that must have a row
 * @param  list<string>  $rows  the classes that have a row
 * @return list<string>
 */
function auditChannelDifferences(array $entities, array $rows): array
{
    $differences = [];

    foreach (array_diff($entities, $rows) as $class) {
        $differences[] = "{$class} has no ChannelMatrix row";
    }

    foreach (array_diff($rows, $entities) as $class) {
        $differences[] = "ChannelMatrix declares {$class} but no such entity exists";
    }

    sort($differences);

    return $differences;
}

/**
 * What a channel blob shows that the cell forbids (R-12-05-3): any needle of client B and the client B id, and, for
 * a `none` cell, any needle of client A as well. Empty when the blob is clean.
 *
 * @param  list<string>  $needlesA
 * @param  list<string>  $needlesB
 * @return list<string>
 */
function auditChannelLeaks(string $cell, string $blob, array $needlesA, array $needlesB, string $clientB): array
{
    $leaks = [];

    foreach ($needlesB as $needle) {
        if (str_contains($blob, $needle)) {
            $leaks[] = "the client B needle {$needle} appears";
        }
    }

    if (str_contains($blob, $clientB)) {
        $leaks[] = "the client B id {$clientB} appears";
    }

    if ($cell === 'none') {
        foreach ($needlesA as $needle) {
            if (str_contains($blob, $needle)) {
                $leaks[] = "the client A needle {$needle} appears although the cell is none";
            }
        }
    }

    return $leaks;
}

it('declares every CanaryRegistry entity and only those', function (): void {
    $entities = array_keys(CanaryRegistry::fixtures());
    $rows = array_keys(ChannelMatrix::entities());
    sort($entities, SORT_STRING);
    sort($rows, SORT_STRING);

    expect(auditChannelDifferences($entities, $rows))->toBe([], "Entities and ChannelMatrix rows differ:\n".implode("\n", auditChannelDifferences($entities, $rows)))
        ->and($rows)->toBe($entities);
});

it('declares every PartnerIsolated model', function (): void {
    $differences = auditChannelDifferences(auditChannelIsolatedModels(), array_keys(ChannelMatrix::entities()));

    // The matrix may declare more than the isolated models (CanaryRegistry holds more), never fewer.
    $missing = array_values(array_filter($differences, static fn (string $line): bool => str_ends_with($line, 'has no ChannelMatrix row')));

    expect($missing)->toBe([], "PartnerIsolated models without a ChannelMatrix row:\n".implode("\n", $missing));
});

it('reports a PartnerIsolated model without a row and a row without an entity', function (): void {
    $planted = new class extends Model implements PartnerIsolated
    {
        public function constrainForPartner(Builder $query, string $clientId): void
        {
            $query->where('client_id', $clientId);
        }
    };

    expect(auditChannelDifferences([$planted::class, Project::class], [Project::class]))->toBe([$planted::class.' has no ChannelMatrix row'])
        ->and(auditChannelDifferences([Project::class], [Project::class, $planted::class]))->toBe(['ChannelMatrix declares '.$planted::class.' but no such entity exists']);
});

it('keeps every row complete and every not-applicable cell reasoned', function (): void {
    expect(ChannelMatrix::problems())->toBe([]);
});

it('reports a missing channel, an unknown value and an empty not-applicable reason', function (): void {
    $complete = array_fill_keys(ChannelMatrix::CHANNELS, 'none');
    $row = $complete;
    unset($row['search']);
    $row['selects'] = 'maybe';
    $row['exports'] = ['na' => '  '];

    $problems = ChannelMatrix::problemsIn(['App\\Planted' => $row]);

    $text = implode("\n", $problems);

    expect($problems)->toHaveCount(3)
        ->and($text)->toContain('no cell for the channel search')
        ->and($text)->toContain('selects: the cell is none of foreign')
        ->and($text)->toContain('exports: a not-applicable cell needs a written reason')
        ->and(ChannelMatrix::problemsIn(['App\\Planted' => $complete]))->toBe([]);
});

it('applies the activity channel exactly to the models that write activity rows', function (): void {
    $writers = [...AuditDeclaration::loggingModels(), Activity::class];
    $wrong = [];

    foreach (ChannelMatrix::entities() as $model => $row) {
        $applicable = ! is_array($row['activity']);

        if ($applicable !== in_array($model, $writers, true)) {
            $wrong[] = $model.($applicable ? ' declares the activity channel but writes no activity rows' : ' writes activity rows but declares the activity channel not applicable');
        }
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('finds a leak in a channel blob', function (): void {
    expect(auditChannelLeaks('foreign', 'x B1 y', ['A1'], ['B1'], 'b-id'))->toHaveCount(1)
        ->and(auditChannelLeaks('foreign', 'x b-id y', ['A1'], ['B1'], 'b-id'))->toHaveCount(1)
        ->and(auditChannelLeaks('foreign', 'x A1 y', ['A1'], ['B1'], 'b-id'))->toBe([])
        ->and(auditChannelLeaks('none', 'x A1 y', ['A1'], ['B1'], 'b-id'))->toHaveCount(1)
        ->and(auditChannelLeaks('none', 'nothing', ['A1'], ['B1'], 'b-id'))->toBe([]);
});

it("keeps the canaries of the cell out of Partner A's channel", function (string $model, string $channel): void {
    $cell = ChannelMatrix::entities()[$model][$channel];
    $blob = $this->probe->blob($channel, $model);

    $leaks = auditChannelLeaks(
        (string) $cell,
        $blob,
        $this->probe->needles($model, 'a'),
        $this->probe->needles($model, 'b'),
        $this->probe->clientId('b'),
    );

    expect($leaks)->toBe([], "{$model} leaks through {$channel}:\n".implode("\n", $leaks));
})->with(ChannelMatrix::cells(ChannelProbe::DRIVEN));

it('lets global search reach real data, so the search rows are not vacuous', function (): void {
    $needleB = $this->probe->canary('b');

    // The Admin finds the client B project by its name: the search reaches real rows.
    $adminResults = $this->probe->search($this->probe->admin(), $needleB);

    expect(implode("\n", $adminResults[Project::class] ?? []))->toContain($needleB);

    // Partner A reaches the own canary record through the one Partner-readable searchable resource, and only that.
    $partnerResults = $this->probe->search($this->probe->partnerA(), 'CANARY');

    expect($partnerResults)->toHaveKey(CanaryRecord::class)
        ->and(implode("\n", $partnerResults[CanaryRecord::class]))->toContain($this->probe->canary('a'))
        ->and(implode("\n", $partnerResults[CanaryRecord::class]))->not->toContain($needleB)
        ->and(array_keys($partnerResults))->toBe([CanaryRecord::class]);
});

it('lets an Admin token read the canaries of both clients, so the API rows are not vacuous', function (): void {
    $token = ApiFixtures::token($this->probe->admin(), ApiAbility::values());

    Auth::forgetGuards();
    $body = (string) $this->withToken($token)->getJson('/api/v1/time-entries?per_page=200')->assertOk()->getContent();

    expect($body)->toContain($this->probe->canary('a'))
        ->and($body)->toContain($this->probe->canary('b'));
});

it('lets the Admin read the activity rows of both clients, so the activity rows are not vacuous', function (): void {
    $this->probe->actAs($this->probe->admin());

    $rows = json_encode(Activity::query()->get()->map(static fn (Activity $row): array => $row->getAttributes())->all(), JSON_THROW_ON_ERROR);

    foreach (['a', 'b'] as $side) {
        foreach ($this->probe->needles(Activity::class, $side) as $needle) {
            expect($rows)->toContain($needle);
        }
    }
});

it('lets the Partner select offer the own visible project, so the select rows are not vacuous', function (): void {
    $blob = $this->probe->blob('selects', Project::class);

    expect($blob)->toContain($this->probe->canary('a'))
        ->and($blob)->not->toContain($this->probe->canary('b'));
});

it('lets Partner A see the own canary on the own pages, so the UI rows are not vacuous', function (): void {
    $project = $this->probe->blob('ui', Project::class);
    $record = $this->probe->blob('ui', CanaryRecord::class);

    expect($project)->toContain($this->probe->canary('a'))
        ->and($project)->not->toContain($this->probe->canary('b'))
        ->and($record)->toContain($this->probe->canary('a'))
        ->and($record)->not->toContain($this->probe->canary('b'));
});

it('still refuses a route parameter and a resource it does not know, so a new kind of route is looked at on purpose', function (): void {
    $unknownParameter = new LaravelRoute('GET', '/x/{unknown}', []);
    $unknownResource = new LaravelRoute('GET', '/x/{record}', []);
    $unknownResource->name('filament.admin.resources.unlisted.view');

    expect(fn () => $this->probe->walkUrl($unknownParameter, 'x'))->toThrow(LogicException::class, 'does not know the parameter')
        ->and(fn () => $this->probe->walkRecordId($unknownResource, [], 'a'))->toThrow(LogicException::class, 'does not know the resource');
});

it('serves the Admin the files of both clients, so the download rows are not vacuous', function (): void {
    $this->probe->storeInvoicePdfs();
    $this->probe->storeArchives();
    $this->probe->actAs($this->probe->admin());

    $urls = $this->probe->downloadUrls($this->probe->admin());

    foreach (['document a', 'document b', 'invoice a', 'invoice b', 'archive a', 'archive b'] as $label) {
        $response = $this->get($urls[$label]);

        expect($response->getStatusCode())->toBe(200, "{$label} answered {$response->getStatusCode()} for the Admin");
    }

    $own = $this->get($urls['document a']);

    expect($own->headers->get('Content-Disposition'))->toContain($this->probe->canary('a'));
});

it('lets the Admin open the export pages, so the export rows are not vacuous', function (): void {
    $this->probe->actAs($this->probe->admin());

    foreach (['time report', 'report exports'] as $label) {
        $response = $this->get($this->probe->exportUrls()[$label]);

        expect($response->getStatusCode())->toBe(200, "{$label} answered {$response->getStatusCode()} for the Admin");
    }
});

it('reaches Partner A with the own task notifications, so the notification rows are not vacuous', function (): void {
    $facts = $this->probe->notifyFacts();
    $blob = $this->probe->blob('notifications', Task::class);
    $types = array_values(array_unique(array_map(static fn ($row): string => $row->type, $this->probe->notifyBellRows())));
    sort($types, SORT_STRING);

    $expected = [TaskChangedNotification::class, TaskCommentedNotification::class, TaskEscalatedNotification::class];
    sort($expected, SORT_STRING);

    // Partner A holds a bell row of every task event that can reach a Partner, and a mail, naming the own task.
    expect($types)->toBe($expected)
        ->and($this->probe->notifyMailTexts())->not->toBeEmpty()
        ->and($blob)->toContain($facts['reference'])
        ->and($blob)->toContain($facts['title'])
        ->and($blob)->not->toContain($this->probe->canary('a'))
        ->and($blob)->not->toContain($this->probe->canary('b'));
});
