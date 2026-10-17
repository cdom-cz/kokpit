<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\Widgets\ClientOverviewStats;
use Carbon\CarbonImmutable;
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
