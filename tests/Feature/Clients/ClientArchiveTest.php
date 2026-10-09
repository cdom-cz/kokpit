<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Projects\Models\Project;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * Archive and restore of a client (CL-05, D-11): a soft delete through the
 * domain Actions that hides the client and its projects, locks its Partners out
 * and keeps its tags. Livewire page tests as the Admin; every name is fictional.
 */

/**
 * A client-visible project of the client written through the domain Action.
 */
function archiveProject(Client $client): Project
{
    return app(CreateProjectAction::class)->handle($client, [
        'name' => 'Example visible project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * The raw deleted_at value of a client, read without any scope.
 */
function rawDeletedAt(string $clientId): ?string
{
    $value = DB::table('clients')->where('id', $clientId)->value('deleted_at');

    return $value === null ? null : (string) $value;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
    $this->client = Client::factory()->create();
    $this->project = archiveProject($this->client);
});

it('archives a client from the table out of the default list, shows it under the trashed filter and opens it by URL', function (): void {
    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$this->client])
        ->callTableAction('delete', $this->client);

    expect(Client::query()->withTrashed()->findOrFail($this->client->id)->trashed())->toBeTrue();

    Livewire::test(ListClients::class)
        ->assertCanNotSeeTableRecords([$this->client])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$this->client]);

    $this->get(ClientResource::getUrl('view', ['record' => $this->client->id]))
        ->assertOk()
        ->assertSee($this->client->name);
});

