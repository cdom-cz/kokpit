<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\UnbilledPerClient;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin dashboard (RE-04; D-11, UI-SPEC Surface E): the hours worked today, this week and this month, and
 * the unbilled billable time of the month per client. Every name, rate and amount below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    // Wednesday 2026-10-14, 10:00 in Prague (08:00 UTC). The week is 12. 10. to 18. 10.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

/**
 * Stores an entry of a user from UTC instants, in a system run. An empty end makes it a running entry.
 *
 * @param  array<string, mixed>  $attributes
 */
function adminDashEntry(User $user, string $start, string $end, array $attributes = []): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ]));
}

/**
 * The visible text of a rendered page: icons, comments and scripts removed, tags stripped, white space
 * (the no-break spaces of the money format included) collapsed.
 */
function adminDashText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

/**
 * A fictional client with a rate in its own currency.
 */
function adminDashClient(string $name, string $currency = 'EUR', string $rate = '80'): Client
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create([
        'name' => $name,
        'currency' => $currency,
        'hourly_rate' => Money::fromMajor($rate, $currency),
    ]));
}

/**
 * A fixed-price project of the client, written through the domain Action so its billing row exists.
 */
function adminDashFixedProject(Client $client, string $key): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle($client, [
        'name' => 'Example Project '.$key,
        'key' => $key,
        'billing_type' => 'fixed_price',
        'fixed_price' => '5000',
    ]));
}

/**
 * An entry of the signed-in Admin on a client or a project, from UTC instants.
 *
 * @param  array<string, mixed>  $attributes
 */
function adminDashClientEntry(Client|Project $target, string $start, string $end, array $attributes = [], bool $billed = false): TimeEntry
{
    $factory = $target instanceof Project ? TimeEntry::factory()->forProject($target) : TimeEntry::factory()->state(['client_id' => $target->id]);

    if ($billed) {
        $factory = $factory->billed();
    }

    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => $factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ]));
}

/**
 * Stores one invented CNB list row, as the download Action does.
 */
function adminDashRate(string $date, string $currency, string $rate): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($date, $currency, $rate): void {
        ExchangeRate::query()->insert([
            'rate_date' => $date,
            'currency' => $currency,
            'unit_amount' => 1,
            'rate' => $rate,
            'fetched_at' => $date.' 14:45:00+00',
            'created_at' => $date.' 14:45:00+00',
            'updated_at' => $date.' 14:45:00+00',
        ]);
    });
}

/**
 * A money amount as the page prints it, white space collapsed like the page text.
 */
function adminDashMoney(int $minor, string $currency): string
{
    return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', Money::ofMinor($minor, $currency)->format('cs'));
}

/**
 * The HTML of the unbilled widget alone, as the Admin gets it: the rest of the dashboard and of the panel shell
 * (the timer bar and the list of recent entries name clients too) stays out of the assertions.
 */
function adminDashUnbilledHtml(): string
{
    return Livewire::actingAs(test()->admin)->test(UnbilledPerClient::class)->html();
}

