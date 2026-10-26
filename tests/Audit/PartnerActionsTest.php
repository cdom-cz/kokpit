<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Dashboard;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Widgets\PartnerTasks;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Entry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CanaryRecord;
use Tests\Support\CanaryRegistry;
use Tests\Support\DocumentFixtures;
use Tests\Support\PanelInventory;
use Tests\Support\PartnerMatrix;

/*
 * US-01, AUD-01, D-01, D-02, D-03, D-09, R-12-04-1: what a Partner may do with each entity.
 *
 * tests/Support/PartnerMatrix.php declares, as literal data, every action (list, view, create, update, delete and
 * the custom abilities comment, escalate, clearEscalation, editDescription and attach) a Partner with a client may
 * take on each entity a Partner can see, per record state: an own record the Partner sees (own_visible), a second
 * one that differs in the way an ability cares about (own_visible_other, own_upload), an own record hidden from the
 * Partner (own_hidden) and a record of the other client (foreign). Every other entity of the canary registry is
 * declared Admin-only, and a Partner is refused every action on it, on the rows of the own client and of the other
 * client. The declared entities equal the CanaryRegistry fixtures in both directions (D-03, D-07). The Admin is
 * allowed everything. The record states are built inside the test transaction through the domain Actions and the
 * canary fixtures, with fictional data only.
 *
 * Where the code differs from the draft the row states what the code does and the difference is reported to the
 * owner (R-12-03-3, D-02); a Partner grant beyond the draft is a leak, fixed with the row as its regression (D-09).
 */

/**
 * The entities that are declared twice, not at all or without an entity, one line each; empty when the declaration
 * and the registry agree. Pure over its arguments, so the self-test can feed it planted classes.
 *
 * @param  list<string>  $partnerVisible  the entities declared with Partner abilities
 * @param  list<string>  $adminOnly  the entities declared Admin-only
 * @param  list<string>  $entities  the entities of the registry
 * @return list<string>
 */
function auditActionsDifferences(array $partnerVisible, array $adminOnly, array $entities): array
{
    $differences = [];

    foreach (array_intersect($partnerVisible, $adminOnly) as $class) {
        $differences[] = "{$class} is declared both Partner-visible and Admin-only";
    }

    foreach (array_diff($entities, [...$partnerVisible, ...$adminOnly]) as $class) {
        $differences[] = "{$class} has no declaration of what a Partner may do";
    }

    foreach (array_diff([...$partnerVisible, ...$adminOnly], $entities) as $class) {
        $differences[] = "PartnerMatrix declares {$class} but no such entity exists";
    }

    sort($differences);

    return $differences;
}

/**
 * Makes the user the signed-in one, with the guards forgotten first so a guard that was resolved for the previous
 * user cannot answer for this one.
 */
function auditActionsActAs(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user);
}

/**
 * Whether the user may take the ability on the record of the state (or on the class for the state `class`). For
 * `attach` the record is the task the file would hang on, found under the same state in the Task scene.
 *
 * @param  array<class-string, array<string, Model>>  $scene
 * @param  class-string  $model
 */
function auditActionsAllows(User $user, array $scene, string $model, string $ability, string $state): bool
{
    auditActionsActAs($user);

    $gate = Gate::forUser($user);

    if ($state === 'class') {
        return $gate->allows($ability, $model);
    }

    if ($ability === 'attach') {
        return $gate->allows('attach', [Document::class, $scene[Task::class][$state]]);
    }

    return $gate->allows($ability, $scene[$model][$state]);
}

/**
 * A client-visible project of the client, and one that is hidden from the client, written through the domain Action.
 */
function auditActionsHiddenProject(string $clientId): Project
{
    return app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('hidden_project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => false,
    ]);
}

/**
 * Builds every record state of the PartnerMatrix::partnerAbilities() entities for Partner A: the rows the canary
 * fixtures wrote for the clients A and B (found as system), plus the rows the states need that no fixture writes,
 * created through the domain Actions as the Admin. The Partner's own upload goes in last, as Partner A.
 *
 * @return array<class-string, array<string, Model>>
 */