it('takes an archived client out of the project client select and keeps its project away from Project::selectable()', function (): void {
    $other = Client::factory()->create();

    Livewire::test(ListClients::class)->callTableAction('delete', $this->client);

    Livewire::test(CreateProject::class)
        ->assertFormFieldExists('client_id', static function (Select $field) use ($other): bool {
            $options = $field->getOptions();

            return array_key_exists($other->id, $options) && ! array_key_exists(test()->client->id, $options);
        });

    expect(Project::query()->selectable()->whereKey($this->project->id)->exists())->toBeFalse()
        // The project itself stays: an archive hides it, it does not delete it.
        ->and(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();
});

it('hides the visible project from the Partner, locks the Partner out on the next request and brings everything back with the restore', function (): void {
    $partner = Canary::partnerFor($this->client->id);

    $this->actingAs($partner);
    expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();
    $this->get('/admin')->assertOk();

    $this->actingAs(Canary::admin());
    Livewire::test(ListClients::class)->callTableAction('delete', $this->client);

    $this->actingAs($partner);
    expect(Project::query()->whereKey($this->project->id)->exists())->toBeFalse()
        ->and(Project::query()->selectable()->whereKey($this->project->id)->exists())->toBeFalse();
    $this->get('/admin')->assertForbidden();

    $this->actingAs(Canary::admin());
    Livewire::test(ListClients::class)
        ->filterTable('trashed', false)
        ->callTableAction('restore', $this->client);

    expect(Client::query()->findOrFail($this->client->id)->trashed())->toBeFalse();

    Livewire::test(ListClients::class)->assertCanSeeTableRecords([$this->client]);
    Livewire::test(CreateProject::class)
        ->assertFormFieldExists('client_id', static fn (Select $field): bool => array_key_exists(test()->client->id, $field->getOptions()));

    $this->actingAs($partner);
    $this->get('/admin')->assertOk();
    expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue()
        ->and(Project::query()->selectable()->whereKey($this->project->id)->exists())->toBeTrue();
});

it('archives and restores several clients with the bulk actions', function (): void {
    $second = Client::factory()->create();

    Livewire::test(ListClients::class)
        ->callTableBulkAction('delete', [$this->client, $second]);

    expect(Client::query()->withTrashed()->whereKey([$this->client->id, $second->id])->whereNotNull('deleted_at')->count())->toBe(2);

    Livewire::test(ListClients::class)
        ->filterTable('trashed', false)
        ->callTableBulkAction('restore', [$this->client, $second]);

    expect(Client::query()->whereKey([$this->client->id, $second->id])->count())->toBe(2);
});

it('archives and restores from the detail page', function (): void {
    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->assertActionVisible('delete')
        ->assertActionHidden('restore')
        ->callAction('delete');

    expect(Client::query()->withTrashed()->findOrFail($this->client->id)->trashed())->toBeTrue();

    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->assertActionHidden('delete')
        ->assertActionVisible('restore')
        ->callAction('restore');

    expect(Client::query()->findOrFail($this->client->id)->trashed())->toBeFalse();
});

it('archives an archived client and restores an active one without error and without changing anything', function (): void {
    $this->client->attachTag('vip', 'client');

    app(RestoreClient::class)->handle($this->client);
    expect(rawDeletedAt($this->client->id))->toBeNull();

    app(ArchiveClient::class)->handle($this->client);
    $first = rawDeletedAt($this->client->id);

    expect($first)->not->toBeNull();

    // A second call on the same instance and on a stale copy that still looks active.
    $stale = Client::query()->withTrashed()->findOrFail($this->client->id);
    $stale->setRawAttributes([...$stale->getAttributes(), 'deleted_at' => null], true);

    $this->travel(5)->minutes();
    app(ArchiveClient::class)->handle($this->client);
    app(ArchiveClient::class)->handle($stale);

    expect(rawDeletedAt($this->client->id))->toBe($first);

    app(RestoreClient::class)->handle($this->client);
    app(RestoreClient::class)->handle($this->client);

    expect(rawDeletedAt($this->client->id))->toBeNull()
        ->and(Project::query()->withTrashed()->where('client_id', $this->client->id)->count())->toBe(1)
        ->and(DB::table('taggables')->where('taggable_id', $this->client->id)->count())->toBe(1);
});

it('keeps the client tags through archive and restore', function (): void {
    $this->client->attachTag('vip', 'client');

    app(ArchiveClient::class)->handle($this->client);

    expect(DB::table('taggables')->where('taggable_id', $this->client->id)->count())->toBe(1)
        ->and(Client::query()->withTrashed()->findOrFail($this->client->id)->tagsWithType('client')->pluck('name')->all())->toBe(['vip']);

    app(RestoreClient::class)->handle($this->client);

    expect(DB::table('taggables')->where('taggable_id', $this->client->id)->count())->toBe(1)
        ->and(Client::query()->findOrFail($this->client->id)->tagsWithType('client')->pluck('name')->all())->toBe(['vip']);
});

it('detaches the tags when a client without references is force deleted from code', function (): void {
    $lonely = Client::factory()->create();
    $lonely->attachTag('vip', 'client');

    expect(DB::table('taggables')->where('taggable_id', $lonely->id)->count())->toBe(1);

    $lonely->forceDelete();

    expect(DB::table('taggables')->where('taggable_id', $lonely->id)->count())->toBe(0)
        ->and(DB::table('clients')->where('id', $lonely->id)->exists())->toBeFalse();
});

it('offers no force delete in the table, the bulk actions or the page actions', function (): void {
    Livewire::test(ListClients::class)
        ->assertTableActionExists('delete')
        ->assertTableActionDoesNotExist('forceDelete')
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete');

    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->assertActionExists('delete')
        ->assertActionDoesNotExist('forceDelete');

    Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
        ->assertActionExists('delete')
        ->assertActionDoesNotExist('forceDelete');

    $source = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament/Resources/ClientResource'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $source .= (string) file_get_contents($file->getPathname());
    }

    expect($source.(string) file_get_contents(app_path('Filament/Resources/ClientResource.php')))->not->toContain('ForceDelete');
});

it('refuses a raw hard delete of a client that a project references', function (): void {
    RawSql::expectSqlState('23001', fn () => DB::delete('DELETE FROM clients WHERE id = ?', [$this->client->id]));

    expect(DB::table('clients')->where('id', $this->client->id)->exists())->toBeTrue();
});

it('gives a Partner 403 on the detail route of a client', function (): void {
    $this->actingAs(Canary::partnerFor($this->client->id));

    $this->get(ClientResource::getUrl('view', ['record' => $this->client->id]))->assertForbidden();
});
