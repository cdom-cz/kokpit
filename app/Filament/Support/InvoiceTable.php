<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\DeleteDraft;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceListTab;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Invoicing\Support\ClientDocumentEmails;
use App\Domain\Payments\Queries\PaymentLinkStatus;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\InvoiceResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Livewire\Component;
use WeakMap;

/**
 * The columns, filters, sorting and row actions of a document list (UI-SPEC Surface A), built once for the Admin
 * list of plan 10-21 and for the client tab of a later plan, so the two can never read a document differently.
 *
 * Nothing here writes a row but through a domain Action. The state of a row (status, remaining amount, days late) is
 * never a column: it is derived for the whole page at once by {@see ReceivablesReader::statuses()}, which runs two
 * queries however many rows the page holds. The tabs of the list page narrow the query through the same reader, so a
 * tab and the state of its rows can never disagree.
 */
final class InvoiceTable
{
    /** The state of the latest e-mail of the document, as SQL over the table `invoices`. */
    private const string LATEST_EMAIL_SQL = '(SELECT latest_email.state FROM invoice_emails AS latest_email WHERE latest_email.invoice_id = invoices.id ORDER BY latest_email.created_at DESC, latest_email.id DESC LIMIT 1)';

    /**
     * The derived states of the records of the page being rendered, per record collection: a column reads its row
     * from here, so the page is derived once and not once per cell.
     *
     * @var WeakMap<object, array<string, array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}>>|null
     */
    private static ?WeakMap $pages = null;

    /**
     * The card-payment keys of the records of the page being rendered, per record collection (one query per page).
     *
     * @var WeakMap<object, array<string, string>>|null
     */
    private static ?WeakMap $cardKeys = null;