function auditActionsScene(User $admin, User $partnerA, string $clientA, string $clientB, string $canaryA, string $canaryB): array
{
    $scene = app(PartnerContext::class)->runAsSystem(static function () use ($admin, $partnerA, $clientA, $clientB, $canaryA, $canaryB): array {
        $projectA = Project::query()->where('client_id', $clientA)->where('name', $canaryA)->firstOrFail();
        $projectB = Project::query()->where('client_id', $clientB)->where('name', $canaryB)->firstOrFail();
        $hiddenProject = auditActionsHiddenProject($clientA);
        $create = app(CreateTask::class);

        // Requested by and assigned to Partner A, status Planned: the Partner may edit its description and clear its flag.
        $own = $create->handle($admin, $projectA, [
            'title' => Canary::canary('own_task'),
            'status' => 'planned',
            'requester_id' => (string) $partnerA->getKey(),
            'assignee_id' => (string) $partnerA->getKey(),
        ]);

        // Requested by and assigned to the Admin (the column is not nullable), in progress: visible, but not the
        // Partner's to edit or to clear.
        $other = $create->handle($admin, $projectA, ['title' => Canary::canary('other_task'), 'status' => 'in_progress']);

        $hidden = $create->handle($admin, $hiddenProject, ['title' => Canary::canary('hidden_task')]);
        $foreign = Task::query()->where('title', $canaryB)->firstOrFail();

        $comment = app(AddTaskComment::class);
        $visibleComment = $comment->handle($admin, $own, '<p>'.Canary::canary('comment').'</p>');
        $internalComment = $comment->handle($admin, $own, '<p>'.Canary::canary('internal').'</p>', internal: true);
        $foreignComment = $comment->handle($admin, $foreign, '<p>'.Canary::canary('foreign_comment').'</p>');

        $invoice = static fn (string $client, string $canary, bool $issued): Invoice => Invoice::query()
            ->where('client_id', $client)->where('note', $canary)
            ->when($issued, static fn ($query) => $query->whereNotNull('number'), static fn ($query) => $query->whereNull('number'))
            ->firstOrFail();
        $item = static fn (Invoice $invoice): InvoiceItem => InvoiceItem::query()->where('invoice_id', $invoice->getKey())->firstOrFail();
        $issuedA = $invoice($clientA, $canaryA, true);
        $draftA = $invoice($clientA, $canaryA, false);
        $issuedB = $invoice($clientB, $canaryB, true);

        $file = static fn (string $name): Document => Document::query()->where('name', $name)->firstOrFail();
        $tag = static fn (string $canary): Tag => Tag::query()->get()->first(static fn (Tag $candidate): bool => $candidate->name === $canary) ?? throw new LogicException("No project tag carries {$canary}.");
        $canaryRecord = static fn (string $canary): CanaryRecord => CanaryRecord::query()->where('secret', $canary)->withoutGlobalScopes()->firstOrFail();

        return [
            Project::class => ['own_visible' => $projectA, 'own_hidden' => $hiddenProject, 'foreign' => $projectB],
            Task::class => ['own_visible' => $own, 'own_visible_other' => $other, 'own_hidden' => $hidden, 'foreign' => $foreign],
            TaskComment::class => ['own_visible' => $visibleComment, 'own_hidden' => $internalComment, 'foreign' => $foreignComment],
            Document::class => ['own_visible' => $file($canaryA.'.txt'), 'own_hidden' => $file($canaryA.'-hidden.txt'), 'foreign' => $file($canaryB.'.txt')],
            Invoice::class => ['own_visible' => $issuedA, 'own_hidden' => $draftA, 'foreign' => $issuedB],
            InvoiceItem::class => ['own_visible' => $item($issuedA), 'own_hidden' => $item($draftA), 'foreign' => $item($issuedB)],
            Tag::class => ['own_visible' => $tag($canaryA), 'foreign' => $tag($canaryB)],
            CanaryRecord::class => ['own_visible' => $canaryRecord($canaryA), 'foreign' => $canaryRecord($canaryB)],
        ];
    });

    // The Partner's own upload on the own task, written as Partner A through the real Action.
    auditActionsActAs($partnerA);
    $scene[Document::class]['own_upload'] = app(AttachDocuments::class)
        ->handle($partnerA, $scene[Task::class]['own_visible'], [DocumentFixtures::upload(Canary::canary('upload').'.txt')])
        ->firstOrFail();

    return $scene;
}

