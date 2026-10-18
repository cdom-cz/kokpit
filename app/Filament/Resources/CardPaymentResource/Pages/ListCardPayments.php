<?php

declare(strict_types=1);

namespace App\Filament\Resources\CardPaymentResource\Pages;

use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Queries\UnresolvedPayments;
use App\Filament\Resources\CardPaymentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * The queue of card payments (UI-SPEC Surface H): "Nevyřešené" first and by default, then the other tabs. The counts of
 * the tabs come from the one aggregate query of {@see UnresolvedPayments}, so the query count does not depend on the
 * number of rows and a tab and its count can never disagree. There is no header action: records are written by the
 * webhook job and resolved on the record view.
 */
final class ListCardPayments extends ListRecords
{
    protected static string $resource = CardPaymentResource::class;

    public function getTitle(): string
    {
        return __('card_payments.navigation.heading');
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = app(UnresolvedPayments::class)->tabCounts();
        $tabs = [];

        foreach (CardPaymentTab::cases() as $tab) {
            $count = $counts[$tab->value] ?? 0;
            $unresolved = $tab === CardPaymentTab::Unresolved;

            // The queue badge is absent at zero: "Nic k řešení" is the quiet success state.
            $tabs[$tab->value] = Tab::make($tab->getLabel())
                ->badge($unresolved && $count === 0 ? null : $count)
                ->badgeColor($unresolved && $count > 0 ? 'danger' : 'gray')
                ->modifyQueryUsing(static fn (Builder $query): Builder => $tab->constrain($query));
        }

        return $tabs;
    }

    /**
     * A resolution made on the record view (another tab, another action) moves the tab counts; the re-render reads them
     * again.
     */
    #[On('card-payment-resolved')]
    public function refreshPayments(): void
    {
        // Intentionally empty: the listener only triggers the component to render again.
    }
}