    /**
     * @param  bool  $withClientColumn  false on the tab of one client, where every row has the same client
     */
    public static function configure(Table $table, bool $withClientColumn = true): Table
    {
        $columns = [
            TextColumn::make('number')
                ->label(__('invoices.list.columns.number'))
                ->weight(FontWeight::SemiBold)
                ->placeholder(__('invoices.navigation.empty_value'))
                ->searchable()
                ->sortable(),
            TextColumn::make('kind')
                ->label(__('invoices.list.columns.kind'))
                ->badge()
                ->color('gray'),
        ];

        if ($withClientColumn) {
            $columns[] = TextColumn::make('client.name')
                ->label(__('invoices.list.columns.client'))
                ->url(static fn (Invoice $record): string => ClientResource::getUrl('view', ['record' => $record->client_id]));
        }

        $columns = [
            ...$columns,
            TextColumn::make('issue_date')
                ->label(__('invoices.list.columns.issued'))
                ->date('j. n. Y')
                ->placeholder(__('invoices.navigation.empty_value'))
                ->sortable(),
            self::dueColumn(),
            TextColumn::make('total')
                ->label(__('invoices.list.columns.total'))
                ->state(static fn (Invoice $record): string => $record->total()->format('cs'))
                ->alignEnd(),
            self::remainingColumn(perCurrencyTotal: ! $withClientColumn),
            self::statusColumn(),
            self::emailColumn(),
            ...self::cardPaymentColumns(),
            TextColumn::make('currency')
                ->label(__('invoices.list.columns.currency'))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')
                ->label(__('invoices.list.columns.created'))
                ->dateTime('j. n. Y H:i')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('variable_symbol')
                ->label(__('invoices.list.columns.variable_symbol'))
                ->placeholder(__('invoices.navigation.empty_value'))
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];

        return $table
            ->columns($columns)
            ->defaultSort(static fn (Builder $query, Component $livewire): Builder => self::sorted($query, self::activeTab($livewire)))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->filters(self::filters($withClientColumn))
            ->recordUrl(static fn (Invoice $record): string => InvoiceResource::getUrl($record->isDraft() ? 'edit' : 'view', ['record' => $record]))
            ->recordActions([
                self::editDraftAction(),
                self::downloadAction(),
                self::resendEmailAction(),
                self::deleteDraftAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(static fn (Component $livewire): string => self::emptyHeading(self::activeTab($livewire)))
            ->emptyStateDescription(static fn (Component $livewire): ?string => self::emptyDescription(self::activeTab($livewire)))
            ->emptyStateActions([CreateDraftAction::make()]);
    }

    /**
     * The derived state of one document, outside a table.
     *
     * @return array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}
     */
    public static function statusOf(Invoice $invoice): array
    {
        return app(ReceivablesReader::class)->statuses([$invoice], ReceivablesReader::today())[$invoice->id];
    }

    /**
     * The derived state of a record of the table being rendered: the whole page is derived on the first call.
     *
     * @return array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}
     */
    private static function rowState(Invoice $record, Table $table): array
    {
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

            self::$pages[$records] = app(ReceivablesReader::class)->statuses($page, ReceivablesReader::today());
        }

        return self::$pages[$records][$record->id] ?? self::statusOf($record);
    }

    private static function dueColumn(): TextColumn
    {
        return TextColumn::make('due_date')
            ->label(__('invoices.list.columns.due'))
            ->date('j. n. Y')
            ->placeholder(__('invoices.navigation.empty_value'))
            ->color(static fn (Invoice $record, Table $table): ?string => self::rowState($record, $table)['overdue_days'] === null ? null : 'danger')
            ->description(static function (Invoice $record, Table $table): ?string {
                $days = self::rowState($record, $table)['overdue_days'];

                return $days === null ? null : trans_choice('invoices.list.overdue_days', $days, ['count' => $days]);
            })
            ->sortable();
    }

    /**
     * @param  bool  $perCurrencyTotal  the footer "K úhradě": what the rows shown still owe, one amount per currency
     */
    private static function remainingColumn(bool $perCurrencyTotal = false): TextColumn
    {
        return TextColumn::make('remaining')
            ->label(__('invoices.list.columns.remaining'))
            ->state(static function (Invoice $record, Table $table): string {
                $remaining = self::rowState($record, $table)['remaining'];

                return $remaining === null || $remaining->isZero() ? __('invoices.navigation.empty_value') : $remaining->format('cs');
            })
            // A foreign-currency row of the overdue tab names its CZK equivalent, converted with the frozen rate (D-20).
            ->description(static function (Invoice $record, Table $table, Component $livewire): ?string {
                if (self::activeTab($livewire) !== InvoiceListTab::Overdue->value || $record->currency === 'CZK') {
                    return null;
                }

                $remaining = self::rowState($record, $table)['remaining'];
                $admin = $record->admin;

                if ($remaining === null || $remaining->isZero() || $admin === null || $admin->exchange_rate === null || $admin->exchange_unit_amount === null) {
                    return null;
                }

                return (string) __('invoices.list.czk', ['czk' => $remaining->convert($admin->exchange_rate, 'CZK', $admin->exchange_unit_amount)->format('cs')]);
            })
            ->alignEnd()
            ->summarize([
                Summarizer::make()
                    ->label(__('invoices.list.czk_total'))
                    ->visible(static fn (Component $livewire): bool => self::activeTab($livewire) === InvoiceListTab::Overdue->value)
                    ->using(static fn (QueryBuilder $query): string => app(ReceivablesReader::class)->owedFrom($query)['czk']->format('cs')),
                // Amounts of different currencies are never added: one figure per currency, the CZK ones first only
                // by alphabet (the client tab has no tabs, so the figure covers every row the filters leave).
                Summarizer::make('to_pay')
                    ->label(__('invoices.client_panel.invoices.to_pay_total'))
                    ->visible($perCurrencyTotal)
                    ->using(static function (QueryBuilder $query): string {
                        $amounts = app(ReceivablesReader::class)->owedByCurrencyFrom($query);

                        return $amounts === []
                            ? (string) __('invoices.navigation.empty_value')
                            : implode(', ', array_map(static fn (Money $amount): string => $amount->format('cs'), array_values($amounts)));
                    }),
            ]);
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('invoices.list.columns.status'))
            ->state(static fn (Invoice $record, Table $table): InvoiceStatus => self::rowState($record, $table)['status'])
            ->badge()
            // A partially paid overdue document reads "Po splatnosti" with the payment state on a second line.
            ->description(static function (Invoice $record, Table $table): ?string {
                $state = self::rowState($record, $table);

                return $state['status'] === InvoiceStatus::Overdue && $state['paid'] > 0 ? (string) __('invoices.list.partially_paid') : null;
            });
    }