/**
 * The names a declaration holds that match the forbidden pattern and carry no written exception, one line each
 * (`{surface}#{name}`); empty when every such name is excused. Pure over its arguments, so the self-test can feed it
 * planted names (per D-03, measured time is forbidden everywhere).
 *
 * @param  array<string, list<string>>  $fields  surface => allowed names
 * @param  array<string, string>  $exceptions  `{surface}#{name}` => reason
 * @return list<string>
 */
function auditActionsForbiddenNames(array $fields, array $exceptions): array
{
    $offenders = [];

    foreach ($fields as $surface => $names) {
        foreach ($names as $name) {
            if (preg_match(PartnerMatrix::FORBIDDEN_FIELD_PATTERN, $name) === 1 && trim($exceptions["{$surface}#{$name}"] ?? '') === '') {
                $offenders[] = "{$surface}#{$name}";
            }
        }
    }

    return $offenders;
}

/**
 * The names sorted and without repeats, so two lists compare equal whatever their order (a repeatable entry names
 * the same field once per item).
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function auditActionsCanonical(array $names): array
{
    $names = array_values(array_unique($names));
    sort($names, SORT_STRING);

    return $names;
}

/**
 * The class a surface key names: the one PartnerMatrix::panelClasses() row whose short class name is the first part
 * of the key.
 *
 * @return class-string
 */
function auditActionsSurfaceClass(string $surface): string
{
    $short = explode('.', $surface)[0];
    $matches = array_values(array_filter(
        array_keys(PartnerMatrix::panelClasses()),
        static fn (string $class): bool => substr($class, (int) strrpos($class, '\\') + 1) === $short,
    ));

    if (count($matches) !== 1) {
        throw new LogicException("Field surface {$surface} names {$short}, which is not exactly one panel class.");
    }

    return $matches[0];
}

/**
 * The names of the entries of an infolist schema: the named entries among its flat components, hidden ones
 * included.
 *
 * @return list<string>
 */
function auditActionsEntryNames(Schema $schema): array
{
    $names = [];

    foreach ($schema->getFlatComponents(withActions: false, withHidden: true) as $component) {
        if ($component instanceof Entry) {
            $names[] = $component->getName();
        }
    }

    return $names;
}

/**
 * The names of the fields of a form schema, hidden ones included.
 *
 * @return list<string>
 */
function auditActionsFieldNames(Schema $schema): array
{
    return array_values(array_map(
        static fn ($field): string => $field->getName(),
        $schema->getFlatFields(withHidden: true),
    ));
}

/**
 * The record of the scene the Partner opens a screen on, read again through the Partner-scoped query as Partner A,
 * the way a request reads it.
 *
 * @param  array<class-string, array<string, Model>>  $scene
 * @param  class-string<Model>  $model
 */
function auditActionsOwnRecord(array $scene, string $model): Model
{
    return $model::query()->whereKey($scene[$model]['own_visible']->getKey())->firstOrFail();
}

/**
 * The Partner screens that have no field surface and no exemption, one class per line; empty when every screen has
 * at least one surface. Pure over its arguments, so the self-test can feed it a planted screen.
 *
 * @param  list<class-string>  $screens  the Partner screens that need a surface
 * @param  list<string>  $surfaces  the declared surface keys
 * @param  list<class-string>  $exempt  the screens exempt by declaration
 * @return list<class-string>
 */
function auditActionsSurfaceGaps(array $screens, array $surfaces, array $exempt): array
{
    return array_values(array_filter($screens, static function (string $class) use ($surfaces, $exempt): bool {
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        return ! in_array($class, $exempt, true)
            && array_filter($surfaces, static fn (string $surface): bool => str_starts_with($surface, $short.'.')) === [];
    }));
}

/**
 * The names the runtime shows Partner A on one surface (R-12-04-2): table columns, filters, infolist entries and
 * form fields of a Resource on its list page; of a relation manager mounted on an own visible project or task (the
 * inline comment form where the manager has one); of a table widget; the columns the Partner tasks widget selects;
 * the form of the profile page.
 *
 * @param  array<class-string, array<string, Model>>  $scene
 * @return list<string>
 */
