<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Queries\UnresolvedPayments;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\RelationManagers\CardPaymentHistoryRelationManager;
use App\Filament\Resources\CardPaymentResource\Pages\ListCardPayments;
use App\Filament\Resources\CardPaymentResource\Pages\ViewCardPayment;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * The Admin's "Platby kartou" screen (PA-07, PA-08; D-11 to D-14, D-17; UI-SPEC Surface H): every payment and refund
 * event Stripe reported, whatever happened to it, with the unresolved ones first, and the record view that explains
 * each one.
 *
 * Nothing here writes a row: the list and the view only read {@see CardPayment}, never the webhook table, so pruning old
 * events removes no row (D-17). The resolutions are header actions of the record view, added by a later plan. The
 * records carry Stripe references and the resolution note, so the resource is Admin only and is not globally
 * searchable.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Finance belongs to the Admin: a Partner never sees a card payment, its Stripe references or its resolution')]
final class CardPaymentResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = CardPayment::class;

    protected static ?string $slug = 'card-payments';

    protected static ?int $navigationSort = 52;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('card_payments.navigation.label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedCreditCard;
    }

    public static function getModelLabel(): string
    {
        return __('card_payments.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('card_payments.navigation.plural');
    }

    /**
     * The number of records that wait for the Admin, shown only above zero.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }

        $unresolved = app(UnresolvedPayments::class)->counts()['total'];

        return $unresolved > 0 ? (string) $unresolved : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /**
     * The rows with the documents and clients a row shows loaded for the page at once (an archived client included), so
     * the number of queries does not depend on the number of rows.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invoice.client', 'assignedInvoice.client']);
    }

    public static function getRelations(): array
    {
        return [
            CardPaymentHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardPayments::route('/'),
            'view' => ViewCardPayment::route('/{record}'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('received_at')
                    ->label(__('card_payments.columns.received'))
                    ->dateTime('j. n. Y H:i')
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('card_payments.columns.type'))
                    ->badge()
                    ->color(static fn (CardPaymentType $state): string => self::typeColor($state))
                    ->icon(static fn (CardPaymentType $state): Heroicon => self::typeIcon($state)),
                TextColumn::make('invoice_number')
                    ->label(__('card_payments.columns.invoice'))
                    ->state(static fn (CardPayment $record): ?string => self::documentOf($record)?->number)
                    ->url(static fn (CardPayment $record): ?string => ($document = self::documentOf($record)) === null ? null : InvoiceResource::getUrl('view', ['record' => $document]))
                    ->weight(FontWeight::SemiBold)
                    ->placeholder(__('card_payments.navigation.empty_value')),
                TextColumn::make('client_name')
                    ->label(__('card_payments.columns.client'))
                    ->state(static fn (CardPayment $record): ?string => self::documentOf($record)?->client?->name)
                    ->url(static fn (CardPayment $record): ?string => ($document = self::documentOf($record)) === null ? null : ClientResource::getUrl('view', ['record' => $document->client_id]))
                    ->placeholder(__('card_payments.navigation.empty_value')),
                TextColumn::make('amount')
                    ->label(__('card_payments.columns.amount'))
                    ->state(static fn (CardPayment $record): string => $record->amount()->format('cs'))
                    ->alignEnd(),
                TextColumn::make('reason')
                    ->label(__('card_payments.columns.reason'))
                    ->state(static fn (CardPayment $record): ?string => $record->reason?->getLabel())
                    ->placeholder(__('card_payments.navigation.empty_value')),
                TextColumn::make('status')
                    ->label(__('card_payments.columns.status'))
                    ->state(static fn (CardPayment $record): CardPaymentStatus => $record->status())
                    ->badge()
                    ->color(static fn (CardPaymentStatus $state): string => self::statusColor($state))
                    ->icon(static fn (CardPaymentStatus $state): Heroicon => self::statusIcon($state)),
            ])
            ->defaultSort(static fn (Builder $query, Component $livewire): Builder => self::sorted($query, self::activeTab($livewire)))
            ->recordUrl(static fn (CardPayment $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label(__('card_payments.actions.open')),
            ]);
    }

    /**
     * The document a record is shown against: the assigned one for a record the Admin assigned, otherwise the one the
     * payment named (R11-11-4). Null for an undetermined payment.
     */
    public static function documentOf(CardPayment $record): ?Invoice
    {
        return $record->resolution === CardPaymentResolution::Assigned ? $record->assignedInvoice : $record->invoice;
    }

    public static function typeColor(CardPaymentType $type): string
    {
        return match ($type) {
            CardPaymentType::Matched => 'success',
            CardPaymentType::Duplicate, CardPaymentType::Unmatched => 'danger',
            CardPaymentType::Refund => 'warning',
        };
    }

    public static function typeIcon(CardPaymentType $type): Heroicon
    {
        return match ($type) {
            CardPaymentType::Matched => Heroicon::OutlinedCheckCircle,
            CardPaymentType::Duplicate, CardPaymentType::Unmatched => Heroicon::OutlinedExclamationTriangle,
            CardPaymentType::Refund => Heroicon::OutlinedReceiptRefund,
        };
    }

    public static function statusColor(CardPaymentStatus $status): string
    {
        return match ($status) {
            CardPaymentStatus::Booked => 'success',
            CardPaymentStatus::Unresolved => 'danger',
            CardPaymentStatus::Resolved, CardPaymentStatus::PaymentDeleted => 'gray',
        };
    }

    public static function statusIcon(CardPaymentStatus $status): Heroicon
    {
        return match ($status) {
            CardPaymentStatus::Booked, CardPaymentStatus::Resolved => Heroicon::OutlinedCheckCircle,
            CardPaymentStatus::PaymentDeleted => Heroicon::OutlinedTrash,
            CardPaymentStatus::Unresolved => Heroicon::OutlinedExclamationCircle,
        };
    }

    /**
     * "Nevyřešené" is worked from the oldest record; every other tab shows the newest first. The id breaks a tie so the
     * order is stable.
     *
     * @param  Builder<CardPayment>  $query
     * @return Builder<CardPayment>
     */
    private static function sorted(Builder $query, ?string $tab): Builder
    {
        $oldestFirst = $tab === CardPaymentTab::Unresolved->value;

        return $query
            ->orderBy('card_payments.received_at', $oldestFirst ? 'asc' : 'desc')
            ->orderBy('card_payments.id', $oldestFirst ? 'asc' : 'desc');
    }

    /**
     * The key of the tab being shown, or null on a page without tabs.
     */
    private static function activeTab(Component $livewire): ?string
    {
        $tab = $livewire->activeTab ?? null;

        return is_string($tab) ? $tab : null;
    }
}
