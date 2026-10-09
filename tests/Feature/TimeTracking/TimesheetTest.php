<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\TimesheetPage;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The timesheet "Výkaz" (TI-06; D-04, D-05): the day view of the signed-in Admin and the week
 * grid, both on Prague days. Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * A stored entry of the signed-in Admin from UTC instants; an empty end is a running entry.
 *
 * @param  array<string, mixed>  $attributes
 */
function sheetEntry(string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

/**
 * The labels of the navigation items the signed-in user is offered.
 *
 * @return list<string>
 */
function sheetNavigationLabels(): array
{
    $labels = [];

    foreach (Filament::getNavigation() as $group) {
        foreach ($group->getItems() as $item) {
            $labels[] = (string) $item->getLabel();
        }
    }

    return $labels;
}

describe('the day view', function (): void {
    it('lists the entries of today by start with the day total and the summary line', function (): void {
        // Wednesday 2026-10-14, 12:00 in Prague (summer time ended on the 25th, so UTC+2 now).
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $morning = sheetEntry('2026-10-14 06:00:00', '2026-10-14 07:30:00'); // 08:00-09:30
        $later = sheetEntry('2026-10-14 08:00:00', '2026-10-14 08:45:00', ['billable' => false]); // 10:00-10:45
        $running = sheetEntry('2026-10-14 09:40:00', ''); // started 20 minutes ago, 11:40 Prague
        $yesterday = sheetEntry('2026-10-13 06:00:00', '2026-10-13 07:00:00');
        $other = TimeEntry::factory()->create([
            'user_id' => User::factory(),
            'started_at' => CarbonImmutable::parse('2026-10-14 05:00:00', 'UTC'),
            'ended_at' => CarbonImmutable::parse('2026-10-14 06:00:00', 'UTC'),
        ]);

        Livewire::test(TimesheetPage::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$morning, $later, $running], inOrder: true)
            ->assertCanNotSeeTableRecords([$yesterday, $other])
            ->assertSee('Celkem za den')
            ->assertSee('Celkem 2:35 · fakturovatelné 1:50 · nefakturovatelné 0:45')
            ->assertSee('08:00–09:30')
            ->assertSee('11:40–')
            ->assertSee('Běží');
    });

    it('shows the day total over the exact seconds, not the sum of rounded rows', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        // Three stretches of 59 seconds: 0:00 each as a row, 2:57 together read as 0:02.
        sheetEntry('2026-10-14 06:00:00', '2026-10-14 06:00:59');
        sheetEntry('2026-10-14 06:10:00', '2026-10-14 06:10:59');
        sheetEntry('2026-10-14 06:20:00', '2026-10-14 06:20:59');

        Livewire::test(TimesheetPage::class)
            ->assertSee('Celkem 0:02 · fakturovatelné 0:02 · nefakturovatelné 0:00');
    });

    it('shows a dash for the project and the task of a client-only entry', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $client = Client::factory()->create(['name' => 'Example Client One']);
        sheetEntry('2026-10-14 06:00:00', '2026-10-14 07:00:00', ['client_id' => $client->id]);

        $this->get('/admin/timesheet')
            ->assertOk()
            ->assertSee('Example Client One')
            ->assertSee('—');
    });

    it('refuses a Partner and gives it no navigation item', function (): void {
        $client = Client::factory()->create();
        $this->actingAs(Canary::partnerFor($client->id));

        $this->get('/admin/timesheet')->assertForbidden();
        $this->get('/admin/timesheet?view=week&date=2026-10-14')->assertForbidden();

        expect(TimesheetPage::canAccess())->toBeFalse()
            ->and(sheetNavigationLabels())->not->toContain('Výkaz');

        $html = (string) $this->get('/admin')->assertOk()->getContent();
        expect($html)->not->toContain('Výkaz');
    });

    it('gives the Admin a navigation item after the entries', function (): void {
        expect(TimesheetPage::canAccess())->toBeTrue()
            ->and(sheetNavigationLabels())->toContain('Výkaz')
            ->and(TimesheetPage::getNavigationSort())->toBe(41)
            ->and(TimesheetPage::getNavigationLabel())->toBe('Výkaz');

        $this->get('/admin/timesheet')->assertOk()->assertSee('Výkaz');
    });
});
