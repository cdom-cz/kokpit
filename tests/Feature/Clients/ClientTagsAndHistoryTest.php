<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Money\Money;
use App\Filament\RelationManagers\ClientHistoryRelationManager;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieTagsInput;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Client tags of type client and the Admin-only client history (CL-05, D-07,
 * D-06). Livewire page tests as the Admin; every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

/**
 * A minimal valid client form state for the tag tests.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tagsClientForm(array $overrides = []): array
{
    return [
        'country' => 'CZ',
        'name' => 'Example client tagged',
        'stage' => 'active',
        'currency' => 'CZK',
        'hourly_rate' => '1500',
        'payment_terms_days' => 14,
        'invoice_language' => 'cs',
        ...$overrides,
    ];
}

it('stores the tags typed on a new client as two tags of type client', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(tagsClientForm(['tags' => ['vip', 'retainer']]))
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Example client tagged')->firstOrFail();

    expect($client->tagsWithType('client')->pluck('name')->sort()->values()->all())->toBe(['retainer', 'vip'])
        ->and(Tag::query()->where('type', 'client')->count())->toBe(2)
        ->and(Tag::query()->whereNull('type')->count())->toBe(0);
});

it('edits the tags of a client and keeps them typed', function (): void {
    $client = Client::factory()->create();
    $client->syncTagsWithType(['vip'], 'client');

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertFormSet(['tags' => ['vip']])
        ->fillForm(['tags' => ['vip', 'slow-payer']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Client::query()->findOrFail($client->id)->tagsWithType('client')->pluck('name')->sort()->values()->all())->toBe(['slow-payer', 'vip'])
        ->and(Tag::query()->whereNull('type')->count())->toBe(0);
});

it('suggests only client tags to the client input and only project tags to the project input', function (): void {
    $client = Client::factory()->create(['currency' => 'CZK']);
    $client->attachTag('vip', 'client');

    $project = app(CreateProjectAction::class)->handle($client, [
        'name' => 'Example tagged project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
    ]);
    $project->attachTag('alpha', 'project');

    Livewire::test(CreateClient::class)
        ->assertFormFieldExists('tags', static function (SpatieTagsInput $field): bool {
            $suggestions = $field->getSuggestions();

            return in_array('vip', $suggestions, true) && ! in_array('alpha', $suggestions, true);
        });

    Livewire::test(CreateProject::class)
        ->assertFormFieldExists('tags', static function (SpatieTagsInput $field): bool {
            $suggestions = $field->getSuggestions();

            return in_array('alpha', $suggestions, true) && ! in_array('vip', $suggestions, true);
        });
});

it('shows the tags on the detail page of the client', function (): void {
    $client = Client::factory()->create();
    $client->syncTagsWithType(['vip', 'retainer'], 'client');
    $client->attachTag('stray', 'project');

    $this->get(route('filament.admin.resources.clients.view', ['record' => $client->id]))
        ->assertOk()
        ->assertSee('vip')
        ->assertSee('retainer')
        ->assertDontSee('stray');
});

it('keeps every client tag away from the Partner of that client', function (): void {
    $client = Client::factory()->create(['currency' => 'CZK']);
    $client->attachTag(Canary::canary('client_tag'), 'client');

    $project = app(CreateProjectAction::class)->handle($client, [
        'name' => 'Example visible project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
    $own = Canary::canary('project_tag');
    $project->attachTag($own, 'project');

    $this->actingAs(Canary::partnerFor($client->id));

    $visible = Tag::query()->get()->map(static fn (Tag $tag): string => (string) $tag->name)->all();
    $onProjects = Project::query()->with('tags')->get()->flatMap(static fn (Project $project) => $project->tags->pluck('name'))->all();

    expect($visible)->toBe([$own])
        ->and(Tag::query()->where('type', 'client')->count())->toBe(0)
        ->and($onProjects)->toBe([$own]);
});

it('logs a change of the name and the rate under the log name client with exactly those attributes', function (): void {
    $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::ofMinor(100000, 'CZK')]);
    $client->activitiesAsSubject()->delete();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['name' => 'Example client renamed', 'hourly_rate' => '1200'])
        ->call('save')
        ->assertHasNoFormErrors();

    $rows = $client->activitiesAsSubject()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->log_name)->toBe('client')
        ->and(array_keys($rows->first()->attribute_changes->get('attributes')))->toBe(['name', 'hourly_rate_minor'])
        ->and($rows->first()->attribute_changes->get('attributes')['hourly_rate_minor'])->toBe(120000);
});

it('shows the history relation manager to the Admin with the client changes', function (): void {
    $client = Client::factory()->create();
    $client->update(['name' => 'Example client renamed']);
    $activities = $client->activitiesAsSubject()->get();

    expect($activities)->not->toBeEmpty()
        ->and(ClientHistoryRelationManager::canViewForRecord($client, ViewClient::class))->toBeTrue();

    Livewire::test(ClientHistoryRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($activities);
});

it('refuses the history relation manager to a Partner at boot', function (): void {
    $client = Client::factory()->create();
    $partner = Canary::partnerFor($client->id);

    $this->actingAs($partner);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => ClientHistoryRelationManager::canViewForRecord($client, ViewClient::class)))->toBeFalse();

    Livewire::test(ClientHistoryRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertForbidden();
});
