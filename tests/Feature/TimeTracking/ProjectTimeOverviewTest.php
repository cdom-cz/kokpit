<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\Widgets\ProjectTimeStats;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin project time overview (PR-05): the stats row, the task tab and the entry tab, and
 * their absence from every Partner surface. Every name is fictional; no money is shown.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * A project of a new client, written through the domain Action; the estimate is in hours.
 *
 * @param  array<string, mixed>  $overrides
 */
function overviewProject(?string $estimateHours = null, array $overrides = []): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example overview '.mb_strtolower(Canary::projectKey()),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'estimate_hours' => $estimateHours,
        ...$overrides,
    ]);
}

/**
 * A stored entry of the signed-in Admin on the project, from UTC instants; an empty end is a
 * running entry.
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewEntry(Project $project, string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->forProject($project)->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

/**
 * Billed time as the Admin would have it: a finished billable entry in the state "billed".
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewBilled(Project $project, string $start, string $end, array $attributes = []): TimeEntry
{
    return overviewEntry($project, $start, $end, [
        'billable' => true,
        'billing_state' => 'billed',
        'billed_at' => CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC'),
        ...$attributes,
    ]);
}

/**
 * The visible words of a rendered page: tags, comments and extra white space removed.
 */
function overviewText(string $html): string
{
    $html = preg_replace(['/<!--.*?-->/s', '/<(script|style)\b.*?<\/\1>/s'], '', $html) ?? $html;
    $html = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($html)) ?? $html);
}

/**
 * The stats row of the project as visible text.
 */
function overviewStats(Project $project): string
{
    return overviewText(Livewire::test(ProjectTimeStats::class, ['record' => $project])->assertSuccessful()->html());
}

describe('the stats row', function (): void {
    it('shows the estimate, the worked, billed and unbilled time with the running entry at its elapsed time', function (): void {
        $project = overviewProject('10');

        overviewBilled($project, '2026-10-12 08:00:00', '2026-10-12 10:00:00'); // 2:00 billed
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:30:00'); // 1:30 billable, unbilled
        overviewEntry($project, '2026-10-12 13:00:00', '2026-10-12 13:30:00', ['billable' => false]); // 0:30
        overviewEntry($project, '2026-10-14 09:45:00', ''); // running for 15 minutes

        $text = overviewStats($project);

        expect($text)->toContain('Odhad 10:00')
            ->and($text)->toContain('Odpracováno 4:15 42 % odhadu')
            ->and($text)->toContain('Vyfakturováno 2:00')
            ->and($text)->toContain('Nevyfakturováno 1:45 Nefakturovatelné: 0:30');
    });

    it('keeps the identity worked = billed + unbilled + non-billable in exact seconds', function (): void {
        $project = overviewProject('10');
        overviewBilled($project, '2026-10-12 08:00:00', '2026-10-12 08:59:59');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 11:30:01');
        overviewEntry($project, '2026-10-12 13:00:00', '2026-10-12 13:00:07', ['billable' => false]);
        overviewEntry($project, '2026-10-14 09:59:00', '');

        $totals = app(TimeTotals::class)->forProject($project, CarbonImmutable::now());

        expect($totals['worked'])->toBe(3599 + 1801 + 7 + 60)
            ->and($totals['billed'] + $totals['unbilled'] + $totals['non_billable'])->toBe($totals['worked'])
            ->and($totals['billed'])->toBe(3599)
            ->and($totals['unbilled'])->toBe(1801 + 60)
            ->and($totals['non_billable'])->toBe(7)
            ->and($totals['estimate'])->toBe(36000);
    });

    it('counts the time of the project only, not that of another project', function (): void {
        $project = overviewProject('10');
        $other = overviewProject('10');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:00:00');
        overviewEntry($other, '2026-10-12 11:00:00', '2026-10-12 12:30:00');

        expect(overviewStats($project))->toContain('Odpracováno 1:00')
            ->and(overviewStats($other))->toContain('Odpracováno 1:30');
    });

    it('says how far over the estimate the project is, in danger text', function (): void {
        $project = overviewProject('1');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:45:00');

        $html = Livewire::test(ProjectTimeStats::class, ['record' => $project])->html();

        expect(overviewText($html))->toContain('Odpracováno 1:45 Překročeno o 0:45')
            ->and($html)->toContain('fi-color-danger');
    });

    it('treats an estimate of 0 as a value: any time exceeds it and there is no percent', function (): void {
        $project = overviewProject('0');

        expect(overviewStats($project))->toContain('Odhad 0:00')
            ->not->toContain('Odhad není zadaný')
            ->not->toContain('% odhadu')
            ->not->toContain('Překročeno');

        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 11:20:00');

        expect(overviewStats($project))->toContain('Překročeno o 0:20')
            ->not->toContain('% odhadu');
    });

    it('shows a dash and "Odhad není zadaný" without an estimate, and zero time on an empty project', function (): void {
        $text = overviewStats(overviewProject());

        expect($text)->toContain('Odhad — Odhad není zadaný')
            ->and($text)->toContain('Odpracováno 0:00')
            ->and($text)->toContain('Vyfakturováno 0:00')
            ->and($text)->toContain('Nevyfakturováno 0:00 Nefakturovatelné: 0:00')
            ->not->toContain('% odhadu');
    });

    it('rounds the percent down, never up', function (): void {
        $project = overviewProject('1');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 11:59:59'); // 99.97 %

        expect(overviewStats($project))->toContain('99 % odhadu');
    });

    it('is on the Admin project page and shows no money', function (): void {
        $project = overviewProject('2');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:00:00');

        $html = $this->get(ProjectResource::getUrl('view', ['record' => $project]))->assertOk()->getContent();
        $text = overviewText((string) $html);

        expect($text)->toContain('Odhad 2:00')
            ->and($text)->toContain('Odpracováno 1:00 50 % odhadu')
            ->and($text)->not->toMatch('/\d\s*(Kč|CZK|EUR|€)/u');
    });

    it('does not tick: the widget has no polling', function (): void {
        $project = overviewProject('2');

        expect(Livewire::test(ProjectTimeStats::class, ['record' => $project])->html())->not->toContain('wire:poll');
    });
});

