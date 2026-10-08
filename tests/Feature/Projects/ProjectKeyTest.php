<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Projects\Models\Project;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The key of a project in the Admin form (D-14, PR-02): the suggestion while the
 * name is typed, the override, and the duplicate-key field error from the form
 * and from the database race path. Every name and key is fictional.
 */

/**
 * A project with the given key, written through the domain Action.
 */
function keyedProject(Client $client, string $key, string $name = 'Example keyed project'): Project
{
    return app(CreateProjectAction::class)->handle($client, ['name' => $name, 'key' => $key, 'billing_type' => 'hourly']);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
    $this->client = Client::factory()->create();
});

it('fills the suggested key when the name is typed on create', function (): void {
    Livewire::test(CreateProject::class)
        ->set('data.name', 'Nový web')
        ->assertSet('data.key', 'NW')
        ->set('data.name', 'Účetnictví')
        ->assertSet('data.key', 'UCET');
});

it('suggests the next free variant when the initials belong to an archived project', function (): void {
    keyedProject($this->client, 'NW')->delete();

    Livewire::test(CreateProject::class)
        ->set('data.name', 'Nový web')
        ->assertSet('data.key', 'NOW');
});

it('suggests PRJ for a name without usable letters', function (): void {
    Livewire::test(CreateProject::class)
        ->set('data.name', '123')
        ->assertSet('data.key', 'PRJ');
});

it('does not overwrite a key the Admin has typed when the name changes', function (): void {
    Livewire::test(CreateProject::class)
        ->set('data.name', 'Nový web')
        ->set('data.key', 'ZZZ')
        ->set('data.name', 'Jiný projekt')
        ->assertSet('data.key', 'ZZZ');
});

it('resumes suggesting when the typed key is cleared again', function (): void {
    Livewire::test(CreateProject::class)
        ->set('data.key', 'ZZZ')
        ->set('data.key', '')
        ->set('data.name', 'Nový web')
        ->assertSet('data.key', 'NW');
});

it('never changes the key on edit when the name changes', function (): void {
    $project = keyedProject($this->client, 'ABC');

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->set('data.name', 'Completely different name')
        ->assertSet('data.key', 'ABC')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Project::query()->findOrFail($project->id)->key)->toBe('ABC');
});

it('shows a duplicate of an existing or archived project key as a field error on key', function (): void {
    keyedProject($this->client, 'ABC');
    keyedProject($this->client, 'DEF')->delete();

    foreach (['ABC', 'DEF'] as $taken) {
        Livewire::test(CreateProject::class)
            ->fillForm(['client_id' => $this->client->id, 'name' => 'Example duplicate', 'key' => $taken])
            ->call('create')
            ->assertHasFormErrors(['key' => 'unique']);
    }

    expect(Project::query()->withTrashed()->count())->toBe(2);
});

it('lets a project keep its own key on edit', function (): void {
    $project = keyedProject($this->client, 'ABC');

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->fillForm(['name' => 'Renamed project', 'key' => 'ABC'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('shows a key taken between the form check and the save as a field error on key and leaves one row', function (): void {
    $key = Canary::projectKey();
    $client = $this->client;
    $raced = false;

    // The form's unique rule is the SELECT on projects by key; the other request wins right after it.
    DB::listen(static function (QueryExecuted $query) use (&$raced, $key, $client): void {
        if ($raced || ! str_contains($query->sql, 'count(*)') || ! str_contains($query->sql, '"projects"') || ! in_array($key, $query->bindings, true)) {
            return;
        }

        $raced = true;
        Project::factory()->create(['client_id' => $client->id, 'key' => $key, 'name' => 'Example raced project']);
    });

    $page = Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $client->id, 'name' => 'Example loser', 'key' => $key])
        ->call('create')
        ->assertHasErrors(['data.key']);

    // The message of the domain Action, not the form's unique rule: the database path was taken.
    expect($page->errors()->first('data.key'))->toBe(__('kokpit.projects.errors.key_taken'))
        ->and($raced)->toBeTrue()
        ->and(Project::query()->where('key', $key)->count())->toBe(1)
        ->and(Project::query()->where('key', $key)->firstOrFail()->name)->toBe('Example raced project');
});

it('stores a lower-case key in upper case', function (): void {
    Livewire::test(CreateProject::class)
        ->set('data.client_id', $this->client->id)
        ->set('data.name', 'Example lower case')
        ->set('data.key', 'abc')
        ->assertSet('data.key', 'ABC')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::query()->where('name', 'Example lower case')->firstOrFail()->key)->toBe('ABC');
});

it('refuses a key of seven letters, one letter or digits as a field error', function (string $key): void {
    Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $this->client->id, 'name' => 'Example bad key', 'key' => $key])
        ->call('create')
        ->assertHasFormErrors(['key']);

    expect(Project::query()->where('name', 'Example bad key')->exists())->toBeFalse();
})->with([
    'seven letters' => ['ABCDEFG'],
    'one letter' => ['A'],
    'digits' => ['AB12'],
]);
