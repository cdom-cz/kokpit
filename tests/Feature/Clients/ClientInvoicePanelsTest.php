<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\RelationManagers\ClientDocumentsRelationManager;
use App\Filament\RelationManagers\ClientHistoryRelationManager;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ClientInvoicesRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ClientProjectsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\PartnerAccountsRelationManager;
use App\Filament\Resources\ClientResource\Widgets\ClientOverviewStats;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The client detail as the client's overview (CL-03; D-04, D-20, ruling R-06-3, UI-SPEC Surface I): the stats row with
 * the unbilled time (reserved time included, its draft share named), what the client owes and what is overdue. Every
 * name and amount is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Prague'));
});

/**
 * The visible text of a rendered widget: icons, comments and scripts removed, tags stripped, white space (the no-break
 * spaces of the money format included) collapsed.
 */
function clientPanelText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

/**
 * Money as the screens print it, with the white space collapsed the way {@see clientPanelText()} collapses it.
 */
function clientPanelMoney(string $major, string $currency = 'CZK'): string
{
    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', Money::fromMajor($major, $currency)->format('cs')));
}

/**
 * A client in CZK with a rate of 1 000 an hour.
 *
 * @param  array<string, mixed>  $overrides
 */
function clientPanelClient(array $overrides = []): Client
{
    $currency = is_string($overrides['currency'] ?? null) ? $overrides['currency'] : 'CZK';

    return InvoiceFixtures::client(['hourly_rate' => Money::fromMajor('1000', $currency), ...$overrides]);
}

/**
 * A finished billable entry of the client on a Prague day, `$hours` long.
 */
function clientPanelEntry(Client $client, string $day, int $hours, array $attributes = []): TimeEntry
{
    $start = CarbonImmutable::parse($day.' 08:00:00', 'Europe/Prague');

    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => User::factory(),
        'client_id' => $client->id,
        'started_at' => $start,
        'ended_at' => $start->addHours($hours),
        ...$attributes,
    ]));
}

/**
 * Reserves the entries for a new draft of the client, as the Admin does with "Přidat nový čas".
 *
 * @param  list<TimeEntry>  $entries
 */
function clientPanelReserve(Client $client, array $entries): void
{
    $draft = InvoiceFixtures::draft($client);

    app(AddEntriesToDraft::class)->handle(Canary::admin(), $draft, array_map(static fn (TimeEntry $entry): string => $entry->id, $entries));
}

/**
 * The stats widget of a client, mounted as the Admin.
 *
 * @return Livewire\Features\SupportTesting\Testable
 */
function clientPanelStats(Client $client)
{
    return Livewire::test(ClientOverviewStats::class, ['record' => $client]);
}

/**
 * The tracer's world, read on 2026-10-20: 6 h unbilled time of which 2 h are held by a draft, one unpaid invoice of
 * 10 000 and one overdue invoice of 10 000 that is partially paid by 3 000.
 */
function clientPanelWorld(): Client
{
    $client = clientPanelClient(['payment_terms_days' => 30]);

    // Issued on 2026-09-01 it is due on 2026-10-01; the second one, issued on 2026-10-01, is due on 2026-10-31.
    test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'Europe/Prague'));
    $overdue = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '10000.00')]);

    test()->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Prague'));
    InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '10000.00')]);

    clientPanelEntry($client, '2026-09-28', 4);
    $reserved = clientPanelEntry($client, '2026-09-29', 2);
    clientPanelReserve($client, [$reserved]);

    test()->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Europe/Prague'));

    $admin = Canary::admin();
    test()->actingAs($admin);
    app(RecordPayment::class)->handle($admin, $overdue, ['amount' => '3000', 'paid_on' => '2026-10-10']);

    return $client;
}

it('shows the unbilled time with its draft share, what the client owes and what is overdue', function (): void {
    $client = clientPanelWorld();

    $text = clientPanelText(clientPanelStats($client)->assertSuccessful()->html());

    expect($text)
        ->toContain('Nevyfakturováno')
        ->toContain('6:00')
        ->toContain(clientPanelMoney('6000'))
        ->toContain('z toho v konceptech: 2:00')
        ->toContain('Zobrazit v reportu')
        ->toContain('K úhradě')
        ->toContain(clientPanelMoney('17000'))
        ->toContain('z toho po splatnosti: 1')
        ->toContain('Po splatnosti')
        ->toContain(clientPanelMoney('7000'));
});

it('marks the overdue count in danger above zero', function (): void {
    $client = clientPanelWorld();

    $html = clientPanelStats($client)->html();

    expect($html)->toContain('fi-color-danger')
        ->and(clientPanelText($html))->not->toContain('Nic po splatnosti');
});