function auditActionsRuntimeFields(string $surface, array $scene, User $partner): array
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    auditActionsActAs($partner);

    $kind = explode('.', $surface)[1];
    $class = auditActionsSurfaceClass($surface);

    if (is_subclass_of($class, Resource::class)) {
        $page = Livewire::test($class::getPages()['index']->getPage())->instance();
        $record = auditActionsOwnRecord($scene, $class::getModel());

        return match ($kind) {
            'table' => array_map('strval', array_keys($page->getTable()->getColumns())),
            'filters' => array_map('strval', array_keys($page->getTable()->getFilters(withHidden: true))),
            'infolist' => auditActionsEntryNames($class::infolist(Schema::make($page)->record($record))),
            'form' => auditActionsFieldNames($class::form(Schema::make($page))),
            default => throw new LogicException("{$surface} is not a surface of a Resource."),
        };
    }

    if (is_subclass_of($class, RelationManager::class)) {
        $owner = auditActionsOwnRecord($scene, PanelInventory::ownerModel($class));
        $manager = Livewire::test($class, ['ownerRecord' => $owner, 'pageClass' => PanelInventory::pageClassOf(PanelInventory::owningResource($class))])->instance();

        return match ($kind) {
            'table' => array_map('strval', array_keys($manager->getTable()->getColumns())),
            'filters' => array_map('strval', array_keys($manager->getTable()->getFilters(withHidden: true))),
            'form' => auditActionsFieldNames(method_exists($manager, 'commentForm') ? $manager->commentForm : $manager->form(Schema::make($manager))),
            default => throw new LogicException("{$surface} is not a surface of a relation manager."),
        };
    }

    if ($class === PartnerTasks::class) {
        $row = Livewire::test($class)->instance()->tasks()->first() ?? throw new LogicException('The Partner tasks widget shows no row to read the selected columns from.');
        $names = array_keys($row->getAttributes());

        foreach ($row->getRelations() as $relation => $related) {
            foreach (array_keys($related instanceof Model ? $related->getAttributes() : []) as $attribute) {
                $names[] = "{$relation}.{$attribute}";
            }
        }

        return array_map('strval', $names);
    }

    if (is_subclass_of($class, TableWidget::class)) {
        $table = Livewire::test($class)->instance()->getTable();

        return array_map('strval', match ($kind) {
            'table' => array_keys($table->getColumns()),
            'filters' => array_keys($table->getFilters(withHidden: true)),
            default => throw new LogicException("{$surface} is not a surface of a table widget."),
        });
    }

    if ($class === EditProfile::class) {
        $schema = Livewire::test($class)->instance()->getSchema('form') ?? throw new LogicException('The profile page has no form schema.');

        return auditActionsFieldNames($schema);
    }

    throw new LogicException("{$surface}: teach auditActionsRuntimeFields how to read {$class}.");
}

it('declares every isolated entity exactly once', function (): void {
    $partnerVisible = array_keys(PartnerMatrix::partnerAbilities());
    $adminOnly = array_keys(PartnerMatrix::adminOnlyModels());
    $entities = array_keys(CanaryRegistry::fixtures());

    $differences = auditActionsDifferences($partnerVisible, $adminOnly, $entities);

    expect($differences)->toBe([], implode("\n", $differences))
        ->and(count($partnerVisible) + count($adminOnly))->toBe(count($entities))
        ->and($partnerVisible)->toContain(Task::class, Document::class, Invoice::class)
        ->and($adminOnly)->toContain(Client::class);
});

it('reports an entity without a declaration, a declaration without an entity and an entity declared twice', function (): void {
    $partnerVisible = array_keys(PartnerMatrix::partnerAbilities());
    $adminOnly = array_keys(PartnerMatrix::adminOnlyModels());
    $entities = array_keys(CanaryRegistry::fixtures());

    expect(auditActionsDifferences($partnerVisible, $adminOnly, [...$entities, 'App\\Planted\\Entity']))
        ->toBe(['App\\Planted\\Entity has no declaration of what a Partner may do'])
        ->and(auditActionsDifferences($partnerVisible, [...$adminOnly, 'App\\Planted\\Ghost'], $entities))
        ->toBe(['PartnerMatrix declares App\\Planted\\Ghost but no such entity exists'])
        ->and(auditActionsDifferences($partnerVisible, [...$adminOnly, Task::class], $entities))
        ->toBe([Task::class.' is declared both Partner-visible and Admin-only']);
});

