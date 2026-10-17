<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Domain\Invoicing\Enums\InvoiceListTab;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\CreateDraftAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * The list of every document with "Nová faktura" as the one accent action (UI-SPEC Surface A).
 *
 * The tabs are the filters of {@see ReceivablesReader}, and their counts come from its one aggregate query, so the
 * query count does not depend on the number of rows and a tab and its count can never disagree (D-18, D-20). The
 * tab "Zálohy k vyfakturování" is absent when no paid proforma waits for its invoice, which keeps the attention
 * item quiet when nothing is wrong. The list refreshes when a payment or an e-mail state changes elsewhere.
 */
final class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        return __('invoices.navigation.heading');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateDraftAction::make()];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $today = ReceivablesReader::today();
        $counts = app(ReceivablesReader::class)->tabCounts($today);
        $tabs = [];

        foreach (InvoiceListTab::cases() as $tab) {
            $count = $counts[$tab->value] ?? 0;

            // The attention item is absent at zero: no empty tab, no placeholder.
            if ($tab === InvoiceListTab::AwaitingInvoice && $count === 0) {
                continue;
            }

            $tabs[$tab->value] = Tab::make($tab->getLabel())
                ->badge($count)
                ->badgeColor($count > 0 && in_array($tab, [InvoiceListTab::Overdue, InvoiceListTab::AwaitingInvoice], true) ? 'danger' : 'gray')
                ->modifyQueryUsing(static fn (Builder $query): Builder => app(ReceivablesReader::class)->constrain($query, $tab, $today));
        }

        return $tabs;
    }

    /**
     * A payment saved on a document page or an e-mail whose state changed in the queue moves the derived states and
     * the tab counts; the re-render reads them again.
     */
    #[On('invoice-payment-saved')]
    #[On('invoice-email-state-changed')]
    public function refreshDocuments(): void
    {
        // Intentionally empty: the listener only triggers the component to render again.
    }
}
