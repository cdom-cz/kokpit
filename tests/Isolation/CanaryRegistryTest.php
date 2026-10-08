<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Models\Contact;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Domain\Tasks\Models\TaskChecklistItem;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\Canary;
use Tests\Support\CanaryRecord;
use Tests\Support\CanaryRegistry;
use Tests\Support\ModelDeclaration;

beforeEach(function (): void {
    CanaryRegistry::prepare();
});

afterEach(function (): void {
    CanaryRegistry::cleanup();
});

/**
 * Every PartnerIsolated model of the application plus the test-only canary model.
 *
 * @return list<class-string<Model>>
 */
function partnerIsolatedModels(): array
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
 * The PartnerIsolated models among the given ones that have no registry entry,
 * sorted by class name.
 *
 * @param  list<class-string>  $models
 * @param  list<class-string>  $registered
 * @return list<class-string>
 */
function modelsWithoutFixture(array $models, array $registered): array
{
    $missing = array_values(array_diff($models, $registered));
    sort($missing, SORT_STRING);

    return $missing;
}

/**
 * Every row of the model as the current user sees it, serialised.
 *
 * @param  class-string<Model>  $model
 * @return array{count: int, json: string}
 */
function visibleRows(string $model): array
{
    /** @var Builder<Model> $query */
    $query = $model::query();
    $rows = $query->get();

    // Raw attributes, not toJson(): package models such as Media serialise to nothing useful.
    return ['count' => $rows->count(), 'json' => (string) json_encode($rows->map(static fn (Model $row): array => $row->getAttributes())->all())];
}

it('has a registry entry for every PartnerIsolated model', function (): void {
    $missing = modelsWithoutFixture(partnerIsolatedModels(), array_keys(CanaryRegistry::fixtures()));

    expect($missing)->toBe([], "PartnerIsolated models without a canary fixture:\n".implode("\n", $missing));
});

it('does not pass vacuously: the real isolated models and the canary model are all expected', function (): void {
    expect(partnerIsolatedModels())->toEqualCanonicalizing([
        Activity::class,
        CanaryRecord::class,
        Client::class,
        ClientInvitation::class,
        Contact::class,
        Media::class,
        Project::class,
        ProjectBilling::class,
        SettingsProperty::class,
        Tag::class,
        Task::class,
        TaskBilling::class,
        TaskChecklistItem::class,
        TaskComment::class,
        WebhookCall::class,
    ]);
});

it('reports a PartnerIsolated model without an entry, sorted by class name', function (): void {
    $unregistered = new class extends Model implements PartnerIsolated
    {
        public function constrainForPartner(Builder $query, string $clientId): void
        {
            $query->where('client_id', $clientId);
        }
    };
    $models = [$unregistered::class, Media::class, CanaryRecord::class];

    expect(modelsWithoutFixture($models, [Media::class, CanaryRecord::class]))->toBe([$unregistered::class])
        ->and(modelsWithoutFixture(array_reverse($models), [Media::class]))
        ->toBe(modelsWithoutFixture($models, [Media::class]));
});

it('lets the Admin read the canary of both clients in every registered model, so the checks below are not vacuous', function (): void {
    [$clientA, $clientB] = Canary::twoClients();
    $canaryA = Canary::canary('admin_a');
    $canaryB = Canary::canary('admin_b');
    CanaryRegistry::seedAll($clientA, $canaryA);
    CanaryRegistry::seedAll($clientB, $canaryB);

    $this->actingAs(Canary::admin());

    foreach (array_keys(CanaryRegistry::fixtures()) as $model) {
        $rows = visibleRows($model);

        expect(str_contains($rows['json'], $canaryA))->toBeTrue("{$model}: Admin must see the client A canary")
            ->and(str_contains($rows['json'], $canaryB))->toBeTrue("{$model}: Admin must see the client B canary");
    }
});

it('shows Partner A no row carrying a client B canary in any registered model', function (): void {
    [$clientA, $clientB] = Canary::twoClients();
    $canaryA = Canary::canary('own_a');
    $canaryB = Canary::canary('other_b');
    CanaryRegistry::seedAll($clientA, $canaryA);
    CanaryRegistry::seedAll($clientB, $canaryB);

    $this->actingAs(Canary::partnerFor($clientA));

    foreach (array_keys(CanaryRegistry::fixtures()) as $model) {
        $rows = visibleRows($model);

        expect(str_contains($rows['json'], $canaryB))->toBeFalse("{$model}: Partner A must not see the client B canary")
            ->and(str_contains($rows['json'], $clientB))->toBeFalse("{$model}: Partner A must not see the client B id");
    }
});

it('shows Partner A zero rows of every deny-all model and exactly the own row of a client-bound model', function (): void {
    [$clientA, $clientB] = Canary::twoClients();
    $canaryA = Canary::canary('own_a');
    CanaryRegistry::seedAll($clientA, $canaryA);
    CanaryRegistry::seedAll($clientB, Canary::canary('other_b'));

    $this->actingAs(Canary::partnerFor($clientA));

    foreach (array_keys(CanaryRegistry::fixtures()) as $model) {
        $rows = visibleRows($model);

        if (in_array(DeniesPartners::class, class_uses_recursive($model), true)) {
            expect($rows['count'])->toBe(0, "{$model} must be closed to a Partner");
        } else {
            expect($rows['count'])->toBe(1, "{$model} must show a Partner exactly the own client's row")
                ->and(str_contains($rows['json'], $canaryA))->toBeTrue("{$model}: Partner A must see the own canary");
        }
    }
});

it('shows a Partner without a client nothing in any registered model', function (): void {
    [$clientA, $clientB] = Canary::twoClients();
    CanaryRegistry::seedAll($clientA, Canary::canary('a'));
    CanaryRegistry::seedAll($clientB, Canary::canary('b'));

    $this->actingAs(Canary::partnerFor(null));

    foreach (array_keys(CanaryRegistry::fixtures()) as $model) {
        expect(visibleRows($model)['count'])->toBe(0, $model);
    }
});
