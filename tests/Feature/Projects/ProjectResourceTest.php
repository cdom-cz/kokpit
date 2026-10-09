<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\RelationManagers\ProjectHistoryRelationManager;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin project screens (PR-01, PR-02, PR-03, PR-04, D-15, D-16). Livewire
 * page tests as the Admin; every name, key and amount is fictional.
 */

/**
 * A project of the client written through the domain Action, as the Admin would.
 *
 * @param  array<string, mixed>  $overrides
 */
function resourceProject(Client $client, array $overrides = []): Project
{
    return app(CreateProjectAction::class)->handle($client, [
        'name' => 'Example project '.mb_strtolower(Str::random(6)),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '850,50',
        'estimate_hours' => '1,5',
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
    $this->client = Client::factory()->create(['currency' => 'CZK']);
});

it('creates a project with every field, its billing row and its project tags', function (): void {
    $key = Canary::projectKey();

    Livewire::test(CreateProject::class)
        ->fillForm([
            'client_id' => $this->client->id,
            'name' => 'Example launch',
            'key' => $key,
            'description' => 'A fictional description.',
            'status' => 'in_progress',
            'priority' => 'high',
            'start_date' => '2026-01-05',
            'end_date' => '2026-03-01',
            'client_visible' => true,
            'tags' => ['alpha', 'beta'],
            'billing_type' => 'hourly',
            'hourly_rate' => '850,50',
            'estimate_hours' => '1,5',
            'internal_note' => 'Fictional internal note.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $project = Project::query()->where('key', $key)->firstOrFail();
    $billing = $project->billing;

    expect($project->client_id)->toBe($this->client->id)
        ->and($project->name)->toBe('Example launch')
        ->and($project->status->value)->toBe('in_progress')
        ->and($project->priority->value)->toBe('high')
        ->and($project->client_visible)->toBeTrue()
        ->and($project->start_date?->toDateString())->toBe('2026-01-05')
        ->and($project->end_date?->toDateString())->toBe('2026-03-01')
        ->and($billing->hourly_rate_minor)->toBe(85050)
        ->and($billing->hourly_rate_currency)->toBe('CZK')
        ->and($billing->estimate_seconds)->toBe(5400)
        ->and($billing->internal_note)->toBe('Fictional internal note.')
        ->and($project->tagsWithType('project')->pluck('name')->sort()->values()->all())->toBe(['alpha', 'beta'])
        ->and($project->tags()->count())->toBe(2);
});

it('creates a project from only a name, a key and a client and lists it', function (): void {
    $key = Canary::projectKey();

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $this->client->id, 'name' => 'Example minimal', 'key' => $key])
        ->call('create')
        ->assertHasNoFormErrors();

    $project = Project::query()->where('key', $key)->firstOrFail();

    expect($project->description)->toBeNull()
        ->and($project->start_date)->toBeNull()
        ->and($project->end_date)->toBeNull()
        ->and($project->tags()->count())->toBe(0)
        ->and($project->billing->hourly_rate_minor)->toBeNull();

    Livewire::test(ListProjects::class)->assertCanSeeTableRecords([$project]);
});

it('refuses an empty name and an end date before the start date as field errors', function (): void {
    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $this->client->id, 'name' => '', 'key' => Canary::projectKey()])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    Livewire::test(CreateProject::class)
        ->fillForm([
            'client_id' => $this->client->id,
            'name' => 'Example dates',
            'key' => Canary::projectKey(),
            'start_date' => '2026-05-10',
            'end_date' => '2026-05-09',
        ])
        ->call('create')
        ->assertHasFormErrors(['end_date']);

    expect(Project::query()->count())->toBe(0);
});

it('shows a field error on the rate and stores nothing when a CZK rate has too many decimals', function (): void {
    $key = Canary::projectKey();

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $this->client->id, 'name' => 'Example rate', 'key' => $key, 'hourly_rate' => '1,234'])
        ->call('create')
        ->assertHasErrors(['data.hourly_rate']);

    expect(Project::query()->where('key', $key)->exists())->toBeFalse();
});