it('keeps every ability row well formed and every Admin-only entity reasoned', function (): void {
    expect(PartnerMatrix::abilityProblems())->toBe([])
        ->and(array_keys(PartnerMatrix::abilityRows()))->toContain('Task editDescription own_visible_other', 'Task clearEscalation own_visible_other', 'Document attach foreign');
});

it('never declares a rate, price, estimate, billing, internal, measured-time or finance field for a Partner without a written exception', function (): void {
    expect(auditActionsForbiddenNames(PartnerMatrix::partnerFields(), PartnerMatrix::fieldExceptions()))->toBe([])
        ->and(PartnerMatrix::fieldProblems())->toBe([]);
});

it('reports a planted rate, tracked time, measured seconds and time entry total as forbidden unless excused', function (): void {
    $planted = ['Planted.table' => ['hourly_rate', 'tracked_time', 'measured_seconds', 'timeEntries_sum_minutes', 'spent_minutes', 'name']];

    expect(auditActionsForbiddenNames($planted, []))->toBe([
        'Planted.table#hourly_rate',
        'Planted.table#tracked_time',
        'Planted.table#measured_seconds',
        'Planted.table#timeEntries_sum_minutes',
        'Planted.table#spent_minutes',
    ])->and(auditActionsForbiddenNames($planted, ['Planted.table#hourly_rate' => 'the printed hourly rate of an own document']))
        ->not->toContain('Planted.table#hourly_rate')
        ->and(auditActionsForbiddenNames($planted, ['Planted.table#hourly_rate' => '  ']))
        ->toContain('Planted.table#hourly_rate');
});

it('keeps timestamp names outside the forbidden pattern', function (): void {
    foreach (['created_at', 'updated_at', 'escalated_at', 'deadline', 'due_date', 'issue_date', 'datetime', 'timestamp'] as $name) {
        expect(preg_match(PartnerMatrix::FORBIDDEN_FIELD_PATTERN, $name))->toBe(0, "{$name} must stay outside the forbidden pattern");
    }

    foreach (['time_entries', 'total_time', 'tracked_time', 'timeEntries', 'time_entries_sum_minutes', 'project.hourly_rate', 'estimate_hours'] as $name) {
        expect(preg_match(PartnerMatrix::FORBIDDEN_FIELD_PATTERN, $name))->toBe(1, "{$name} must match the forbidden pattern");
    }
});

it('declares the fields of every Partner surface', function (): void {
    $exempt = array_keys(PartnerMatrix::fieldSurfaceExemptions());
    $screens = [];

    foreach (PartnerMatrix::panelClasses() as $class => $row) {
        $isSurface = is_subclass_of($class, Resource::class)
            || is_subclass_of($class, RelationManager::class)
            || is_subclass_of($class, TableWidget::class)
            || in_array($class, [PartnerTasks::class, EditProfile::class], true);

        if ($row['partner'] && $isSurface) {
            $screens[] = $class;
        }
    }

    $missing = auditActionsSurfaceGaps($screens, array_keys(PartnerMatrix::partnerFields()), $exempt);

    // Every declared surface belongs to a Partner screen, and every exemption names a Partner screen.
    $orphans = array_values(array_filter(array_keys(PartnerMatrix::partnerFields()), static function (string $surface): bool {
        try {
            return PartnerMatrix::panelClasses()[auditActionsSurfaceClass($surface)]['partner'] !== true;
        } catch (LogicException) {
            return true;
        }
    }));

    expect($screens)->toContain(PartnerTaskResource::class, PartnerTaskCommentsRelationManager::class, EditProfile::class)
        ->and($missing)->toBe([], "Partner screens without a field surface:\n".implode("\n", $missing))
        ->and($orphans)->toBe([], "Field surfaces of no Partner screen:\n".implode("\n", $orphans))
        ->and(array_values(array_filter($exempt, static fn (string $class): bool => ! (PartnerMatrix::panelClasses()[$class]['partner'] ?? false))))->toBe([]);
});