    private static function emailColumn(): IconColumn
    {
        return IconColumn::make('email')
            ->label(__('invoices.list.columns.email'))
            ->state(static fn (Invoice $record): ?string => self::emailKey($record))
            ->icon(static fn (?string $state): ?Heroicon => match ($state) {
                'sent' => Heroicon::OutlinedEnvelope,
                'queued' => Heroicon::OutlinedClock,
                'failed' => Heroicon::OutlinedExclamationCircle,
                'none' => Heroicon::OutlinedMinus,
                default => null,
            })
            ->color(static fn (?string $state): string => match ($state) {
                'sent' => 'success',
                'queued' => 'info',
                'failed' => 'danger',
                default => 'gray',
            })
            ->tooltip(static function (Invoice $record, ?string $state): ?string {
                if ($state === 'sent') {
                    $sent = self::latestEmail($record)?->sent_at;

                    return (string) __('invoices.list.email.sent', ['date' => $sent === null ? __('invoices.navigation.empty_value') : $sent->setTimezone(FilamentTimezone::get())->format('j. n. Y')]);
                }

                return $state === null ? null : (string) __('invoices.list.email.'.$state);
            });
    }

    /**
     * The icon column "Platba kartou", toggleable and hidden by default (UI-SPEC Surface E, list): the state of the
     * card link of the row. Admin only; the state of the whole page is read in one query.
     *
     * @return list<IconColumn>
     */
    private static function cardPaymentColumns(): array
    {
        if (! app(PartnerContext::class)->isAdmin()) {
            return [];
        }

        return [
            IconColumn::make('card_payment')
                ->label(__('payment_links.list.column'))
                ->state(static fn (Invoice $record, Table $table): ?string => self::cardKey($record, $table))
                ->icon(static fn (?string $state): ?Heroicon => match ($state) {
                    'active' => Heroicon::OutlinedLink,
                    'pending' => Heroicon::OutlinedClock,
                    'failed' => Heroicon::OutlinedExclamationCircle,
                    'deactivated' => Heroicon::OutlinedLinkSlash,
                    'off' => Heroicon::OutlinedCreditCard,
                    'none' => Heroicon::OutlinedMinus,
                    default => null,
                })
                ->color(static fn (?string $state): string => match ($state) {
                    'active' => 'success',
                    'pending' => 'info',
                    'failed' => 'danger',
                    default => 'gray',
                })
                ->tooltip(static fn (?string $state): ?string => $state === null ? null : (string) __('payment_links.list.states.'.$state))
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * The card-payment key of a record of the table being rendered: the whole page is read on the first call.
     */
    private static function cardKey(Invoice $record, Table $table): ?string
    {
        $records = $table->getRecords();

        self::$cardKeys ??= new WeakMap;

        if (! isset(self::$cardKeys[$records])) {
            $items = match (true) {
                $records instanceof Collection => $records->all(),
                $records instanceof Paginator, $records instanceof CursorPaginator => $records->items(),
            };

            $ids = [];

            foreach ($items as $item) {
                if ($item instanceof Invoice) {
                    $ids[] = $item->id;
                }
            }

            self::$cardKeys[$records] = PaymentLinkStatus::listKeys($ids);
        }

        return self::$cardKeys[$records][$record->id] ?? null;
    }

    /**
     * "Platební odkaz": the state of the card link as a filter, built on the newest link of the document and the
     * stored card switch (editable while the document is open, frozen by the lock). The SQL fragments ignore the Partner scope, so the filter exists for the Admin only and refuses
     * to narrow anything else (T-11-44).
     *
     * @return list<SelectFilter>
     */
    private static function cardPaymentFilters(): array
    {
        if (! app(PartnerContext::class)->isAdmin()) {
            return [];
        }

        return [
            SelectFilter::make('payment_link')
                ->label(__('payment_links.list.filter'))
                ->options([
                    'active' => __('payment_links.list.options.active'),
                    'pending' => __('payment_links.list.options.pending'),
                    'failed' => __('payment_links.list.options.failed'),
                    'deactivated' => __('payment_links.list.options.deactivated'),
                    'off' => __('payment_links.list.options.off'),
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    if (! is_string($value) || $value === '') {
                        return $query;
                    }

                    if (! app(PartnerContext::class)->isAdmin()) {
                        return $query->whereRaw('FALSE');
                    }

                    $carriesPayment = $query
                        ->where('invoices.state', InvoiceState::Issued->value)
                        ->whereIn('invoices.kind', [InvoiceKind::Invoice->value, InvoiceKind::Proforma->value]);

                    return match ($value) {
                        'off' => $carriesPayment->whereRaw('COALESCE('.PaymentLinkStatus::SWITCH_SQL.', FALSE) = FALSE'),
                        'active', 'pending', 'failed', 'deactivated' => $carriesPayment->whereRaw(PaymentLinkStatus::LATEST_STATE_SQL.' = ?', [$value]),
                        default => $query->whereRaw('FALSE'),
                    };
                }),
        ];
    }

    /**
     * "Odeslat znovu" (IN-04; D-22; UI-SPEC Surface G): the e-mail dialog for an issued document whose latest send
     * failed or that was never sent. A document that is queued or sent is not offered it, a draft cannot be sent, and
     * neither can a document of a client whose document e-mails are switched off (Phase 16, D-04): the list eager-loads
     * the client, so the switch is read without a query per row.
     */
    private static function resendEmailAction(): Action
    {
        return SendInvoiceEmailAction::forListRow()
            ->visible(static fn (Invoice $record): bool => in_array(self::emailKey($record), ['failed', 'none'], true)
                && ClientDocumentEmails::allows($record->client));
    }

    /**
     * The e-mail state of a row: none for a draft (it was never sent and cannot be), otherwise the latest log entry
     * as sent, queued, failed or none.
     */
    private static function emailKey(Invoice $record): ?string
    {
        if ($record->state === InvoiceState::Draft) {
            return null;
        }

        return match (self::latestEmail($record)?->state) {
            InvoiceEmailState::Sent => 'sent',
            InvoiceEmailState::Queued, InvoiceEmailState::Sending => 'queued',
            InvoiceEmailState::Failed => 'failed',
            // A skipped row reads as not sent (Phase 16, D-08): the client's switch kept it back, it did not fail.
            InvoiceEmailState::Skipped, null => 'none',
        };
    }

    /**
     * The latest send of the document from the eager-loaded log (latest first).
     */
    private static function latestEmail(Invoice $record): ?InvoiceEmail
    {
        return $record->emails->first();
    }

    /**
     * Drafts first by creation time, then issued documents by issue date and number, newest first; "Po splatnosti"
     * lists the oldest due date first and "Zálohy k vyfakturování" the oldest payment first (UI-SPEC Surface A).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function sorted(Builder $query, ?string $tab): Builder
    {
        if ($tab === InvoiceListTab::Overdue->value) {
            return $query->orderBy('invoices.due_date')->orderBy('invoices.number')->orderBy('invoices.id');
        }

        if ($tab === InvoiceListTab::AwaitingInvoice->value) {
            return $query
                ->orderByRaw('(SELECT MAX(invoice_payments.paid_on) FROM invoice_payments WHERE invoice_payments.invoice_id = invoices.id) ASC NULLS LAST')
                ->orderBy('invoices.id');
        }

        return $query
            ->orderByRaw("(invoices.state <> 'draft') ASC")
            ->orderByRaw("CASE WHEN invoices.state = 'draft' THEN invoices.created_at END DESC NULLS LAST")
            ->orderByRaw('invoices.issue_date DESC NULLS LAST')
            ->orderByRaw('invoices.number DESC NULLS LAST')
            ->orderByDesc('invoices.id');
    }

    /**
     * The key of the tab being shown, or null on a page without tabs.
     */
    private static function activeTab(Component $livewire): ?string
    {
        $tab = $livewire->activeTab ?? null;

        return is_string($tab) ? $tab : null;
    }

    private static function emptyHeading(?string $tab): string
    {
        return match ($tab) {
            InvoiceListTab::Drafts->value => __('invoices.list.empty.drafts_heading'),
            InvoiceListTab::Unpaid->value => __('invoices.list.empty.unpaid_heading'),
            InvoiceListTab::Overdue->value => __('invoices.list.empty.overdue_heading'),
            InvoiceListTab::Paid->value => __('invoices.list.empty.paid_heading'),
            InvoiceListTab::Cancelled->value => __('invoices.list.empty.cancelled_heading'),
            InvoiceListTab::AwaitingInvoice->value => __('invoices.list.empty.awaiting_heading'),
            default => __('invoices.list.empty.all_heading'),
        };
    }

    private static function emptyDescription(?string $tab): ?string
    {
        return match ($tab) {
            InvoiceListTab::Overdue->value => (string) __('invoices.list.empty.overdue_body'),
            null, InvoiceListTab::All->value => (string) __('invoices.list.empty.all_body'),
            default => null,
        };
    }

    /**
     * The filters of the list, combined with AND: kind, client (archived ones marked), issue date range, currency and
     * the state of the latest e-mail.
     *
     * @return list<Filter|SelectFilter>
     */
    private static function filters(bool $withClientFilter): array
    {
        $filters = [
            SelectFilter::make('kind')
                ->label(__('invoices.list.filters.kind'))
                ->options(InvoiceKind::class),
            ...self::cardPaymentFilters(),
        ];

        // On the tab of one client there are no list tabs, so the state is a filter beside the kind.
        if (! $withClientFilter) {
            return [...$filters, self::statusFilter()];
        }

        $filters[] = SelectFilter::make('client_id')
            ->label(__('invoices.list.filters.client'))
            ->options(static fn (): array => self::clientOptions())
            ->searchable();

        return [
            ...$filters,
            Filter::make('issued')
                ->label(__('invoices.list.filters.issued'))
                ->schema([
                    DatePicker::make('from')->label(__('invoices.list.filters.from')),
                    DatePicker::make('until')->label(__('invoices.list.filters.until')),
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);

                    // Both days are inclusive; the column is a plain date, so no time zone is involved.
                    return $query
                        ->when($from !== null, static fn (Builder $query): Builder => $query->where('invoices.issue_date', '>=', $from))
                        ->when($until !== null, static fn (Builder $query): Builder => $query->where('invoices.issue_date', '<=', $until));
                })
                ->indicateUsing(static function (array $data): array {
                    $from = PragueDay::day($data['from'] ?? null);
                    $until = PragueDay::day($data['until'] ?? null);
                    $label = (string) __('invoices.list.filters.issued');

                    return array_values(array_filter([
                        $from === null ? null : $label.' '.mb_strtolower((string) __('invoices.list.filters.from')).': '.self::dayText($from),
                        $until === null ? null : $label.' '.mb_strtolower((string) __('invoices.list.filters.until')).': '.self::dayText($until),
                    ]));
                }),
            SelectFilter::make('currency')
                ->label(__('invoices.list.filters.currency'))
                ->options(static fn (): array => self::currencyOptions()),
            SelectFilter::make('email')
                ->label(__('invoices.list.filters.email'))
                ->options([
                    'sent' => __('invoices.list.filters.email_sent'),
                    'queued' => __('invoices.list.filters.email_queued'),
                    'failed' => __('invoices.list.filters.email_failed'),
                    'none' => __('invoices.list.filters.email_none'),
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    return match ($value) {
                        'sent' => $query->whereRaw(self::LATEST_EMAIL_SQL.' = ?', [InvoiceEmailState::Sent->value]),
                        'queued' => $query->whereRaw(self::LATEST_EMAIL_SQL.' IN (?, ?)', [InvoiceEmailState::Queued->value, InvoiceEmailState::Sending->value]),
                        'failed' => $query->whereRaw(self::LATEST_EMAIL_SQL.' = ?', [InvoiceEmailState::Failed->value]),
                        // "Not sent" includes a latest row that was skipped by the client's switch (Phase 16, D-08).
                        'none' => $query->where('invoices.state', InvoiceState::Issued->value)->whereRaw('('.self::LATEST_EMAIL_SQL.' IS NULL OR '.self::LATEST_EMAIL_SQL.' = ?)', [InvoiceEmailState::Skipped->value]),
                        default => $query,
                    };
                }),
        ];
    }

    /**
     * "Stav": the derived state as a filter, the very conditions of the list tabs, so a filter and a tab cannot
     * disagree. "Všechny" is no choice (it is the absence of a filter).
     */
    private static function statusFilter(): SelectFilter
    {
        return SelectFilter::make('status')
            ->label(__('invoices.list.columns.status'))
            ->options(static function (): array {
                $options = [];

                foreach (InvoiceListTab::cases() as $tab) {
                    if ($tab !== InvoiceListTab::All) {
                        $options[$tab->value] = $tab->getLabel();
                    }
                }

                return $options;
            })
            ->query(static function (Builder $query, array $data): Builder {
                $tab = is_string($data['value'] ?? null) ? InvoiceListTab::tryFrom($data['value']) : null;

                return $tab === null || $tab === InvoiceListTab::All
                    ? $query
                    : app(ReceivablesReader::class)->constrain($query, $tab, ReceivablesReader::today());
            });
    }

    /**
     * Every client that has a document, archived ones included and marked, in Czech order.
     *
     * @return array<string, string>
     */
    private static function clientOptions(): array
    {
        $used = Invoice::query()->select('invoices.client_id');
        $options = [];

        foreach (CzechCollation::orderBy(Client::query()->withTrashed()->whereIn('clients.id', $used), 'clients.name')->orderBy('clients.id')->get(['clients.id', 'clients.name', 'clients.deleted_at']) as $client) {
            $options[$client->id] = $client->trashed() ? __('invoices.list.filters.archived', ['name' => $client->name]) : $client->name;
        }

        return $options;
    }

    /**
     * The currencies the stored documents are written in.
     *
     * @return array<string, string>
     */
    private static function currencyOptions(): array
    {
        /** @var list<string> $codes */
        $codes = Invoice::query()->distinct()->orderBy('invoices.currency')->pluck('invoices.currency')->all();

        return array_combine($codes, $codes);
    }

    private static function dayText(string $day): string
    {
        return PragueDay::start($day)->format('j. n. Y');
    }

    /**
     * "Stáhnout PDF" of an issued document (ruling R-21-2): the signed link of the stored PDF, the Admin-only link
     * of plan 10-06; the dedicated invoice PDF route for both roles follows in plan 10-38.
     */
    private static function downloadAction(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('invoices.list.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(static fn (Invoice $record): bool => $record->isIssued() && $record->admin?->pdfDocument !== null)
            ->action(static function (Invoice $record, Component $livewire): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $user = auth()->user();
                $document = $record->admin?->pdfDocument;

                abort_unless($user instanceof User && $document !== null, 404);

                $livewire->redirect(DocumentUrl::download($document, $user));
            });
    }

    /**
     * "Upravit" (plan 10-25): a draft row opens the editor; an issued document has no editor.
     */
    private static function editDraftAction(): Action
    {
        return Action::make('editDraft')
            ->label(__('invoices.list.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(static fn (Invoice $record): bool => $record->isDraft())
            ->url(static fn (Invoice $record): string => InvoiceResource::getUrl('edit', ['record' => $record]));
    }

    /**
     * "Smazat koncept" (IN-10; D-05): the draft goes through DeleteDraft with the destructive confirmation, and its
     * entries are released in the same transaction. Only a draft row has it; an issued document is never deleted.
     */
    private static function deleteDraftAction(): Action
    {
        return Action::make('deleteDraft')
            ->label(__('invoices.list.actions.delete_draft'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(static fn (Invoice $record): bool => $record->isDraft())
            ->requiresConfirmation()
            ->modalHeading(__('invoices.list.delete.heading'))
            ->modalDescription(__('invoices.list.delete.body'))
            ->modalSubmitActionLabel(__('invoices.list.delete.submit'))
            ->modalCancelActionLabel(__('invoices.list.delete.cancel'))
            ->action(static function (Invoice $record): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                try {
                    app(DeleteDraft::class)->handle($user, $record);
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('invoices.list.delete.toast'))->send();
            });
    }
}