it('shows the field error of a fixed price project without a price on the price field', function (): void {
    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $this->client->id, 'name' => 'Example fixed', 'key' => Canary::projectKey(), 'billing_type' => 'fixed_price'])
        ->call('create')
        ->assertHasFormErrors(['fixed_price' => 'required']);
});

it('keeps Czech diacritics exactly as typed and counts the 255 limit of the name in characters', function (): void {
    $czech = 'Účetnictví – příliš žluťoučký kůň';
    $key = Canary::projectKey();

    Livewire::test(CreateProject::class)
        ->fillForm([
            'client_id' => $this->client->id,
            'name' => $czech,
            'key' => $key,
            'description' => 'Úprava ěščřžýáíé',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $project = Project::query()->where('key', $key)->firstOrFail();

    expect($project->name)->toBe($czech)
        ->and($project->description)->toBe('Úprava ěščřžýáíé');

    $long = str_repeat('ž', 255);
    $longKey = Canary::projectKey();

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $this->client->id, 'name' => $long, 'key' => $longKey])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::query()->where('key', $longKey)->firstOrFail()->name)->toBe($long);

    $tooLongKey = Canary::projectKey();

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $this->client->id, 'name' => $long.'ž', 'key' => $tooLongKey])
        ->call('create')
        ->assertHasFormErrors(['name' => 'max']);

    expect(Project::query()->where('key', $tooLongKey)->exists())->toBeFalse();
});

it('offers only active clients in the client select', function (): void {
    $archived = Client::factory()->create();
    $archived->delete();

    Livewire::test(CreateProject::class)
        ->assertFormFieldExists('client_id', static function (Select $field) use ($archived): bool {
            $options = $field->getOptions();

            return array_key_exists(test()->client->id, $options) && ! array_key_exists($archived->id, $options);
        });
});

it('refuses a crafted archived client id on create', function (): void {
    $archived = Client::factory()->create();
    $archived->delete();
    $key = Canary::projectKey();

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $archived->id, 'name' => 'Example archived client', 'key' => $key])
        ->call('create')
        ->assertHasErrors(['data.client_id']);

    expect(Project::query()->where('key', $key)->exists())->toBeFalse();
});

it('loads the billing row into the edit form, keeps the client and switches status and priority freely', function (): void {
    $project = resourceProject($this->client, ['status' => 'done', 'priority' => 'urgent', 'internal_note' => 'Fictional note.']);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormSet([
            'client_id' => $this->client->id,
            'billing_type' => BillingType::Hourly,
            'hourly_rate' => '850,50',
            'estimate_hours' => '1,5',
            'internal_note' => 'Fictional note.',
        ])
        ->fillForm(['status' => 'planned', 'priority' => 'low'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = Project::query()->findOrFail($project->id);

    expect($fresh->status->value)->toBe('planned')
        ->and($fresh->priority->value)->toBe('low')
        ->and($fresh->client_id)->toBe($this->client->id)
        ->and($fresh->billing->hourly_rate_minor)->toBe(85050)
        ->and($fresh->billing->estimate_seconds)->toBe(5400);
});

it('saves changed billing terms from the edit form', function (): void {
    $project = resourceProject($this->client);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->fillForm(['billing_type' => 'fixed_price', 'fixed_price' => '12000', 'hourly_rate' => '', 'estimate_hours' => '40'])
        ->call('save')
        ->assertHasNoFormErrors();

    $billing = Project::query()->findOrFail($project->id)->billing;

    expect($billing->billing_type->value)->toBe('fixed_price')
        ->and($billing->fixed_price_minor)->toBe(1200000)
        ->and($billing->hourly_rate_minor)->toBeNull()
        ->and($billing->estimate_seconds)->toBe(144000);
});

it('shows a domain error of the edit save next to its field', function (): void {
    $project = resourceProject($this->client);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->fillForm(['hourly_rate' => '1,234'])
        ->call('save')
        ->assertHasErrors(['data.hourly_rate']);
});

it('shows the currency of the client as the suffix of the rate fields', function (): void {
    $eur = Client::factory()->create(['currency' => 'EUR', 'hourly_rate' => Money::ofMinor(0, 'EUR')]);

    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $eur->id])
        ->assertFormFieldExists('hourly_rate', static fn ($field): bool => str_contains((string) $field->getSuffixLabel(), 'EUR'))
        ->assertFormFieldExists('fixed_price', static fn ($field): bool => str_contains((string) $field->getSuffixLabel(), 'EUR'));
});

