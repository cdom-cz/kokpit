<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerInvoiceResource\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\CashReceiptPdfUrl;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The read-only "Příjmové doklady" tab of an own issued invoice or proforma (Phase 15, D-13, AS-4): the cash receipts
 * of the document with their number, date received and printed amount, and whether the receipt was cancelled, each
 * with a download of its PDF.
 *
 * The relation goes through the Partner-scoped {@see CashReceipt} model, so a receipt of another client, of a draft or
 * of an archived client is not part of the query. A cancelled receipt stays listed and is marked (AS-4); the reason of
 * the cancel is not on the row and so not on this screen. No column or action names the payment, the reason or the lock
 * state, and the tab offers no header, bulk, create, edit or delete action, no filter and no search.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner reads the cash receipts of the own issued documents, read-only: number, date received, amount and whether the receipt was cancelled.')]
final class PartnerCashReceiptsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule {
        canViewForRecord as private canViewForDeclared;
    }

    protected static string $relationship = 'cashReceipts';

    /**
     * The declaration AND the policy AND a Partner with a client AND an issued invoice or proforma.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::canViewForDeclared($ownerRecord, $pageClass)
            && app(PartnerContext::class)->partnerClientId() !== null
            && $ownerRecord instanceof Invoice
            && $ownerRecord->isIssued()
            && $ownerRecord->kind->carriesPayment();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('cash_receipts.partner.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')
                    ->label(__('cash_receipts.partner.columns.number')),
                TextColumn::make('received_on')
                    ->label(__('cash_receipts.partner.columns.received_on'))
                    ->date('j. n. Y'),
                TextColumn::make('amount')
                    ->label(__('cash_receipts.partner.columns.amount'))
                    ->state(static fn (CashReceipt $record): string => $record->amount()->format('cs'))
                    ->alignEnd(),
                TextColumn::make('state')
                    ->label(__('cash_receipts.partner.columns.state'))
                    ->state(static fn (CashReceipt $record): string => $record->isCancelled()
                        ? (string) __('cash_receipts.partner.state.cancelled')
                        : (string) __('cash_receipts.partner.state.valid'))
                    ->badge()
                    ->color(static fn (CashReceipt $record): string => $record->isCancelled() ? 'danger' : 'success'),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderBy('issued_on')->orderBy('number'))
            ->paginated(false)
            ->recordActions([$this->downloadAction()])
            ->emptyStateHeading(__('cash_receipts.partner.empty_heading'))
            ->emptyStateDescription(__('cash_receipts.partner.empty_body'));
    }

    /**
     * "Stáhnout PDF": the signed, short-lived link of the receipt PDF route, built for the signed-in user at click time
     * and followed by a redirect, never rendered into the page. The receipt is read again through the invoice of this
     * tab, so an id of another document reaches nothing.
     */
    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('cash_receipts.partner.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (CashReceipt $record): void {
                abort_unless(PartnerInvoiceResource::canAccess(), 403);

                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                $owner = $this->getOwnerRecord();
                assert($owner instanceof Invoice);

                $receipt = $owner->cashReceipts()->whereKey($record->getKey())->first();

                abort_unless($receipt instanceof CashReceipt, 404);

                $this->redirect(CashReceiptPdfUrl::download($receipt, $user));
            });
    }
}
