<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerProjectResource;
use App\Filament\Partner\Resources\PartnerProjectResource\Pages\ViewPartnerProject;
use App\Filament\Partner\Resources\PartnerProjectResource\RelationManagers\PartnerProjectDocumentsRelationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * What a Partner meets of files (DO-01, D-03, D-04, D-20, D-21): only files flagged visible that a project or
 * task of the own client owns, never a hidden file, an internal-comment file, a client-owned or unattached
 * file, nor anything of another client or an archived owner. Every name and canary is fictional and
 * assembled at runtime.
 */

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function documentVisibilitySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client-visible project of the client, written through the domain Action.
 *
 * @param  array<string, mixed>  $attributes
 */
function documentVisibilityProject(string $clientId, array $attributes = []): Project
{
    return documentVisibilitySystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
        ...$attributes,
    ]));
}

/**
 * Attaches one fictional file to the owner as the Admin and returns it.
 */
function documentVisibilityFile(User $admin, ?object $owner, string $name, ?bool $visible = null): Document
{
    return documentVisibilitySystem(static fn (): Document => app(AttachDocuments::class)
        ->handle($admin, $owner, [DocumentFixtures::upload($name)], visibleToClient: $visible)
        ->firstOrFail());
}

/**
 * The Partner project tab mounted on the project page, with the project read through the scoped query.
 */
function documentVisibilityProjectTab(Project $project): Testable
{
    $seen = Project::query()->whereKey($project->getKey())->firstOrFail();

    return Livewire::test(PartnerProjectDocumentsRelationManager::class, ['ownerRecord' => $seen, 'pageClass' => ViewPartnerProject::class]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partnerA = Canary::partnerFor($this->clientA);
    $this->projectA = documentVisibilityProject($this->clientA);
});

describe('project tab (tracer)', function (): void {
    it('registers the Soubory tab on the Partner project page', function (): void {
        expect(PartnerProjectResource::getRelations())->toBe([PartnerProjectDocumentsRelationManager::class]);

        $this->actingAs($this->partnerA);

        Livewire::test(ViewPartnerProject::class, ['record' => $this->projectA->getKey()])
            ->assertSeeLivewire(PartnerProjectDocumentsRelationManager::class);
    });

    it('lists the shared file of an own project and not its hidden twin', function (): void {
        $shared = documentVisibilityFile($this->admin, $this->projectA, 'shared-example.txt', true);
        $hidden = documentVisibilityFile($this->admin, $this->projectA, 'hidden-example.txt', false);
        $this->actingAs($this->partnerA);

        documentVisibilityProjectTab($this->projectA)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$hidden])
            ->assertSee('shared-example.txt')
            ->assertDontSee('hidden-example.txt');
    });

    it('redirects a download click of the Partner to a signed link bound to the Partner', function (): void {
        $shared = documentVisibilityFile($this->admin, $this->projectA, 'shared-example.txt', true);
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityProjectTab($this->projectA)
            ->callAction(TestAction::make('download')->table($shared));

        $url = data_get($tab->effects, 'redirect');

        expect($url)->toBeString()
            ->and($url)->toContain(route('filament.admin.files.document', ['document' => $shared->getKey()], false))
            ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query['u'])->toBe($this->partnerA->getKey());
    });

    it('streams the shared file to the Partner through the signed link', function (): void {
        $shared = documentVisibilityFile($this->admin, $this->projectA, 'shared-example.txt', true);
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityProjectTab($this->projectA)
            ->callAction(TestAction::make('download')->table($shared));

        $this->get((string) data_get($tab->effects, 'redirect'))->assertOk();
    });
});
