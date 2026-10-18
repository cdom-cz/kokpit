<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * The first Admin invoicing screen (IN-01, IN-02; D-01, D-02, D-04, D-07): the list of documents and the
 * "Nová faktura" modal that turns last month's unbilled time into a draft. The Actions are tested elsewhere; here
 * the screen follows them. Every name, rate and instant is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague: "Minulý měsíc" is September 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * An hourly project of the client written through the domain Action, so its billing row exists.
 */
function modalProject(Client $client, string $name = 'Example project'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
    ]);
}

/**
 * One finished entry of the project from UTC instants.
 */
function modalEntry(Project $project, string $start, string $end): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail(
        TimeEntry::factory()->forProject($project)->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse($start, 'UTC'),
            'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ])->id,
    ));
}

/**
 * The entry as stored now.
 */
function modalFresh(TimeEntry $entry): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

/**
 * The newest draft of the client, read as a system run.
 */
function modalDraftOf(Client $client): ?Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?Invoice => Invoice::query()->where('client_id', $client->id)->latest('created_at')->first());
}

describe('the tracer: create a draft from last month and find it in the list', function (): void {
    it('opens the list for the Admin', function (): void {
        $this->get(InvoiceResource::getUrl('index'))->assertOk()->assertSee('Faktury');
    });

    it('fills currency, terms and language into the helper line when a client is chosen', function (): void {
        $client = InvoiceFixtures::client(['payment_terms_days' => 21]);

        Livewire::test(ListInvoices::class)
            ->mountAction('createDraft')
            ->setActionData(['client_id' => $client->id, 'kind' => 'invoice', 'source' => 'time', 'period' => 'last_month'])
            ->assertMountedActionModalSee('Měna CZK, splatnost 21 dní, jazyk dokladu čeština.');
    });

    it('shows the count, the H:MM and the estimate of the eligible entries of the chosen period', function (): void {
        $client = InvoiceFixtures::client();
        $project = modalProject($client);
        modalEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        // Outside the period: this month.
        modalEntry($project, '2026-10-05 08:00:00', '2026-10-05 09:00:00');

        Livewire::test(ListInvoices::class)
            ->mountAction('createDraft')
            ->setActionData([
                'client_id' => $client->id,
                'kind' => 'invoice',
                'source' => 'time',
                'period' => 'last_month',
                'project_ids' => [$project->id],
                'without_project' => false,
                'grouping' => 'project',
            ])
            ->assertMountedActionModalSee('Nalezeno 1 záznam, 2:00, odhad '.Money::ofMinor(180000, 'CZK')->format('cs'));
    });

    it('warns when the period has no unbilled time and still allows the draft', function (): void {
        $client = InvoiceFixtures::client();
        $project = modalProject($client);

        Livewire::test(ListInvoices::class)
            ->mountAction('createDraft')
            ->setActionData([
                'client_id' => $client->id,
                'kind' => 'invoice',
                'source' => 'time',
                'period' => 'last_month',
                'project_ids' => [$project->id],
                'without_project' => false,
                'grouping' => 'project',
            ])
            ->assertMountedActionModalSee('Pro zvolené období není žádný nevyfakturovaný čas.');
    });

    it('creates the draft with the reserved entries and shows the toast', function (): void {
        $client = InvoiceFixtures::client();
        $project = modalProject($client);
        $entry = modalEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');

        Livewire::test(ListInvoices::class)
            ->callAction('createDraft', [
                'client_id' => $client->id,
                'kind' => 'invoice',
                'source' => 'time',
                'period' => 'last_month',
                'project_ids' => [$project->id],
                'without_project' => false,
                'grouping' => 'project',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Koncept byl vytvořen');

        $draft = modalDraftOf($client);

        expect($draft)->toBeInstanceOf(Invoice::class)
            ->and($draft->isDraft())->toBeTrue()
            ->and($draft->total_minor)->toBe(180000)
            ->and(modalFresh($entry)->invoice_id)->toBe($draft->id);
    });

    it('creates an empty draft from the empty source', function (): void {
        $client = InvoiceFixtures::client();

        Livewire::test(ListInvoices::class)
            ->callAction('createDraft', [
                'client_id' => $client->id,
                'kind' => 'proforma',
                'source' => 'empty',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Koncept byl vytvořen');

        $draft = modalDraftOf($client);

        expect($draft)->toBeInstanceOf(Invoice::class)
            ->and($draft->kind->value)->toBe('proforma')
            ->and($draft->total_minor)->toBe(0)
            ->and($draft->items()->count())->toBe(0);
    });

    it('shows the field error of the Action under the modal field', function (): void {
        $client = InvoiceFixtures::client();

        Livewire::test(ListInvoices::class)
            ->callAction('createDraft', [
                'client_id' => $client->id,
                'kind' => 'invoice',
                'source' => 'time',
                'period' => 'custom',
                'period_from' => '2026-09-30',
                'period_to' => '2026-09-01',
                'project_ids' => [],
                'without_project' => true,
                'grouping' => 'project',
            ])
            ->assertHasActionErrors(['period_to']);

        expect(modalDraftOf($client))->toBeNull();
    });

    it('lists the new draft', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client);

        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$draft]);
    });

    it('sends the view address of a draft to the editor, the detail being the page of an issued document (plan 10-29)', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);

        $this->get(InvoiceResource::getUrl('view', ['record' => $draft]))->assertRedirect(InvoiceResource::getUrl('edit', ['record' => $draft]));

        Livewire::test(ViewInvoice::class, ['record' => $draft->getRouteKey()])->assertRedirect(InvoiceResource::getUrl('edit', ['record' => $draft]));
    });
});

describe('access', function (): void {
    it('answers a Partner with 403 on the list and the view page', function (): void {
        $client = InvoiceFixtures::client();
        $issued = InvoiceFixtures::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        $this->get(InvoiceResource::getUrl('index'))->assertForbidden();
        $this->get(InvoiceResource::getUrl('view', ['record' => $issued]))->assertForbidden();
    });

    it('is absent from the global search', function (): void {
        expect(InvoiceResource::canGloballySearch())->toBeFalse();
    });

    it('declares the Admin-only navigation entry', function (): void {
        expect(InvoiceResource::getNavigationSort())->toBe(50)
            ->and(InvoiceResource::getNavigationLabel())->toBe('Faktury');
    });
});
