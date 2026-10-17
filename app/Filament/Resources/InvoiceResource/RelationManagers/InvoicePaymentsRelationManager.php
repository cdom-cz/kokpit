<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\TransactionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Platby" tab of an issued invoice or proforma (IN-05, FI-03; D-17, UI-SPEC Surface F): the payments, newest
 * first, with the way they were paid, the income category they were booked under (a link to the transaction, a dash on
 * a proforma, which books no income) and the note. Admin only: a payment is finance data and a Partner reads none.
 *
 * This is the list only. A stored payment is never edited; the actions of the tab (the payment modal on the page, the
 * deletion of a wrong payment) are added by plan 10-30. A credit note owes nothing and a draft has no payment, so the
 * tab is not offered for them.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Payments are finance data: a Partner never sees a payment, not even of the own invoice.')]
final class InvoicePaymentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule {
        canViewForRecord as private canViewForAdmin;
    }

    protected static string $relationship = 'payments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::canViewForAdmin($ownerRecord, $pageClass)
            && $ownerRecord instanceof Invoice
            && $ownerRecord->isIssued()
            && $ownerRecord->kind->carriesPayment();
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('invoices.payments.tab');
    }

    public function table(Table $table): Table
    {
        $empty = __('invoices.navigation.empty_value');

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('transaction.category'))
            ->columns([
                TextColumn::make('paid_on')
                    ->label(__('invoices.payments.columns.paid_on'))
                    ->date('j. n. Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('invoices.payments.columns.amount'))
                    ->state(static fn (InvoicePayment $record): string => $record->amount()->format('cs'))
                    ->alignEnd(),
                TextColumn::make('method')
                    ->label(__('invoices.payments.columns.method'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('category')
                    ->label(__('invoices.payments.columns.category'))
                    ->state(static fn (InvoicePayment $record): ?string => $record->transaction?->category?->name)
                    ->url(static fn (InvoicePayment $record): ?string => $record->transaction === null ? null : TransactionResource::getUrl('edit', ['record' => $record->transaction]))
                    ->placeholder($empty),
                TextColumn::make('note')
                    ->label(__('invoices.payments.columns.note'))
                    ->placeholder($empty)
                    ->wrap(),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('paid_on')->orderByDesc('id'))
            ->paginated(false)
            ->emptyStateHeading(__('invoices.payments.empty_heading'))
            ->emptyStateDescription(__('invoices.payments.empty_body'));
    }
}
