<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Dashboard\Actions\SetDashboardWidgetCollapsed;
use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Dashboard\Queries\CollapsedWidgets;
use App\Domain\Identity\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * Makes a dashboard widget a collapsible card whose state is stored per user (quick task 261015-cv5).
 *
 * The widget view renders `<x-dashboard-card>` with `$this->collapsed`, so the stored state is applied in the first
 * render. A toggle in the browser calls `storeCollapsedState()` in the background: the card is optimistic, silent on
 * success, and goes back to the returned state when the write failed.
 *
 * The card key never comes from the browser: it is the widget's own `dashboardWidget()`, and the user is the signed-in
 * one. `collapsed` is locked, so a forged request cannot write it. Access to the widget itself is decided at boot by
 * EnforcesWidgetAccessRule, before the mount hook or the store method can run.
 */
trait CollapsibleDashboardWidget
{
    /** Whether the signed-in user keeps the card collapsed: a scalar UI state, no data. */
    #[Locked]
    public bool $collapsed = false;

    /**
     * The key under which the card's state is stored.
     */
    abstract public static function dashboardWidget(): DashboardWidget;

    /**
     * The card title.
     */
    abstract protected function cardHeading(): string;

    /**
     * The one key figure that stays in the header while the card is collapsed; protected, so the browser cannot call it.
     */
    abstract protected function collapsedSummary(): ?string;

    /**
     * A link that stays in the header of a table card: its label and url, or null for none.
     *
     * @return array{label: string, url: string}|null
     */
    protected function headerLink(): ?array
    {
        return null;
    }

    public function mountCollapsibleDashboardWidget(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $this->collapsed = app(CollapsedWidgets::class)->isCollapsed($user, static::dashboardWidget());
    }

    /**
     * Stores the state of this card for the signed-in user and returns the state that now holds: the new one, or the
     * unchanged one when the write failed (the card then goes back and a small error is shown).
     */
    #[Renderless]
    public function storeCollapsedState(bool $collapsed): bool
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        try {
            app(SetDashboardWidgetCollapsed::class)->handle($user, static::dashboardWidget(), $collapsed);
        } catch (QueryException $exception) {
            report($exception);

            Notification::make()
                ->title(__('kokpit.dashboard.collapse.store_failed'))
                ->danger()
                ->send();

            return $this->collapsed;
        }

        return $this->collapsed = $collapsed;
    }
}