it('shows nothing owed and nothing overdue for a client without documents', function (): void {
    $client = clientPanelClient();

    $html = clientPanelStats($client)->html();
    $text = clientPanelText($html);

    expect($text)
        ->toContain('Nevyfakturováno')
        ->toContain('0:00')
        ->toContain('Nic po splatnosti')
        ->not->toContain('z toho v konceptech')
        ->and($html)->not->toContain('fi-color-danger');
});

it('mounts the widget on the client page for the Admin and refuses a Partner with 403', function (): void {
    $client = clientPanelClient();

    $this->get(ClientResource::getUrl('view', ['record' => $client->id]))->assertSuccessful();

    Livewire::test(ViewClient::class, ['record' => $client->getKey()])->assertSeeLivewire(ClientOverviewStats::class);

    $this->actingAs(Canary::partnerFor($client->id));

    $this->get(ClientResource::getUrl('view', ['record' => $client->id]))->assertForbidden();
});

/**
 * The projects tab of a client as the Admin sees it on the client page.
 */
function clientPanelProjectsTab(Client $client)
{
    return Livewire::test(ClientProjectsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * The documents tab of a client as the Admin sees it on the client page.
 */
function clientPanelInvoicesTab(Client $client)
{
    return Livewire::test(ClientInvoicesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * A project of the client written through the domain Action, so its billing row exists.
 */
function clientPanelProject(Client $client, string $name, string $billing = 'hourly'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => $billing,
        ...($billing === 'fixed_price' ? ['fixed_price' => '5000'] : ['hourly_rate' => '900']),
    ]);
}

describe('the relation tabs', function (): void {
    it('lists the tabs in the order of the UI-SPEC', function (): void {
        expect(ClientResource::getRelations())->toBe([
            ClientProjectsRelationManager::class,
            ClientInvoicesRelationManager::class,
            ClientDocumentsRelationManager::class,
            ContactsRelationManager::class,
            InvitationsRelationManager::class,
            PartnerAccountsRelationManager::class,
            ClientHistoryRelationManager::class,
        ]);
    });

    it('titles the new tabs Projekty and Faktury', function (): void {
        $client = clientPanelClient();

        expect(ClientProjectsRelationManager::getTitle($client, ViewClient::class))->toBe('Projekty')
            ->and(ClientInvoicesRelationManager::getTitle($client, ViewClient::class))->toBe('Faktury');
    });
});

describe('the Projekty tab', function (): void {
    it('lists the projects of the client with the unbilled time, the fixed-price badge and the archived mark', function (): void {
        $client = clientPanelClient();
        $other = clientPanelClient();
        $hourly = clientPanelProject($client, 'Example hourly project');
        $fixed = clientPanelProject($client, 'Example fixed project', 'fixed_price');
        $archived = clientPanelProject($client, 'Example archived project');
        $foreign = clientPanelProject($other, 'Example foreign project');

        $at = CarbonImmutable::parse('2026-09-28 08:00:00', 'Europe/Prague');
        $entry = static fn (Project $project, array $state = []) => app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->forProject($project)->create([
            'user_id' => test()->admin->id,
            'started_at' => $at,
            'ended_at' => $at->addHours(3),
            ...$state,
        ]));

        $entry($hourly);
        // Not counted: billed, not billable.
        $entry($hourly, ['billing_state' => 'billed', 'billed_at' => $at]);
        $entry($hourly, ['billable' => false]);
        $entry($fixed, ['ended_at' => $at->addHours(1)]);

        app(PartnerContext::class)->runAsSystem(static fn (): ?bool => $archived->delete());

        $tab = clientPanelProjectsTab($client)
            ->assertCanSeeTableRecords([$hourly, $fixed, $archived])
            ->assertCanNotSeeTableRecords([$foreign]);

        $text = clientPanelText($tab->html());

        expect($text)
            ->toContain('Example hourly project')
            ->toContain('3:00')
            ->toContain('1:00')
            ->toContain('Pevná cena')
            ->toContain('Archivovaný')
            ->toContain('Nevyfakturováno');
    });

    it('counts time held by a draft as unbilled', function (): void {
        $client = clientPanelClient();
        $project = clientPanelProject($client, 'Example reserved project');
        $at = CarbonImmutable::parse('2026-09-28 08:00:00', 'Europe/Prague');
        $entry = app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->forProject($project)->create([
            'user_id' => test()->admin->id,
            'started_at' => $at,
            'ended_at' => $at->addHours(2),
        ]));

        clientPanelReserve($client, [$entry]);

        expect(clientPanelText(clientPanelProjectsTab($client)->html()))->toContain('2:00');
    });

    it('pages the projects by ten', function (): void {
        $client = clientPanelClient();

        for ($i = 1; $i <= 12; $i++) {
            clientPanelProject($client, sprintf('Example project %02d', $i));
        }

        $tab = clientPanelProjectsTab($client);

        expect($tab->instance()->getTableRecords())->toHaveCount(10)
            ->and($tab->instance()->getTableRecords()->total())->toBe(12);
    });

    it('shows its empty state for a client without a project', function (): void {
        $text = clientPanelText(clientPanelProjectsTab(clientPanelClient())->html());

        expect($text)->toContain('Klient zatím nemá žádný projekt')->toContain('Projekt vytvoříte v nabídce Projekty.');
    });
});