describe('the registration', function (): void {
    it('registers the widget on the resource and the page, and keeps the panel widget list empty', function (): void {
        expect(ProjectResource::getWidgets())->toBe([ProjectTimeStats::class])
            ->and(Filament::getPanel('admin')->getWidgets())->toBe([]);

        $project = overviewProject();
        $page = Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])->assertSuccessful();

        expect(overviewText($page->html()))->toContain('Odhad není zadaný');
    });

    it('refuses the widget to a Partner, also when forged', function (): void {
        [$clientA] = Canary::twoClients();
        $project = app(CreateProject::class)->handle(Client::query()->findOrFail($clientA), [
            'name' => 'Example partner '.mb_strtolower(Canary::projectKey()),
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'estimate_hours' => '5',
            'client_visible' => true,
        ]);

        $this->actingAs(Canary::partnerFor($clientA));

        expect(ProjectTimeStats::canView())->toBeFalse();
        Livewire::test(ProjectTimeStats::class, ['record' => $project])->assertForbidden();
    });

    it('shows a Partner none of the overview on the own project page', function (): void {
        [$clientA] = Canary::twoClients();
        $project = app(CreateProject::class)->handle(Client::query()->findOrFail($clientA), [
            'name' => 'Example partner '.mb_strtolower(Canary::projectKey()),
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'estimate_hours' => '5',
            'client_visible' => true,
        ]);
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:00:00');

        $html = $this->actingAs(Canary::partnerFor($clientA))
            ->get('/admin/my-projects/'.$project->getRouteKey())
            ->assertOk()
            ->getContent();

        foreach (['Odpracováno', 'Vyfakturováno', 'Nevyfakturováno', 'Odhad', 'Úkoly a čas', 'Časové záznamy'] as $word) {
            expect((string) $html)->not->toContain($word);
        }
    });
});
