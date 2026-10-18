<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Queries\UnresolvedPayments;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\RelationManagers\CardPaymentHistoryRelationManager;
use App\Filament\Resources\CardPaymentResource\Pages\ListCardPayments;
use App\Filament\Resources\CardPaymentResource\Pages\ViewCardPayment;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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
                    ->description(static fn (CardPayment $record): ?string => self::offeredLine($record))
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
                    ->icon(static fn (CardPaymentStatus $state): Heroicon => self::statusIcon($state))
                    ->description(static fn (CardPayment $record): ?string => self::resolutionLine($record)),
                self::referenceColumn('stripe_payment_intent_id', 'payment'),
                self::referenceColumn('stripe_event_id', 'event'),
            ])
            ->filters(self::filters())
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort(static fn (Builder $query, Component $livewire): Builder => self::sorted($query, self::activeTab($livewire)))
            ->recordUrl(static fn (CardPayment $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label(__('card_payments.actions.open')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCreditCard)
            ->emptyStateHeading(static fn (Component $livewire): string => self::emptyHeading(self::activeTab($livewire)))
            ->emptyStateDescription(static fn (Component $livewire): ?string => self::emptyDescription(self::activeTab($livewire)));
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
     * A Stripe reference column: hidden by default, shortened to its first 8 and last 6 characters, with the full value
     * in the tooltip and as the copied text (R11-11-4).
     */
    private static function referenceColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label(__('card_payments.columns.'.$label))
            ->state(static fn (CardPayment $record): ?string => self::shortReference($record->{$name}))
            ->tooltip(static fn (CardPayment $record): ?string => $record->{$name})
            ->copyable()
            ->copyableState(static fn (CardPayment $record): ?string => $record->{$name})
            ->placeholder(__('card_payments.navigation.empty_value'))
            ->toggleable(isToggledHiddenByDefault: true);
    }

    private static function shortReference(?string $reference): ?string
    {
        if ($reference === null || mb_strlen($reference) <= 16) {
            return $reference;
        }

        return mb_substr($reference, 0, 8).'…'.mb_substr($reference, -6);
    }

    /**
     * "Nabízeno: :offered" under an amount that is not the amount the link offered, otherwise null.
     */
    private static function offeredLine(CardPayment $record): ?string
    {
        $offered = $record->offered();

        if ($offered === null || ($offered->minor === $record->amount_minor && $offered->currency === $record->currency)) {
            return null;
        }

        return (string) __('card_payments.columns.offered', ['offered' => $offered->format('cs')]);
    }

    /**
     * How a resolved record was settled, under its state: the document an assignment put the payment on, a note, a
     * recorded return. Null for a record nobody resolved.
     */
    public static function resolutionLine(CardPayment $record): ?string
    {
        if ($record->resolution === CardPaymentResolution::None) {
            return null;
        }

        $label = $record->resolution->getLabel();

        return $record->resolution === CardPaymentResolution::Assigned
            ? trim($label.' '.$record->assignedInvoice?->number)
            : $label;
    }

    /**
     * The filters of the list, combined with AND: client, currency and the day the payment was received.
     *
     * @return list<Filter|SelectFilter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('client_id')
                ->label(__('card_payments.filters.client'))
                ->options(static fn (): array => self::clientOptions())
                ->searchable()
                ->query(static function (Builder $query, array $data): Builder {
                    $client = $data['value'] ?? null;

                    // The client of the document the row is shown against (see documentOf()).
                    return is_string($client) && $client !== ''
                        ? $query->whereRaw('EXISTS (SELECT 1 FROM invoices WHERE invoices.id = COALESCE(card_payments.assigned_invoice_id, card_payments.invoice_id) AND invoices.client_id = ?)', [$client])
                        : $query;
                }),
            SelectFilter::make('currency')
                ->label(__('card_payments.filters.currency'))
                ->options(static fn (): array => self::currencyOptions()),
            Filter::make('received')
                ->label(__('card_payments.filters.received'))
                ->schema([
                    DatePicker::make('from')->label(__('card_payments.filters.from')),
                    DatePicker::make('until')->label(__('card_payments.filters.until')),
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);

                    // Both days are inclusive, counted in the panel time zone.
                    return $query
                        ->when($from !== null, static fn (Builder $query): Builder => $query->where('card_payments.received_at', '>=', PragueDay::start((string) $from)))
                        ->when($until !== null, static fn (Builder $query): Builder => $query->where('card_payments.received_at', '<', PragueDay::endExclusive((string) $until)));
                })
                ->indicateUsing(static function (array $data): array {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);
                    $label = (string) __('card_payments.filters.received');

                    return array_values(array_filter([
                        $from === null ? null : $label.' '.mb_strtolower((string) __('card_payments.filters.from')).': '.self::dayText($from),
                        $until === null ? null : $label.' '.mb_strtolower((string) __('card_payments.filters.until')).': '.self::dayText($until),
                    ]));
                }),
        ];
    }

    /**
     * Every client that has a document on a record, archived ones included and marked, in Czech order.
     *
     * @return array<string, string>
     */
    private static function clientOptions(): array
    {
        $documents = Invoice::query()
            ->select('invoices.client_id')
            ->where(static fn (Builder $query): Builder => $query
                ->whereIn('invoices.id', CardPayment::query()->select('card_payments.invoice_id'))
                ->orWhereIn('invoices.id', CardPayment::query()->select('card_payments.assigned_invoice_id')));
        $options = [];

        foreach (CzechCollation::orderBy(Client::query()->withTrashed()->whereIn('clients.id', $documents), 'clients.name')->orderBy('clients.id')->get(['clients.id', 'clients.name', 'clients.deleted_at']) as $client) {
            $options[$client->id] = $client->trashed() ? __('card_payments.filters.archived', ['name' => $client->name]) : $client->name;
        }

        return $options;
    }

    /**
     * The currencies the records are written in.
     *
     * @return array<string, string>
     */
    private static function currencyOptions(): array
    {
        /** @var list<string> $codes */
        $codes = CardPayment::query()->distinct()->orderBy('card_payments.currency')->pluck('card_payments.currency')->all();

        return array_combine($codes, $codes);
    }

    /**
     * A calendar day `Y-m-d` as the panel writes it. The day is plain, so no time zone is involved.
     */
    private static function dayText(string $day): string
    {
        return CarbonImmutable::parse($day, 'UTC')->format('j. n. Y');
    }

    private static function emptyHeading(?string $tab): string
    {
        // A list that never had a record explains where records come from, whichever tab is open.
        if (app(UnresolvedPayments::class)->tabCounts()[CardPaymentTab::All->value] === 0) {
            return __('card_payments.empty.all_heading');
        }

        return match ($tab) {
            CardPaymentTab::Unresolved->value => __('card_payments.empty.unresolved_heading'),
            CardPaymentTab::Matched->value => __('card_payments.empty.matched_heading'),
            CardPaymentTab::Duplicate->value => __('card_payments.empty.duplicate_heading'),
            CardPaymentTab::Unmatched->value => __('card_payments.empty.unmatched_heading'),
            CardPaymentTab::Refund->value => __('card_payments.empty.refund_heading'),
            CardPaymentTab::Resolved->value => __('card_payments.empty.resolved_heading'),
            default => __('card_payments.empty.all_heading'),
        };
    }

    private static function emptyDescription(?string $tab): ?string
    {
        if (app(UnresolvedPayments::class)->tabCounts()[CardPaymentTab::All->value] === 0) {
            return (string) __('card_payments.empty.all_body');
        }

        return match ($tab) {
            CardPaymentTab::Unresolved->value => (string) __('card_payments.empty.unresolved_body'),
            null, CardPaymentTab::All->value => (string) __('card_payments.empty.all_body'),
            default => null,
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
