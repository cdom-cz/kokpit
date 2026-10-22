<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceListTab;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * The Partner-safe document columns, filters and entries (IN-09, UI-SPEC Surface N, rulings R-26-2 and R-26-3).
 *
 * The Partner resource builds its list and its detail only from these builders, so a surface a Partner can open shows
 * exactly the fields named here: what the issued document prints (number, kind, dates, parties, bank data, lines,
 * totals, printed note) and three derived figures (the state, what is still owed and what was paid). It never shows a
 * draft, the internal note, a payment row, an income, linked time or a duration, a rate, a CNB rate or a CZK total,
 * the e-mail log or an Admin action.
 *
 * The derived figures come from {@see ReceivablesReader}, which reads the Admin-only payments and therefore refuses
 * everybody but the Admin and a system run. It is run as system, but only over the ids of the page of the
 * Partner-scoped query that is being drawn, so it can never describe a document the Partner may not read, and the
 * Partner reads a state without reading any payment row (ruling R-26-2). A page is derived once, however many rows it
 * holds.
 */
final class InvoiceColumns
{
    /** @var list<string> */
    public const array PARTNER_COLUMN_NAMES = ['number', 'kind', 'issue_date', 'due_date', 'total', 'remaining', 'status'];

    /** @var list<string> */
    public const array PARTNER_FILTER_NAMES = ['kind', 'status'];

    /**
     * The derived states of the records of the page being rendered, per record collection: a column reads its row
     * from here, so the page is derived once and not once per cell.
     *
     * @var WeakMap<object, array<string, array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}>>|null
     */
    private static ?WeakMap $pages = null;

    /**
     * List columns for a Partner. Nothing is searchable: the client searches nothing it does not already see.
     *
     * @return list<TextColumn>
     */
    public static function partnerColumns(): array
    {
        return [
            TextColumn::make('number')
                ->label(__('invoices.partner.list.columns.number'))
                ->weight(FontWeight::SemiBold)
                ->url(static fn (Invoice $record): string => PartnerInvoiceResource::getUrl('view', ['record' => $record]))
                ->sortable(),
            TextColumn::make('kind')
                ->label(__('invoices.partner.list.columns.kind'))
                ->badge()
                ->color('gray'),
            TextColumn::make('issue_date')
                ->label(__('invoices.partner.list.columns.issued'))
                ->date('j. n. Y')
                ->sortable(),
            TextColumn::make('due_date')
                ->label(__('invoices.partner.list.columns.due'))
                ->date('j. n. Y')
                ->placeholder(__('invoices.navigation.empty_value'))
                ->color(static fn (Invoice $record, Table $table): ?string => self::partnerState($record, $table)['overdue_days'] === null ? null : 'danger')
                ->description(static function (Invoice $record, Table $table): ?string {
                    $days = self::partnerState($record, $table)['overdue_days'];

                    return $days === null ? null : trans_choice('invoices.list.overdue_days', $days, ['count' => $days]);
                })
                ->sortable(),
            TextColumn::make('total')
                ->label(__('invoices.partner.list.columns.total'))
                ->state(static fn (Invoice $record): string => $record->total()->format('cs'))
                ->alignEnd(),
            TextColumn::make('remaining')
                ->label(__('invoices.partner.list.columns.remaining'))
                ->state(static function (Invoice $record, Table $table): string {
                    $remaining = self::partnerState($record, $table)['remaining'];

                    return $remaining === null || $remaining->isZero() ? __('invoices.navigation.empty_value') : $remaining->format('cs');
                })
                ->alignEnd(),
            TextColumn::make('status')
                ->label(__('invoices.partner.list.columns.status'))
                ->state(static fn (Invoice $record, Table $table): InvoiceStatus => self::partnerState($record, $table)['status'])
                ->badge()
                ->formatStateUsing(static fn (InvoiceStatus $state): string => $state->partnerLabel())
                ->color(static fn (InvoiceStatus $state): string => self::partnerColor($state))
                // A partly paid overdue document reads "Po splatnosti" with the payment state on a second line.
                ->description(static function (Invoice $record, Table $table): ?string {
                    $state = self::partnerState($record, $table);

                    return $state['status'] === InvoiceStatus::Overdue && $state['paid'] > 0 ? (string) __('invoices.list.partially_paid') : null;
                }),
        ];
    }