describe('the hours of the Admin', function (): void {
    it('shows today, this week and this month as H:MM with a running timer at its elapsed time', function (): void {
        // Today 1:00 finished and a timer running for 0:30; Monday 2:00; 2. 10. 3:00; 30. 9. 4:00 (last month).
        adminDashEntry($this->admin, '2026-10-14 06:00:00', '2026-10-14 07:00:00');
        adminDashEntry($this->admin, '2026-10-14 07:30:00', '');
        adminDashEntry($this->admin, '2026-10-12 06:00:00', '2026-10-12 08:00:00');
        adminDashEntry($this->admin, '2026-10-02 06:00:00', '2026-10-02 09:00:00');
        adminDashEntry($this->admin, '2026-09-30 06:00:00', '2026-09-30 10:00:00');

        // Another user's time is never the Admin's own.
        adminDashEntry(User::factory()->create(), '2026-10-14 06:00:00', '2026-10-14 07:00:00');

        $text = adminDashText((string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent());

        expect($text)
            ->toContain('Dnes 1:30 Včetně běžícího časovače')
            ->toContain('Tento týden 3:30 12. 10. – 18. 10.')
            ->toContain('Tento měsíc 6:30 Fakturovatelné 6:30')
            ->not->toContain('Odpracováno dnes');
    });

    it('says "Odpracováno dnes" when no timer runs', function (): void {
        adminDashEntry($this->admin, '2026-10-14 06:00:00', '2026-10-14 07:00:00');

        $text = adminDashText((string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent());

        expect($text)->toContain('Dnes 1:00 Odpracováno dnes')
            ->not->toContain('Včetně běžícího časovače');
    });

    it('counts non-billable time in the month total but not in its billable line', function (): void {
        adminDashEntry($this->admin, '2026-10-05 06:00:00', '2026-10-05 08:00:00');
        adminDashEntry($this->admin, '2026-10-06 06:00:00', '2026-10-06 06:30:00', ['billable' => false]);

        $text = adminDashText((string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent());

        expect($text)->toContain('Tento měsíc 2:30 Fakturovatelné 2:00');
    });

    it('starts a Prague day at Prague midnight, whatever the UTC date says', function (): void {
        // 2026-10-14 is CEST (UTC+2): Prague midnight is 2026-10-13 22:00 UTC.
        adminDashEntry($this->admin, '2026-10-13 21:00:00', '2026-10-13 21:59:59'); // yesterday 23:00 Prague
        adminDashEntry($this->admin, '2026-10-13 22:00:00', '2026-10-13 22:20:00'); // today 00:00 Prague, 0:20
        adminDashEntry($this->admin, '2026-10-14 21:59:00', '2026-10-14 21:59:30'); // today 23:59 Prague, 30 seconds
        adminDashEntry($this->admin, '2026-10-14 22:00:00', '2026-10-14 22:10:00'); // tomorrow 00:00 Prague

        $text = adminDashText((string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent());

        // Today holds the two entries of 20 minutes and 30 seconds; the 10 minutes after midnight are tomorrow's.
        expect($text)->toContain('Dnes 0:20');
    });

    it('treats the week as Monday to Sunday', function (): void {
        // Sunday 11. 10. belongs to the week before, Sunday 18. 10. to this one.
        adminDashEntry($this->admin, '2026-10-11 06:00:00', '2026-10-11 07:00:00');
        adminDashEntry($this->admin, '2026-10-18 06:00:00', '2026-10-18 08:00:00');
        adminDashEntry($this->admin, '2026-10-19 06:00:00', '2026-10-19 08:00:00');

        $text = adminDashText((string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent());

        expect($text)->toContain('Tento týden 2:00 12. 10. – 18. 10.');
    });

    it('renders zero hours on an empty day and no band for invoices and payments', function (): void {
        $text = adminDashText((string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent());

        expect($text)->toContain('Dnes 0:00 Odpracováno dnes')
            ->toContain('Tento týden 0:00')
            ->toContain('Tento měsíc 0:00 Fakturovatelné 0:00')
            ->not->toContain('Nezaplacené faktury')
            ->not->toContain('Nevyřešené platby')
            ->not->toContain(__('kokpit.dashboard.empty_heading'));
    });

    it('listens to the four time events and reads the figures again after one', function (): void {
        $component = Livewire::actingAs($this->admin)->test(HoursStats::class)->assertSee('0:00');

        expect(SupportEvents::getListenerEventNames($component->instance()))
            ->toEqualCanonicalizing(['timer-started', 'timer-stopped', 'time-entry-saved', 'time-entry-deleted']);

        adminDashEntry($this->admin, '2026-10-14 06:00:00', '2026-10-14 07:00:00');

        // The stored entry shows up only on the render that the event causes.
        $component->assertDontSee('1:00')->dispatch('time-entry-saved')->assertSee('1:00');
    });

    it('polls every 60 seconds and ticks nowhere', function (): void {
        $html = Livewire::actingAs($this->admin)->test(HoursStats::class)->html();

        expect($html)->toContain('wire:poll.visible.60s')
            ->not->toContain('wire:poll.5s');
    });

    it('is refused to a Partner, also when the widget is mounted by force', function (): void {
        $partner = Canary::partnerFor(Canary::twoClients()[0]);

        Livewire::actingAs($partner)->test(HoursStats::class)->assertForbidden();
    });

    it('shows a Partner none of the Admin headings', function (): void {
        $partner = Canary::partnerFor(Canary::twoClients()[0]);

        $text = adminDashText((string) $this->actingAs($partner)->get('/admin')->assertOk()->getContent());

        expect($text)->not->toContain('Tento týden')
            ->not->toContain('Tento měsíc')
            ->not->toContain('Včetně běžícího časovače')
            ->not->toContain('Odpracováno dnes');
    });
});

describe('the unbilled time of the month per client', function (): void {
    /**
     * Client A: 2:00 on 5. 10. at the list of that day (25.000) and 1:00 on 7. 10. (24.000), 80.00 EUR an hour; a
     * billed entry, a non-billable one and one of last month that are not counted.
     */
    function adminDashClientA(): Client
    {
        $client = adminDashClient('Example Client Alpha');

        adminDashRate('2026-10-05', 'EUR', '25.000');
        adminDashRate('2026-10-07', 'EUR', '24.000');

        adminDashClientEntry($client, '2026-10-05 08:00:00', '2026-10-05 10:00:00');
        adminDashClientEntry($client, '2026-10-07 08:00:00', '2026-10-07 09:00:00');
        adminDashClientEntry($client, '2026-10-06 08:00:00', '2026-10-06 12:00:00', billed: true);
        adminDashClientEntry($client, '2026-10-08 08:00:00', '2026-10-08 11:00:00', ['billable' => false]);
        adminDashClientEntry($client, '2026-09-30 08:00:00', '2026-09-30 15:00:00');

        return $client;
    }

    it('shows the hours, the amount in the client currency and the CZK amount of the unbilled billable entries', function (): void {
        adminDashClientA();

        $text = adminDashText(adminDashUnbilledHtml());

        // 2:00 = 160.00 EUR at 25.000 = 4 000,00 Kč and 1:00 = 80.00 EUR at 24.000 = 1 920,00 Kč.
        expect($text)
            ->toContain('Nevyfakturováno tento měsíc')
            ->toContain('Klient Hodiny Částka Částka v Kč')
            ->toContain('Example Client Alpha 3:00 '.adminDashMoney(24000, 'EUR').' '.adminDashMoney(592000, 'CZK'))
            ->toContain('Celkem 3:00 '.adminDashMoney(592000, 'CZK'))
            ->not->toContain('Nic k vyfakturování');
    });

    it('equals the unbilled figures of the report for the same client and month', function (): void {
        $client = adminDashClientA();

        $rows = Livewire::actingAs($this->admin)->test(UnbilledPerClient::class)->instance()->rows();
        $report = TimeReport::build(ReportFilters::period('2026-10-01', '2026-10-31', $client->id), CarbonImmutable::now());

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['seconds'])->toBe($report->summary['unbilled'])
            ->and($rows[0]['czk']?->equals($report->summary['czk']['unbilled'] ?? Money::zero('CZK')))->toBeTrue()
            ->and($rows[0]['amount']?->equals($report->summary['amounts']['unbilled']['EUR']))->toBeTrue();
    });

    it('shows hours and dashes with the "Pevná cena" badge and the note for a client with fixed-price hours only', function (): void {
        $client = adminDashClient('Example Client Fixed');
        $project = adminDashFixedProject($client, 'FIX');
        adminDashClientEntry($project, '2026-10-05 08:00:00', '2026-10-05 09:30:00');

        $html = (string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent();
        $text = adminDashText($html);

        expect($text)->toContain('Example Client Fixed Pevná cena 1:30 — —')
            ->toContain('Hodiny s pevnou cenou jsou bez částky.')
            ->not->toContain('Chybí kurz');
    });

    it('shows the amount and the missing-rate marker for a foreign currency without a stored rate', function (): void {
        $client = adminDashClient('Example Client Dollar', 'USD', '50');
        adminDashClientEntry($client, '2026-10-05 08:00:00', '2026-10-05 09:00:00');

        $text = adminDashText(adminDashUnbilledHtml());

        expect($text)->toContain('Example Client Dollar 1:00 '.adminDashMoney(5000, 'USD').' Chybí kurz')
            ->toContain('Součet v Kč nezahrnuje 1 záznamů bez kurzu ČNB.')
            ->not->toContain('Pevná cena');
    });

    it('shows the same amount in both money columns for a CZK client', function (): void {
        $client = adminDashClient('Example Client Crown', 'CZK', '500');
        adminDashClientEntry($client, '2026-10-05 08:00:00', '2026-10-05 09:00:00');

        $text = adminDashText(adminDashUnbilledHtml());

        expect($text)->toContain('Example Client Crown 1:00 '.adminDashMoney(50000, 'CZK').' '.adminDashMoney(50000, 'CZK'));
    });

    it('orders by hours, lists at most 10 clients and totals every client in the footer', function (): void {
        // Twelve clients of 1:00, 2:00 ... 12:00: the two smallest are cut off, the footer counts all of them.
        for ($hours = 1; $hours <= 12; $hours++) {
            $client = adminDashClient('Example Client N'.str_pad((string) $hours, 2, '0', STR_PAD_LEFT), 'CZK', '100');
            adminDashClientEntry($client, '2026-10-05 05:00:00', CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC')->addHours($hours)->format('Y-m-d H:i:s'));
        }

        $rows = Livewire::actingAs($this->admin)->test(UnbilledPerClient::class)->instance()->rows();
        $text = adminDashText(adminDashUnbilledHtml());

        expect($rows)->toHaveCount(10)
            ->and(array_column($rows, 'client_name'))->toBe(array_map(
                static fn (int $hours): string => 'Example Client N'.str_pad((string) $hours, 2, '0', STR_PAD_LEFT),
                range(12, 3),
            ))
            ->and($text)->not->toContain('Example Client N01')
            ->not->toContain('Example Client N02')
            // 1 + 2 + ... + 12 = 78 hours at 100,00 Kč.
            ->toContain('Celkem 78:00 '.adminDashMoney(780000, 'CZK'));
    });

    it('links every row to the report of the client and the month and the heading to the report of the month', function (): void {
        $client = adminDashClientA();

        $html = html_entity_decode(adminDashUnbilledHtml());

        expect($html)->toContain('/admin/time-report?client='.$client->id.'&period=this_month')
            ->toContain('/admin/time-report?period=this_month')
            ->toContain('Zobrazit v reportu');
    });

    it('says "Nic k vyfakturování" when nothing is waiting to be invoiced', function (): void {
        // Only billed, non-billable and older time exists.
        $client = adminDashClient('Example Client Quiet');
        adminDashClientEntry($client, '2026-10-05 08:00:00', '2026-10-05 09:00:00', billed: true);
        adminDashClientEntry($client, '2026-10-06 08:00:00', '2026-10-06 09:00:00', ['billable' => false]);
        adminDashClientEntry($client, '2026-09-30 08:00:00', '2026-09-30 09:00:00');

        $text = adminDashText(adminDashUnbilledHtml());

        expect($text)->toContain('Nic k vyfakturování')
            ->toContain('Tento měsíc nemáte žádný nevyfakturovaný fakturovatelný čas.')
            ->not->toContain('Example Client Quiet');
    });

    it('is part of the Admin dashboard', function (): void {
        adminDashClientA();

        $text = adminDashText((string) $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent());

        expect($text)->toContain('Nevyfakturováno tento měsíc')
            ->toContain('Example Client Alpha 3:00 '.adminDashMoney(24000, 'EUR').' '.adminDashMoney(592000, 'CZK'));
    });

    it('counts the time of every user, not only the Admin\'s own', function (): void {
        $client = adminDashClient('Example Client Shared');
        adminDashClientEntry($client, '2026-10-05 08:00:00', '2026-10-05 09:00:00', ['user_id' => User::factory()->create()->id]);

        $rows = Livewire::actingAs($this->admin)->test(UnbilledPerClient::class)->instance()->rows();

        expect($rows)->toHaveCount(1)->and($rows[0]['seconds'])->toBe(3600);
    });

    it('listens to the four time events and polls every 60 seconds', function (): void {
        $component = Livewire::actingAs($this->admin)->test(UnbilledPerClient::class);

        expect(SupportEvents::getListenerEventNames($component->instance()))
            ->toEqualCanonicalizing(['timer-started', 'timer-stopped', 'time-entry-saved', 'time-entry-deleted'])
            ->and($component->html())->toContain('wire:poll.visible.60s');
    });

    it('is refused to a Partner, also when mounted by force, and absent from the Partner dashboard', function (): void {
        $partner = Canary::partnerFor(Canary::twoClients()[0]);

        Livewire::actingAs($partner)->test(UnbilledPerClient::class)->assertForbidden();

        $text = adminDashText((string) $this->actingAs($partner)->get('/admin')->assertOk()->getContent());

        expect($text)->not->toContain('Nevyfakturováno tento měsíc')
            ->not->toContain('Zobrazit v reportu')
            ->not->toContain('Nic k vyfakturování');
    });
});
