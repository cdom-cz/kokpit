<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Enums\BillingBadge;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\TimeEntryResource\Pages\EditTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * The markers of reserved and invoiced time on the Admin time screens and the reserved part of the unbilled
 * report tile (IN-02; D-04, ruling R-06-3; UI-SPEC Surface M, F-13). Every name and number is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague; every entry below lies in October 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * A finished billable entry of the exact seconds on a day of October 2026 (UTC morning).
 */
function markerEntry(Client $client, int $seconds, int $day = 5): TimeEntry
{
    $start = CarbonImmutable::parse('2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 08:00:00', 'UTC');

    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'client_id' => $client->id,
        'started_at' => $start,
        'ended_at' => $start->addSeconds($seconds),
    ])->refresh());
}

/**
 * Holds the entry by the draft, as the draft editor does.
 */
function markerReserve(TimeEntry $entry, Invoice $draft): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($entry, $draft): void {
        DB::transaction(static function () use ($entry, $draft): void {
            app(EntryBilling::class)->reserve(TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->get(), $draft->id);
        });
    });
}

/**
 * A client with a draft that holds one entry, and that entry.
 *
 * @return array{client: Client, draft: Invoice, entry: TimeEntry}
 */
function markerReservedWorld(int $seconds = 3600): array
{
    $client = InvoiceFixtures::client(['hourly_rate' => Money::fromMajor('1500', 'CZK')]);
    $entry = markerEntry($client, $seconds);
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '1500.00')]);
    markerReserve($entry, $draft);

    return ['client' => $client, 'draft' => $draft, 'entry' => $entry->refresh()];
}

it('marks a reserved entry V konceptu in the list and leaves a free one without the badge', function (): void {
    ['client' => $client, 'entry' => $reserved] = markerReservedWorld();
    $free = markerEntry($client, 1800, 6);

    Livewire::test(ListTimeEntries::class)
        ->assertCanSeeTableRecords([$reserved, $free])
        ->assertTableColumnStateSet('reserved', 'V konceptu', $reserved)
        ->assertTableColumnStateSet('reserved', null, $free)
        ->assertTableColumnStateSet('billing_badge', BillingBadge::Unbilled, $reserved)
        ->assertSee('Nevyfakturováno')
        ->assertSee('Záznam je připojen ke konceptu faktury.');
});

it('shows the V konceptu badge on the view page of a reserved entry and not on a free one', function (): void {
    ['client' => $client, 'entry' => $reserved] = markerReservedWorld();
    $free = markerEntry($client, 1800, 6);

    Livewire::test(ViewTimeEntry::class, ['record' => $reserved->id])
        ->assertSee('V konceptu')
        ->assertSee('Nevyfakturováno');
    Livewire::test(ViewTimeEntry::class, ['record' => $free->id])
        ->assertDontSee('V konceptu');
});

it('links the edit page of a reserved entry to the draft editor and offers no link for a free one', function (): void {
    ['client' => $client, 'draft' => $draft, 'entry' => $reserved] = markerReservedWorld();
    $free = markerEntry($client, 1800, 6);

    Livewire::test(EditTimeEntry::class, ['record' => $reserved->id])
        ->assertActionVisible('openDraft')
        ->assertActionHasLabel('openDraft', 'Otevřít koncept')
        ->assertActionHasUrl('openDraft', InvoiceResource::getUrl('edit', ['record' => $draft]));
    Livewire::test(EditTimeEntry::class, ['record' => $free->id])
        ->assertActionHidden('openDraft');
});

it('shows Faktura plus the number as a link on the view page of an invoiced entry and no link for a manually billed one', function (): void {
    InvoiceFixtures::configureSupplier();
    ['client' => $client, 'draft' => $draft, 'entry' => $entry] = markerReservedWorld();
    $manual = markerEntry($client, 1800, 6);
    app(MarkEntriesBilled::class)->handle($this->admin, [$manual->id]);

    $invoice = app(IssueDocument::class)->handle($this->admin, $draft);
    $number = (string) $invoice->number;

    expect($entry->refresh()->isBilled())->toBeTrue()
        ->and($number)->not->toBe('');

    Livewire::test(ViewTimeEntry::class, ['record' => $entry->id])
        ->assertSee('Faktura '.$number)
        ->assertSee(InvoiceResource::getUrl('view', ['record' => $invoice]))
        ->assertDontSee('V konceptu');
    Livewire::test(ViewTimeEntry::class, ['record' => $manual->id])
        ->assertDontSee('Faktura ');
});

it('bills the free entry of a bulk selection and names the skipped reserved one in the toast', function (): void {
    ['client' => $client, 'entry' => $reserved] = markerReservedWorld();
    $free = markerEntry($client, 1800, 6);

    Livewire::test(ListTimeEntries::class)
        ->callTableBulkAction('markBilled', [$reserved, $free])
        ->assertNotified(
            Notification::make()
                ->success()
                ->title('Označeno jako vyfakturované (1)')
                ->body('Přeskočen 1 záznam připojený ke konceptu faktury. Odpojte ho v konceptu.'),
        );

    expect($free->refresh()->isBilled())->toBeTrue()
        ->and($reserved->refresh()->isBilled())->toBeFalse()
        ->and($reserved->isReserved())->toBeTrue();
});

/**
 * The report of October 2026 as the page builds it.
 */
function markerReport(): TimeReport
{
    return TimeReport::build(ReportFilters::period('2026-10-01', '2026-10-31'), CarbonImmutable::now());
}

it('names the 2 h reserved part of 5 h unbilled time in the report summary and in the tile', function (): void {
    ['client' => $client, 'entry' => $reserved] = markerReservedWorld(7200);
    markerEntry($client, 10800, 6);

    $summary = markerReport()->summary;

    expect($reserved->isReserved())->toBeTrue()
        ->and($summary['unbilled'])->toBe(18000)
        ->and($summary['reserved'])->toBe(7200);

    $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=this_month')->assertOk()->getContent();
    $text = (string) preg_replace('/\s+/u', ' ', strip_tags(str_replace('>', '> ', $html)));

    expect($text)
        ->toContain('Nevyfakturováno')
        ->toContain('5:00')
        ->toContain('z toho v konceptech: 2:00');
});

it('omits the reserved line of the tile when no reserved time exists in the period', function (): void {
    $client = InvoiceFixtures::client();
    markerEntry($client, 3600, 5);

    expect(markerReport()->summary['reserved'])->toBe(0);

    $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=this_month')->assertOk()->getContent();

    expect($html)->not->toContain('z toho v konceptech');
});

it('counts the reserved seconds as exactly the seconds of the entries flagged reserved, billed and free ones apart', function (): void {
    ['client' => $client] = markerReservedWorld(3600);
    $second = markerEntry($client, 900, 7);
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example more work', '1', '375.00')]);
    markerReserve($second, $draft);
    markerEntry($client, 600, 8);
    $billed = markerEntry($client, 1200, 9);
    app(MarkEntriesBilled::class)->handle($this->admin, [$billed->id]);

    $summary = markerReport()->summary;

    // 1:00 + 0:15 reserved, 0:10 free; the billed 0:20 is never part of the reserved or the unbilled sum.
    expect($summary['reserved'])->toBe(4500)
        ->and($summary['unbilled'])->toBe(5100)
        ->and($summary['billed'])->toBe(1200);
});