it('archives a project out of the default list, shows it under the trashed filter, opens it by URL and restores it', function (): void {
    $project = resourceProject($this->client);

    Livewire::test(ListProjects::class)
        ->assertCanSeeTableRecords([$project])
        ->callTableAction('delete', $project);

    expect(Project::query()->withTrashed()->findOrFail($project->id)->trashed())->toBeTrue();

    Livewire::test(ListProjects::class)
        ->assertCanNotSeeTableRecords([$project])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$project]);

    $this->get(route('filament.admin.resources.projects.view', ['record' => $project->id]))
        ->assertOk()
        ->assertSee($project->name);

    Livewire::test(ListProjects::class)
        ->filterTable('trashed', false)
        ->callTableAction('restore', $project);

    expect(Project::query()->findOrFail($project->id)->trashed())->toBeFalse();

    Livewire::test(ListProjects::class)->assertCanSeeTableRecords([$project]);
});

it('keeps the tags of an archived project and brings them back with the restore', function (): void {
    $project = resourceProject($this->client);
    $project->syncTagsWithType(['alpha'], 'project');

    Livewire::test(ListProjects::class)->callTableAction('delete', $project);

    expect(Project::query()->withTrashed()->findOrFail($project->id)->tagsWithType('project')->pluck('name')->all())->toBe(['alpha']);

    Livewire::test(ListProjects::class)
        ->filterTable('trashed', false)
        ->callTableAction('restore', $project);

    expect(Project::query()->findOrFail($project->id)->tagsWithType('project')->pluck('name')->all())->toBe(['alpha']);
});

it('offers no force delete in the table, the bulk actions or the page actions', function (): void {
    $project = resourceProject($this->client);

    Livewire::test(ListProjects::class)
        ->assertTableActionExists('delete')
        ->assertTableActionDoesNotExist('forceDelete')
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete');

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionExists('delete')
        ->assertActionDoesNotExist('forceDelete');

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertActionExists('delete')
        ->assertActionDoesNotExist('forceDelete');

    $source = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament/Resources/ProjectResource'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $source .= (string) file_get_contents($file->getPathname());
    }

    expect($source.(string) file_get_contents(app_path('Filament/Resources/ProjectResource.php')))->not->toContain('ForceDelete');
});

it('shows the project history to the Admin', function (): void {
    $project = resourceProject($this->client);
    $project->update(['name' => 'Example renamed']);
    $activities = $project->activitiesAsSubject()->get();

    expect($activities)->not->toBeEmpty()
        ->and(ProjectHistoryRelationManager::canViewForRecord($project, EditProject::class))->toBeTrue();

    Livewire::test(ProjectHistoryRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($activities);
});

it('opens the view page of a project for the Admin with the billing section filled', function (): void {
    $project = resourceProject($this->client);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertFormSet(['hourly_rate' => '850,50', 'name' => $project->name]);
});

it('gives a Partner 403 on every Admin project route', function (): void {
    $project = resourceProject($this->client, ['client_visible' => true]);
    $partner = Canary::partnerFor($this->client->id);

    $this->actingAs($partner);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => Project::query()->whereKey($project->id)->exists()))->toBeTrue();

    $this->get(route('filament.admin.resources.projects.index'))->assertForbidden();
    $this->get(route('filament.admin.resources.projects.create'))->assertForbidden();
    $this->get(route('filament.admin.resources.projects.edit', ['record' => $project->id]))->assertForbidden();
    $this->get(route('filament.admin.resources.projects.view', ['record' => $project->id]))->assertForbidden();
});
