<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\CreateDraftAction;
use App\Filament\Support\InvoiceTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The tab "Faktury" of the Admin client page (CL-03, UI-SPEC Surface I): the documents of this client, drafts included,
 * with the columns and the row actions of the Admin list of documents, built by the same {@see InvoiceTable} so the two
 * can never read a document differently. The "Klient" column is dropped, the filters are Druh and Stav, and the footer
 * "K úhradě" adds the remaining amounts per currency. The header action "Nová faktura" opens the create modal with the
 * client locked; the badge on the tab is the overdue count, in danger. Admin only.
 *
 * The table does not write a row: the draft is created by {@see CreateDraftAction} through the domain Action and the
 * row actions are the shared ones.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Documents, amounts owed and payment state of a client are finance data; a Partner reads own documents on its own surface only.')]
final class ClientInvoicesRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'invoices';

    /**
     * The Admin works documents from the tab (the create and the delete-draft actions), so Filament's read-only
     * default for view pages does not apply.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('invoices.client_panel.tabs.invoices');
    }

    /**
     * The overdue documents of the client, shown on the tab in danger; no badge while nothing is overdue.
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $overdue = app(ReceivablesReader::class)->tabCounts(ReceivablesReader::today(), (string) $ownerRecord->getKey())['overdue'];

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        $client = $this->getOwnerRecord();
        assert($client instanceof Client);

        return InvoiceTable::configure($table, withClientColumn: false)
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(static fn (Builder $query): Builder => InvoiceResource::withListRelations($query))
            ->headerActions([CreateDraftAction::make($client)])
            // The header action is the one place to create a document here, with the client locked.
            ->emptyStateActions([])
            ->emptyStateHeading(__('invoices.client_panel.invoices.empty_heading'))
            ->emptyStateDescription(__('invoices.client_panel.invoices.empty_body'));
    }
}