describe('the Faktury tab', function (): void {
    it('lists only the documents of the client, without the Klient column', function (): void {
        $client = clientPanelClient();
        $other = clientPanelClient();
        $own = InvoiceFixtures::issued($client);
        $draft = InvoiceFixtures::draft($client);
        $foreign = InvoiceFixtures::issued($other);

        $tab = clientPanelInvoicesTab($client)
            ->assertCanSeeTableRecords([$own, $draft])
            ->assertCanNotSeeTableRecords([$foreign]);

        expect($tab->instance()->getTable()->getColumn('client.name'))->toBeNull()
            ->and($tab->instance()->getTable()->getColumn('number'))->not->toBeNull();
    });

    it('filters by kind and by state', function (): void {
        $client = clientPanelClient(['payment_terms_days' => 30]);
        $invoice = InvoiceFixtures::issued($client);
        $proforma = InvoiceFixtures::issued($client, [], InvoiceKind::Proforma);
        $draft = InvoiceFixtures::draft($client);

        clientPanelInvoicesTab($client)
            ->filterTable('kind', InvoiceKind::Proforma->value)
            ->assertCanSeeTableRecords([$proforma])
            ->assertCanNotSeeTableRecords([$invoice, $draft])
            ->resetTableFilters()
            ->filterTable('status', 'drafts')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$invoice, $proforma]);
    });

    it('filters the overdue documents with the overdue state', function (): void {
        $client = clientPanelWorld();
        $overdue = Invoice::query()->where('client_id', $client->id)->orderBy('issue_date')->firstOrFail();
        $unpaid = Invoice::query()->where('client_id', $client->id)->whereKeyNot($overdue->id)->where('state', 'issued')->firstOrFail();

        clientPanelInvoicesTab($client)
            ->filterTable('status', 'overdue')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$unpaid]);
    });

    it('sums what is owed in the footer and badges the overdue count in danger on the tab', function (): void {
        $client = clientPanelWorld();

        $text = clientPanelText(clientPanelInvoicesTab($client)->html());

        expect($text)->toContain('K úhradě')->toContain(clientPanelMoney('17000'))
            ->and(ClientInvoicesRelationManager::getBadge($client, ViewClient::class))->toBe('1')
            ->and(ClientInvoicesRelationManager::getBadgeColor($client, ViewClient::class))->toBe('danger');
    });

    it('shows no badge while nothing is overdue', function (): void {
        $client = clientPanelClient();
        InvoiceFixtures::issued($client);

        expect(ClientInvoicesRelationManager::getBadge($client, ViewClient::class))->toBeNull();
    });

    it('shows its empty state for a client without a document', function (): void {
        $text = clientPanelText(clientPanelInvoicesTab(clientPanelClient())->html());

        expect($text)
            ->toContain('Klient zatím nemá žádný doklad')
            ->toContain('Fakturu vytvoříte tlačítkem Nová faktura. Můžete ji sestavit z nevyfakturovaného času.');
    });

    it('creates a draft for the client from Nová faktura, with the client locked', function (): void {
        $client = clientPanelClient();

        clientPanelInvoicesTab($client)
            ->mountAction(TestAction::make('createDraft')->table())
            ->assertSchemaStateSet(['client_id' => $client->id])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        // Read through the Admin scope: the new draft belongs to the client of the page.
        $draft = Invoice::query()->where('client_id', $client->id)->first();

        expect($draft)->toBeInstanceOf(Invoice::class)->and($draft->isDraft())->toBeTrue();
    });

    it('refuses a forged other client id in the state of the locked modal', function (): void {
        $client = clientPanelClient();
        $other = clientPanelClient();

        clientPanelInvoicesTab($client)
            ->callAction(TestAction::make('createDraft')->table(), [
                'client_id' => $other->id,
                'kind' => 'invoice',
                'source' => 'empty',
            ]);

        expect(Invoice::query()->where('client_id', $other->id)->count())->toBe(0)
            ->and(Invoice::query()->where('client_id', $client->id)->count())->toBe(1);
    });
});
