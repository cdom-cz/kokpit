<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Widgets\HoursStats;
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

        expect($html)->toContain('wire:poll.60s')
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