it('reports a Partner screen without a field surface and leaves an exempt one alone', function (): void {
    expect(auditActionsSurfaceGaps([PartnerTaskResource::class, Dashboard::class], ['PartnerProjectResource.table'], [Dashboard::class]))->toBe([PartnerTaskResource::class])
        ->and(auditActionsSurfaceGaps([PartnerTaskResource::class], ['PartnerTaskResource.table'], []))->toBe([]);
});

describe('with the record states built', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CanaryRegistry::prepare();

        [$clientA, $clientB] = Canary::twoClients();
        $canaryA = Canary::canary('actions_a');
        $canaryB = Canary::canary('actions_b');

        CanaryRegistry::seedAll($clientA, $canaryA);
        CanaryRegistry::seedAll($clientB, $canaryB);

        $this->admin = CanaryRegistry::plannerOwner();
        $this->partnerA = Canary::partnerFor($clientA);
        $this->scene = auditActionsScene($this->admin, $this->partnerA, $clientA, $clientB, $canaryA, $canaryB);
    });

    afterEach(function (): void {
        CanaryRegistry::cleanup();
    });

    it('builds every declared record state', function (): void {
        foreach (PartnerMatrix::abilityRows() as $label => [$model, $ability, $state]) {
            if ($state === 'class') {
                continue;
            }

            // The record of an `attach` row is the task the file would hang on.
            $owner = $ability === 'attach' ? Task::class : $model;

            expect($this->scene[$owner][$state] ?? null)->toBeInstanceOf(Model::class, "{$label} has no record in the scene");
        }
    });

    it('answers {1} on {0} in state {2} for a Partner as declared and for the Admin as allowed', function (string $model, string $ability, string $state): void {
        $declared = $state === 'class'
            ? PartnerMatrix::partnerAbilities()[$model]['class'][$ability]
            : PartnerMatrix::partnerAbilities()[$model]['records'][$ability][$state];

        $partner = auditActionsAllows($this->partnerA, $this->scene, $model, $ability, $state);
        $admin = auditActionsAllows($this->admin, $this->scene, $model, $ability, $state);

        expect($partner)->toBe($declared, "{$ability} on {$model} ({$state}) for a Partner")
            ->and($admin)->toBeTrue("{$ability} on {$model} ({$state}) for the Admin");
    })->with(PartnerMatrix::abilityRows());

    it('refuses a Partner every action on the Admin-only {0}', function (string $model): void {
        $rows = app(PartnerContext::class)->runAsSystem(static fn () => $model::query()->withoutGlobalScopes()->get());

        // The fixtures wrote rows for both clients, so the refusals below are not vacuous.
        expect($rows)->not->toBeEmpty("the fixtures wrote no {$model} row");

        auditActionsActAs($this->partnerA);
        $gate = Gate::forUser($this->partnerA);

        $allowed = [];

        foreach (['viewAny', 'create'] as $ability) {
            if ($gate->allows($ability, $model)) {
                $allowed[] = "{$ability} on the class";
            }
        }

        foreach ($rows as $row) {
            foreach (['view', 'update', 'delete'] as $ability) {
                if ($gate->allows($ability, $row)) {
                    $allowed[] = "{$ability} on row {$row->getKey()}";
                }
            }
        }

        expect($allowed)->toBe([], "A Partner may take these actions on the Admin-only {$model}:\n".implode("\n", $allowed));

        // And the Admin is not refused, so the refusal above is the Partner's alone.
        auditActionsActAs($this->admin);
        expect(Gate::forUser($this->admin)->allows('viewAny', $model))->toBeTrue();
    })->with(array_map(
        static fn (string $model): array => [$model],
        array_keys(PartnerMatrix::adminOnlyModels()),
    ));
    it('shows a Partner exactly the declared fields on {0}', function (string $surface): void {
        $runtime = auditActionsCanonical(auditActionsRuntimeFields($surface, $this->scene, $this->partnerA));
        $declared = auditActionsCanonical(PartnerMatrix::partnerFields()[$surface]);

        expect($runtime)->toBe($declared, sprintf(
            "%s shows a Partner\n  not declared: [%s]\n  declared but not shown: [%s]",
            $surface,
            implode(', ', array_diff($runtime, $declared)),
            implode(', ', array_diff($declared, $runtime)),
        ));
    })->with(array_map(
        static fn (string $surface): array => [$surface],
        array_keys(PartnerMatrix::partnerFields()),
    ));
});