    /**
     * The two filters of the list: the kind and the state. There is no client filter (one client) and no date filter.
     *
     * @return list<SelectFilter>
     */
    public static function partnerFilters(): array
    {
        return [
            SelectFilter::make('kind')
                ->label(__('invoices.list.filters.kind'))
                ->options(InvoiceKind::class),
            SelectFilter::make('status')
                ->label(__('invoices.partner.list.columns.status'))
                ->options(static fn (): array => self::statusOptions())
                ->query(static function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    return is_string($value) ? self::constrainToStatus($query, $value) : $query;
                }),
        ];
    }

    /**
     * Detail entries for a Partner: the overdue callout and the sections of the document.
     *
     * @return list<Callout|Grid|Section>
     */
    public static function partnerEntries(): array
    {
        $empty = __('invoices.navigation.empty_value');
        $fields = 'invoices.partner.detail.fields.';

        return [
            Callout::make(static fn (Invoice $record): string => self::overdueText($record))
                ->danger()
                ->visible(static fn (?Invoice $record): bool => $record instanceof Invoice && self::partnerState($record)['status'] === InvoiceStatus::Overdue),
            Section::make(__('invoices.partner.detail.sections.document'))
                ->columns(2)
                ->schema([
                    TextEntry::make('number')
                        ->label(__($fields.'number'))
                        ->weight(FontWeight::SemiBold),
                    TextEntry::make('kind')
                        ->label(__($fields.'kind'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('issue_date')
                        ->label(__($fields.'issue_date'))
                        ->date('j. n. Y'),
                    TextEntry::make('taxable_supply_date')
                        ->label(__($fields.'taxable_supply_date'))
                        ->date('j. n. Y')
                        ->visible(static fn (Invoice $record): bool => $record->taxable_supply_date !== null),
                    TextEntry::make('due_date')
                        ->label(__($fields.'due_date'))
                        ->date('j. n. Y')
                        ->placeholder($empty),
                    TextEntry::make('variable_symbol')
                        ->label(__($fields.'variable_symbol'))
                        ->placeholder($empty),
                    TextEntry::make('currency')
                        ->label(__($fields.'currency')),
                ]),
            Grid::make(['default' => 1, 'md' => 2])
                ->schema([
                    self::partySection('supplier_snapshot', 'supplier'),
                    self::partySection('customer_snapshot', 'customer'),
                ]),
            Section::make(__('invoices.partner.detail.sections.payment'))
                ->columns(2)
                ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote)
                ->schema([
                    TextEntry::make('bank_account')
                        ->label(__($fields.'bank_account'))
                        ->state(static fn (Invoice $record): array => self::bankLines($record))
                        ->listWithLineBreaks()
                        ->placeholder($empty),
                    TextEntry::make('payment_variable_symbol')
                        ->label(__($fields.'variable_symbol'))
                        ->state(static fn (Invoice $record): ?string => $record->variable_symbol)
                        ->placeholder($empty),
                    TextEntry::make('amount_due')
                        ->label(__($fields.'amount_due'))
                        ->state(static fn (Invoice $record): string => $record->total()->format('cs')),
                    TextEntry::make('payment_due_date')
                        ->label(__('invoices.partner.list.columns.due'))
                        ->state(static fn (Invoice $record): ?string => $record->due_date?->format('j. n. Y'))
                        ->placeholder($empty),
                ]),
            Section::make(__('invoices.partner.detail.sections.items'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->placeholder(__('invoices.detail.no_items'))
                        ->state(static fn (Invoice $record): array => self::lines($record))
                        ->table([
                            TableColumn::make(__('invoices.detail.columns.description')),
                            TableColumn::make(__('invoices.detail.columns.quantity'))->alignEnd(),
                            TableColumn::make(__('invoices.detail.columns.unit_price'))->alignEnd(),
                            TableColumn::make(__('invoices.detail.columns.total'))->alignEnd(),
                        ])
                        ->schema([
                            TextEntry::make('description'),
                            TextEntry::make('quantity'),
                            TextEntry::make('unit_price'),
                            TextEntry::make('line_total'),
                        ]),
                ]),
            Section::make(__('invoices.partner.detail.sections.summary'))
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextEntry::make('summary_total')
                        ->label(__($fields.'total'))
                        ->state(static fn (Invoice $record): string => $record->total()->format('cs'))
                        ->size(TextSize::Large)
                        ->weight(FontWeight::SemiBold),
                    TextEntry::make('summary_paid')
                        ->label(__($fields.'paid'))
                        ->state(static fn (Invoice $record): string => Money::ofMinor(self::partnerState($record)['paid'], $record->currency)->format('cs'))
                        ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                    TextEntry::make('summary_remaining')
                        ->label(__($fields.'remaining'))
                        ->state(static fn (Invoice $record): string => (self::partnerState($record)['remaining'] ?? Money::zero($record->currency))->format('cs'))
                        ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                ]),
            Section::make(__('invoices.partner.detail.sections.note'))
                ->visible(static fn (Invoice $record): bool => is_string($record->note) && trim($record->note) !== '')
                ->schema([
                    TextEntry::make('note')
                        ->hiddenLabel()
                        ->prose(),
                ]),
            self::relatedSection(),
        ];
    }

    /**
     * The derived state of one document: its status, what it still owes, the days it is late and what was paid. A
     * record of the table being rendered reads its row from the page derived once.
     *
     * @return array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}
     */
    public static function partnerState(Invoice $record, ?Table $table = null): array
    {
        if ($table === null) {
            return self::derive([$record])[$record->id];
        }

        $records = $table->getRecords();

        self::$pages ??= new WeakMap;

        if (! isset(self::$pages[$records])) {
            $items = match (true) {
                $records instanceof Collection => $records->all(),
                $records instanceof Paginator, $records instanceof CursorPaginator => $records->items(),
            };

            $page = [];

            foreach ($items as $item) {
                if ($item instanceof Invoice) {
                    $page[] = $item;
                }
            }

            self::$pages[$records] = self::derive($page);
        }

        return self::$pages[$records][$record->id] ?? self::derive([$record])[$record->id];
    }

    /**
     * The badge colour of a state as the Partner reads it: a paid proforma, whatever its invoice, reads as paid.
     */
    public static function partnerColor(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Converted, InvoiceStatus::AwaitingInvoice => 'success',
            default => $status->getColor(),
        };
    }

    /**
     * The "Stav" choices of the Partner, in the order the screen lists them.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];

        foreach ([InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue, InvoiceStatus::Paid, InvoiceStatus::Cancelled, InvoiceStatus::CreditNote] as $status) {
            $options[$status->value] = $status->partnerLabel();
        }

        return $options;
    }

    /**
     * Narrows the Partner-scoped query to one Partner state with the very conditions of the Admin list tabs, so a
     * filter and the state of the rows cannot disagree. Building the condition reads no row: it only needs the
     * reader's permission, and the query keeps its Partner scope.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function constrainToStatus(Builder $query, string $value): Builder
    {
        $status = InvoiceStatus::tryFrom($value);

        if ($status === null) {
            return $query;
        }

        $today = ReceivablesReader::today();
        $reader = app(ReceivablesReader::class);

        return app(PartnerContext::class)->runAsSystem(static fn (): Builder => match ($status) {
            InvoiceStatus::Unpaid => $reader->constrain($query, InvoiceListTab::Unpaid, $today)->whereRaw(InvoiceBalances::PAID_SQL.' = 0'),
            InvoiceStatus::PartiallyPaid => $reader->constrain($query, InvoiceListTab::Unpaid, $today)->whereRaw(InvoiceBalances::PAID_SQL.' > 0'),
            InvoiceStatus::Overdue => $reader->constrain($query, InvoiceListTab::Overdue, $today),
            InvoiceStatus::Paid => $reader->constrain($query, InvoiceListTab::Paid, $today),
            InvoiceStatus::Cancelled => $reader->constrain($query, InvoiceListTab::Cancelled, $today),
            InvoiceStatus::CreditNote => $query->where('invoices.kind', InvoiceKind::CreditNote->value),
            default => $query,
        });
    }

    /**
     * The states of the given documents, which are the ones the Partner-scoped query returned. This is the one place
     * the Admin-only reader is run as system for a Partner (ruling R-26-2).
     *
     * @param  list<Invoice>  $invoices
     * @return array<string, array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}>
     */
    private static function derive(array $invoices): array
    {
        $today = ReceivablesReader::today();

        return app(PartnerContext::class)->runAsSystem(static fn (): array => app(ReceivablesReader::class)->statuses($invoices, $today));
    }

    private static function overdueText(Invoice $record): string
    {
        $state = self::partnerState($record);
        $days = $state['overdue_days'] ?? 0;

        return trans_choice('invoices.partner.detail.overdue', $days, [
            'count' => $days,
            'amount' => ($state['remaining'] ?? Money::zero($record->currency))->format('cs'),
        ]);
    }

    /**
     * One party block of a snapshot, frozen at issue.
     */
    private static function partySection(string $attribute, string $field): Section
    {
        return Section::make(__('invoices.partner.detail.sections.'.$field))
            ->schema([
                TextEntry::make($field)
                    ->hiddenLabel()
                    ->state(static fn (Invoice $record): array => self::partyLines($record->{$attribute}))
                    ->listWithLineBreaks(),
            ]);
    }

    /**
     * The printed lines of a party snapshot: name, address, company and VAT id.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return list<string>
     */
    public static function partyLines(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $text = static fn (string $key): ?string => is_string($snapshot[$key] ?? null) && trim($snapshot[$key]) !== '' ? trim($snapshot[$key]) : null;

        $lines = array_filter([
            $text('name'),
            $text('street'),
            trim(implode(' ', array_filter([$text('postal_code'), $text('city')]))),
            ($country = $text('country')) !== null && $country !== 'CZ' ? $country : null,
            ($companyId = $text('company_id')) === null ? null : __('invoices.partner.detail.fields.company_id').': '.$companyId,
            ($vatId = $text('vat_id')) === null ? null : __('invoices.partner.detail.fields.vat_id').': '.$vatId,
        ], static fn (?string $line): bool => $line !== null && $line !== '');

        return array_values($lines);
    }

    /**
     * The bank account of the document as printed: the account number with the bank code, then the IBAN.
     *
     * @return list<string>
     */
    public static function bankLines(Invoice $record): array
    {
        $bank = $record->bank_snapshot;

        if ($bank === null) {
            return [];
        }

        $text = static fn (string $key): ?string => is_string($bank[$key] ?? null) && trim($bank[$key]) !== '' ? trim($bank[$key]) : null;
        $lines = [];

        if (($number = $text('account_number')) !== null) {
            $lines[] = $number.(($code = $text('bank_code')) !== null ? '/'.$code : '');
        }

        if (($iban = $text('iban')) !== null) {
            $lines[] = __('invoices.partner.detail.fields.iban').': '.trim(chunk_split(preg_replace('/\s+/', '', $iban) ?? $iban, 4, ' '));
        }

        return $lines;
    }

    /**
     * The frozen lines of the document as plain text rows, in order, formatted once with the currency of the
     * document so the entry runs no query per line.
     *
     * @return list<array{description: string, quantity: string, unit_price: string, line_total: string}>
     */
    private static function lines(Invoice $invoice): array
    {
        $lines = [];

        foreach ($invoice->items as $item) {
            $lines[] = [
                'description' => $item->description,
                'quantity' => InvoiceQuantity::fromDecimal($item->quantity)->format('cs').' '.$item->unit,
                'unit_price' => Money::ofMinor($item->unit_price_minor, $invoice->currency)->format('cs'),
                'line_total' => Money::ofMinor($item->line_total_minor, $invoice->currency)->format('cs'),
            ];
        }

        return $lines;
    }

    /**
     * "Související doklady" (ruling R-26-3): the credit note of a cancelled invoice and the invoice made from a
     * proforma. Both are read through the Partner-scoped relations, so only an issued document of the own client can
     * ever be named and a draft never is.
     */
    private static function relatedSection(): Section
    {
        return Section::make(__('invoices.detail.sections.related'))
            ->columns(2)
            ->visible(static fn (Invoice $record): bool => self::relatedDocuments($record) !== [])
            ->schema([
                TextEntry::make('related_credit_note')
                    ->label(InvoiceKind::CreditNote->getLabel())
                    ->state(static fn (Invoice $record): ?string => (self::relatedDocuments($record)['credit_note'] ?? null)?->number)
                    ->url(static fn (Invoice $record): ?string => ($note = self::relatedDocuments($record)['credit_note'] ?? null) === null ? null : PartnerInvoiceResource::getUrl('view', ['record' => $note]))
                    ->visible(static fn (Invoice $record): bool => isset(self::relatedDocuments($record)['credit_note'])),
                TextEntry::make('related_invoice')
                    ->label(InvoiceKind::Invoice->getLabel())
                    ->state(static fn (Invoice $record): ?string => (self::relatedDocuments($record)['invoice'] ?? null)?->number)
                    ->url(static fn (Invoice $record): ?string => ($invoice = self::relatedDocuments($record)['invoice'] ?? null) === null ? null : PartnerInvoiceResource::getUrl('view', ['record' => $invoice]))
                    ->visible(static fn (Invoice $record): bool => isset(self::relatedDocuments($record)['invoice'])),
            ]);
    }

    /**
     * The related documents that exist, keyed `credit_note` and `invoice`.
     *
     * @return array{credit_note?: Invoice, invoice?: Invoice}
     */
    private static function relatedDocuments(Invoice $record): array
    {
        $record->loadMissing(['stornoCreditNote', 'invoiceFromProforma']);

        $related = [];

        if ($record->kind === InvoiceKind::Invoice && $record->stornoCreditNote instanceof Invoice) {
            $related['credit_note'] = $record->stornoCreditNote;
        }

        if ($record->kind === InvoiceKind::Proforma && $record->invoiceFromProforma instanceof Invoice) {
            $related['invoice'] = $record->invoiceFromProforma;
        }

        return $related;
    }
}
