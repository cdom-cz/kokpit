<?php

declare(strict_types=1);

use App\Domain\Dashboard\Actions\SetDashboardWidgetCollapsed;
use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Dashboard\Models\DashboardWidgetPreference;
use App\Domain\Dashboard\Queries\CollapsedWidgets;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Widgets\HoursStats;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The collapsible dashboard cards: the state is stored per user, applied in the first render, refused for a Partner
 * on an Admin card, and a failed write goes back and says so. Every name below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    // Wednesday 2026-10-14, 10:00 in Prague (08:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

/**
 * The stored rows of a user, read without the owner scope.
 *
 * @return list<object>
 */
function collapsibleDashRows(User $user, ?DashboardWidget $widget = null): array
{
    $query = DB::table('dashboard_widget_preferences')->where('user_id', $user->id);

    if ($widget !== null) {
        $query->where('widget_key', $widget->value);
    }

    return $query->get()->all();
}

/**
 * A finished entry of a user from UTC instants, in a system run.
 */
function collapsibleDashEntry(User $user, string $start, string $end): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
    ]));
}

describe('the hours card as the Admin', function (): void {
    it('starts expanded without a stored row', function (): void {
        $component = Livewire::actingAs($this->admin)->test(HoursStats::class);

        expect($component->get('collapsed'))->toBeFalse()
            ->and($component->html())->toContain('aria-expanded="true"');
    });

    it('stores a toggle in one row, applies it on the next mount and updates the same row', function (): void {
        Livewire::actingAs($this->admin)->test(HoursStats::class)
            ->call('storeCollapsedState', true)
            ->assertReturned(true);

        $rows = collapsibleDashRows($this->admin);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->widget_key)->toBe('hours_stats')
            ->and($rows[0]->collapsed)->toBeTrue();

        $fresh = Livewire::actingAs($this->admin)->test(HoursStats::class);

        expect($fresh->get('collapsed'))->toBeTrue()
            ->and($fresh->html())->toContain('aria-expanded="false"')
            ->and($fresh->html())->toContain('x-cloak');

        $fresh->call('storeCollapsedState', false)->assertReturned(false);

        $rows = collapsibleDashRows($this->admin);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->collapsed)->toBeFalse();
    });

    it('does not change the card of a second Admin', function (): void {
        Livewire::actingAs($this->admin)->test(HoursStats::class)->call('storeCollapsedState', true);

        $second = Canary::admin();

        expect(Livewire::actingAs($second)->test(HoursStats::class)->get('collapsed'))->toBeFalse()
            ->and(collapsibleDashRows($second))->toBe([]);
    });

    it('refuses a client write to the collapsed property', function (): void {
        $component = Livewire::actingAs($this->admin)->test(HoursStats::class);

        expect(fn () => $component->set('collapsed', true))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(collapsibleDashRows($this->admin))->toBe([]);
    });

    it('shows the heading and the summary of today, and keeps the poll', function (): void {
        collapsibleDashEntry($this->admin, '2026-10-14 06:00:00', '2026-10-14 07:00:00');

        $html = Livewire::actingAs($this->admin)->test(HoursStats::class)->html();

        expect($html)->toContain('Odpracovaný čas')
            ->and($html)->toContain('Dnes 1:00')
            ->and($html)->toContain('wire:poll.visible.60s');
    });
});

describe('a Partner and the hours card', function (): void {
    it('is refused by the Action and the Query, and nothing is written', function (): void {
        [$clientId] = Canary::twoClients();
        $partner = Canary::partnerFor($clientId);

        expect(fn () => app(SetDashboardWidgetCollapsed::class)->handle($partner, DashboardWidget::HoursStats, true))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(CollapsedWidgets::class)->isCollapsed($partner, DashboardWidget::HoursStats))
            ->toThrow(AuthorizationException::class)
            ->and(collapsibleDashRows($partner))->toBe([])
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => DashboardWidgetPreference::query()->count()))->toBe(0);
    });

    it('is forbidden on a forged Livewire mount', function (): void {
        [$clientId] = Canary::twoClients();

        Livewire::actingAs(Canary::partnerFor($clientId))->test(HoursStats::class)->assertForbidden();
    });
});

describe('a failed write', function (): void {
    it('goes back to the previous state, says so in a toast and stores nothing', function (): void {
        app()->bind(SetDashboardWidgetCollapsed::class, fn (): SetDashboardWidgetCollapsed => new class extends SetDashboardWidgetCollapsed
        {
            public function handle(User $actor, DashboardWidget $widget, bool $collapsed): void
            {
                throw new QueryException('pgsql', 'insert into example', [], new RuntimeException('example failure'));
            }
        });

        Livewire::actingAs($this->admin)->test(HoursStats::class)
            ->call('storeCollapsedState', true)
            ->assertReturned(false)
            ->assertNotified(__('kokpit.dashboard.collapse.store_failed'))
            ->assertSet('collapsed', false);

        expect(collapsibleDashRows($this->admin))->toBe([]);
    });
});
