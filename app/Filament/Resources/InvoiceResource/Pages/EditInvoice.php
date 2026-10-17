<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\RateFormat;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\DraftPreview;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Money\Money;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Pages\SettingsPage;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\DraftEditorPreview;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The draft editor (IN-02, IN-07, IN-08, IN-10; UI-SPEC Surface C): the header fields, the items table and the
 * summary, as a thin layer over the domain Actions.
 *
 * The form state is a plain array. "Uložit koncept" hands it to {@see UpdateDraft}, which re-validates every key and
 * computes every amount, so a value the browser sends for a total, a position or an id of another document changes
 * nothing (T-10-66). `Repeater::relationship()` is not used and the page never writes a row itself.
 *
 * Ruling R-25-1: the amount of each line and the summary are recomputed on the server on every change (the quantity
 * and the price are debounced) by {@see DraftEditorPreview}, never in JavaScript, so the single rounding point of the
 * amounts holds. The page is Admin only; the check runs on every Livewire request, not only on mount.
 *
 * Every helper that Livewire could expose is protected: a public method of a component can be called from the
 * browser.
 */
final class EditInvoice extends EditRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = InvoiceResource::class;

    protected ?DraftEditorPreview $preview = null;

    /**
     * The Admin check of every request, before any other code of the page runs. A Partner is refused with 403 before
     * the record is read: the Partner scope of a document would answer 404 for a draft.
     */
    public function boot(): void
    {
        abort_unless(InvoiceResource::canAccess(), 403);
    }

    /**
     * An issued document is never editable: its edit address goes to the document page.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $invoice = $this->invoice();

        if (! $invoice->isDraft()) {
            $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
        }
    }

    public function getTitle(): string
    {
        return match ($this->invoice()->kind) {
            InvoiceKind::Proforma => __('invoices.navigation.draft_proforma'),
            InvoiceKind::CreditNote => __('invoices.navigation.draft_credit_note'),
            InvoiceKind::Invoice => __('invoices.navigation.draft_invoice'),
        };
    }

    public function getHeading(): Htmlable
    {
        $badge = Blade::render('<x-filament::badge color="gray" style="vertical-align: middle;">{{ $label }}</x-filament::badge>', ['label' => __('invoices.draft.badge')]);

        return new HtmlString(e($this->getTitle()).' '.$badge);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                $this->documentSection(),
                $this->itemsSection(),
                $this->summarySection(),
            ]);
    }

    /**
     * A single "Uložit koncept" in the form footer; autosave is not used.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label(__('invoices.draft.actions.save'))
                ->color('gray'),
        ];
    }

    /**
     * The form state: the keys of the form only. Nothing else of the document travels to the browser as part of the
     * Livewire snapshot (the snapshots, the Admin half and the rates stay on the server).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $invoice = $this->invoice();
        $today = DraftEditorPreview::today();

        $issue = $invoice->issue_date?->format('Y-m-d');
        $due = $invoice->due_date?->format('Y-m-d');
        $derivedIssue = $issue === null ? $today : null;
        $derivedDue = $due === null ? $this->defaultDueDate($issue ?? $today) : null;

        $items = [];

        foreach (InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get() as $item) {
            $items[] = $this->itemState($item, $invoice->currency);
        }

        return [
            'currency' => $invoice->currency,
            'language' => $invoice->language->value,
            'issue_date' => $issue ?? $derivedIssue,
            'issue_date_default' => $derivedIssue,
            'due_date' => $due ?? $derivedDue,
            'due_date_default' => $derivedDue,
            'taxable_supply_date' => $invoice->taxable_supply_date?->format('Y-m-d'),
            'note' => $invoice->note,
            'internal_note' => $invoice->admin?->internal_note,
            'items' => $items,
        ];
    }

    /**
     * The values the Action takes: the dates the Admin did not type stay null, every item is a plain row, and nothing
     * else of the state (a forged total, a position) is passed on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $items = [];

        foreach (array_values(is_array($data['items'] ?? null) ? $data['items'] : []) as $item) {
            $line = is_array($item) ? $item : [];

            $items[] = [
                'id' => $line['id'] ?? null,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'unit' => $line['unit'] ?? null,
                'unit_price' => $line['unit_price'] ?? null,
                'confirm_zero_price' => (bool) ($line['confirm_zero_price'] ?? false),
            ];
        }

        return [
            'currency' => $data['currency'] ?? null,
            'language' => $data['language'] ?? null,
            'issue_date' => self::typedDate($data['issue_date'] ?? null, $data['issue_date_default'] ?? null),
            'due_date' => self::typedDate($data['due_date'] ?? null, $data['due_date_default'] ?? null),
            'note' => $data['note'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Invoice);

        $actor = auth()->user();
        assert($actor instanceof User);

        // The Action names an item error `items.<n>.<field>` by position; the repeater keys its rows by uuid.
        $keys = array_keys(is_array($this->data['items'] ?? null) ? $this->data['items'] : []);

        return $this->withFormErrors(function () use ($actor, $record, $data, $keys): Invoice {
            try {
                return app(UpdateDraft::class)->handle($actor, $record, $data);
            } catch (ValidationException $exception) {
                throw self::underRows($exception, $keys);
            } catch (DomainException $exception) {
                // The document was issued in the meantime: nothing was written.
                Notification::make()->danger()->title($exception->getMessage())->send();

                throw new Halt;
            }
        });
    }

    /**
     * The stored lines have their ids, positions and previews after a save, so the form is filled again.
     */
    protected function afterSave(): void
    {
        $this->invoice()->refresh();
        $this->preview = null;
        $this->fillForm();
    }

    private function documentSection(): Section
    {
        return Section::make(__('invoices.draft.sections.document'))
            ->columns(['default' => 1, 'lg' => 2])
            ->schema([
                TextEntry::make('client_name')
                    ->label(__('invoices.draft.fields.client'))
                    ->state(fn (): string => $this->client()->name)
                    ->url(fn (): string => ClientResource::getUrl('view', ['record' => $this->invoice()->client_id])),
                TextEntry::make('kind_label')
                    ->label(__('invoices.draft.fields.kind'))
                    ->state(fn (): string => $this->invoice()->kind->getLabel())
                    ->badge()
                    ->color('gray'),
                Select::make('currency')
                    ->label(__('invoices.draft.fields.currency'))
                    ->options(fn (): array => $this->currencyOptions())
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        $this->repriceGeneratedLines($get, $set, is_string($state) ? $state : $this->invoice()->currency);
                    }),
                TextEntry::make('bank_account')
                    ->label(__('invoices.draft.fields.bank_account'))
                    ->state(fn (Get $get): HtmlString => $this->bankAccountState($get('currency')))
                    ->color(fn (Get $get): ?string => $this->bankAccountFor($get('currency')) === null ? 'danger' : null)
                    ->html(),
                Select::make('language')
                    ->label(__('invoices.draft.fields.language'))
                    ->options(InvoiceLanguage::class)
                    ->native(false)
                    ->required(),
                Group::make([
                    DatePicker::make('issue_date')
                        ->label(__('invoices.draft.fields.issue_date'))
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $this->followIssueDate($get, $set);
                        }),
                    Hidden::make('issue_date_default'),
                ]),
                Group::make([
                    DatePicker::make('due_date')
                        ->label(__('invoices.draft.fields.due_date')),
                    Hidden::make('due_date_default'),
                ]),
                // IN-08: the taxable-supply date exists on a VAT payer's document only; in the non-payer mode the
                // field is not rendered at all.
                DatePicker::make('taxable_supply_date')
                    ->label(__('invoices.draft.fields.taxable_supply_date'))
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->invoice()->vat_mode === VatMode::Payer),
                TextEntry::make('payment_method')
                    ->label(__('invoices.draft.fields.payment_method'))
                    ->state(__('invoices.draft.payment_method_value')),
                TextEntry::make('variable_symbol')
                    ->label(__('invoices.draft.fields.variable_symbol'))
                    ->state(__('invoices.draft.helpers.variable_symbol'))
                    ->color('gray'),
                Textarea::make('note')
                    ->label(__('invoices.draft.fields.note'))
                    ->maxLength(500)
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('internal_note')
                    ->label(__('invoices.draft.fields.internal_note'))
                    ->helperText(__('invoices.draft.helpers.internal_note'))
                    ->maxLength(2000)
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    private function itemsSection(): Section
    {
        return Section::make(__('invoices.draft.sections.items'))
            ->schema([
                Text::make(__('invoices.draft.helpers.items'))->color('gray'),
                Repeater::make('items')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make(__('invoices.draft.columns.index'))->width('3rem'),
                        TableColumn::make(__('invoices.draft.columns.description')),
                        TableColumn::make(__('invoices.draft.columns.quantity'))->width('9rem')->alignEnd(),
                        TableColumn::make(__('invoices.draft.columns.unit'))->width('7rem'),
                        TableColumn::make(__('invoices.draft.columns.unit_price'))->width('11rem')->alignEnd(),
                        TableColumn::make(__('invoices.draft.columns.total'))->width('10rem')->alignEnd(),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        Text::make(fn (Component $component): string => $this->rowNumber($component))->color('gray'),
                        TextInput::make('description')
                            ->label(__('invoices.draft.columns.description'))
                            ->hiddenLabel()
                            ->maxLength(500),
                        TextInput::make('quantity')
                            ->label(__('invoices.draft.columns.quantity'))
                            ->hiddenLabel()
                            ->live(debounce: 500)
                            ->extraInputAttributes(['inputmode' => 'decimal', 'style' => 'text-align: right; font-variant-numeric: tabular-nums;']),
                        TextInput::make('unit')
                            ->label(__('invoices.draft.columns.unit'))
                            ->hiddenLabel()
                            ->datalist(['h', 'ks', 'den', 'měs.', 'paušál'])
                            ->maxLength(16),
                        TextInput::make('unit_price')
                            ->label(__('invoices.draft.columns.unit_price'))
                            ->hiddenLabel()
                            ->live(debounce: 500)
                            ->extraInputAttributes(['inputmode' => 'decimal', 'style' => 'text-align: right; font-variant-numeric: tabular-nums;']),
                        Text::make(fn (Get $get): string => $this->lineTotalText($get))
                            ->weight(FontWeight::Medium)
                            ->extraAttributes(['style' => 'text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;']),
                    ])
                    ->addActionLabel(__('invoices.draft.item_actions.add'))
                    ->defaultItems(0)
                    ->reorderable()
                    ->reorderableWithButtons(false)
                    ->deleteAction(fn (Action $action, Component $component): Action => $action
                        ->label(fn (array $arguments): string => __('invoices.draft.item_actions.delete', ['n' => $this->positionOf($component, $arguments['item'] ?? null)]))
                        ->icon(Heroicon::OutlinedTrash))
                    ->moveUpAction(fn (Action $action): Action => $action->label(__('invoices.draft.item_actions.move_up')))
                    ->moveDownAction(fn (Action $action): Action => $action->label(__('invoices.draft.item_actions.move_down'))),
            ]);
    }

    private function summarySection(): View
    {
        return View::make('filament.invoices.draft-summary')
            ->viewData(fn (): array => $this->summaryData());
    }

    /**
     * The strings of the summary block, computed on the server from the lines in the form (R-25-1).
     *
     * @return array{subtotal: string|null, vat: string|null, total: string, czk: string|null, czkMissing: string|null}
     */
    private function summaryData(): array
    {
        $currency = $this->currentCurrency();
        $totals = [];

        foreach ($this->lineStates() as $line) {
            $totals[] = $this->preview()->line(self::text($line['id'] ?? null), $line['quantity'] ?? null, $line['unit_price'] ?? null, $currency)['total'];
        }

        $summary = $this->preview()->summary($totals, $currency);
        $czk = null;
        $missing = null;

        if ($summary['czk'] !== null) {
            $quote = $summary['czk'];
            $rate = RateFormat::display($quote['rate']).($quote['unit'] === 1 ? '' : ' / '.$quote['unit']);
            $czk = (string) __('invoices.draft.summary.czk_preview', [
                'czk' => $quote['czk']->format('cs'),
                'rate' => $rate,
                'date' => IssueChecks::czechDate($quote['rate_date']),
            ]);
        } elseif ($summary['czk_missing']) {
            $missing = (string) __('invoices.errors.cnb_missing', ['currency' => $currency, 'date' => IssueChecks::czechDate(DraftEditorPreview::today())]);
        }

        // The non-payer mode has no VAT line, and the subtotal equals the total (IN-08).
        return [
            'subtotal' => null,
            'vat' => null,
            'total' => $summary['total']->format('cs'),
            'czk' => $czk,
            'czkMissing' => $missing,
        ];
    }

    /**
     * The amount of one line, as text; a dash when the quantity or the price is not usable yet.
     */
    private function lineTotalText(Get $get): string
    {
        $line = $this->preview()->line(self::text($get('id')), $get('quantity'), $get('unit_price'), $this->currentCurrency());

        return $line['total']?->format('cs') ?? __('invoices.navigation.empty_value');
    }

    /**
     * The state of one stored line: the figures as a person types them.
     *
     * @return array<string, mixed>
     */
    private function itemState(InvoiceItem $item, string $currency): array
    {
        return [
            'id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity()->format('cs'),
            'unit' => $item->unit,
            'unit_price' => DraftEditorPreview::priceText(Money::ofMinor($item->unit_price_minor, $currency)),
            'confirm_zero_price' => false,
        ];
    }

    /**
     * A change of currency shows the price of a generated line in the new currency (the Action stores the same
     * figure); the prices typed by hand are never changed.
     */
    private function repriceGeneratedLines(Get $get, Set $set, string $currency): void
    {
        $items = $get('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $line = $this->preview()->line(self::text($item['id'] ?? null), $item['quantity'] ?? null, null, $currency);

            if ($line['generated'] && $line['unit_price'] instanceof Money) {
                $items[$key]['unit_price'] = DraftEditorPreview::priceText($line['unit_price']);
            }
        }

        $set('items', $items);
    }

    /**
     * An untouched due date follows the issue date; one the Admin typed does not move (R-11-1).
     */
    private function followIssueDate(Get $get, Set $set): void
    {
        $default = $get('due_date_default');
        $issue = $get('issue_date');

        if (! is_string($default) || $get('due_date') !== $default || ! is_string($issue) || $issue === '') {
            return;
        }

        $due = $this->defaultDueDate($issue);

        $set('due_date', $due);
        $set('due_date_default', $due);
    }

    /**
     * The issue date plus the payment terms of the client, as the issue will compute it.
     */
    private function defaultDueDate(string $issueDate): string
    {
        $terms = $this->client()->payment_terms_days;
        $issue = CarbonImmutable::createFromFormat('!Y-m-d', $issueDate, 'UTC');

        return $issue instanceof CarbonImmutable ? $issue->addDays($terms)->format('Y-m-d') : $issueDate;
    }

    /**
     * A date the Admin did not type is not stored: the value that equals the derived default stays null.
     */
    private static function typedDate(mixed $value, mixed $default): ?string
    {
        if (! is_string($value) || trim($value) === '' || $value === $default) {
            return null;
        }

        return $value;
    }

    /**
     * The currency in the form, or the stored one when the form holds something unknown.
     */
    private function currentCurrency(): string
    {
        $currency = $this->data['currency'] ?? null;

        return is_string($currency) && Money::isKnownCurrency(strtoupper($currency)) ? strtoupper($currency) : $this->invoice()->currency;
    }

    /**
     * The currencies of the supplier's bank accounts, the currency of the client and the one the draft has now.
     *
     * @return array<string, string>
     */
    private function currencyOptions(): array
    {
        $codes = array_map(static fn (BankAccount $account): string => $account->currency, app(BankAccountSettings::class)->accounts);
        $codes[] = $this->invoice()->currency;

        $codes[] = $this->client()->currency;

        $codes = array_values(array_unique($codes));
        sort($codes);

        return array_combine($codes, $codes);
    }

    private function bankAccountFor(mixed $currency): ?BankAccount
    {
        return is_string($currency) && $currency !== '' ? app(BankAccountSettings::class)->forCurrency($currency) : null;
    }

    /**
     * The account of the currency, or the danger message with the link to the settings (UI-SPEC C1).
     */
    private function bankAccountState(mixed $currency): HtmlString
    {
        $account = $this->bankAccountFor($currency);

        if ($account instanceof BankAccount) {
            return new HtmlString(e($account->label.' · '.self::accountNumber($account)));
        }

        $message = e(__('invoices.errors.bank_missing', ['currency' => is_string($currency) ? $currency : $this->invoice()->currency]));
        $link = '<a href="'.e(SettingsPage::getUrl()).'" style="text-decoration: underline;">'.e(__('invoices.draft.settings_link')).'</a>';

        return new HtmlString(str_replace(e(__('invoices.draft.settings_link')), $link, $message));
    }

    private static function accountNumber(BankAccount $account): string
    {
        if ($account->accountNumber !== null) {
            return $account->accountNumber.($account->bankCode !== null ? '/'.$account->bankCode : '');
        }

        return trim(chunk_split((string) $account->iban, 4, ' '));
    }

    /**
     * The rows of the form in their order, each as the state array of the row.
     *
     * @return list<array<string, mixed>>
     */
    private function lineStates(): array
    {
        $items = $this->data['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * The position (from 1) of the row with the repeater key.
     */
    private function positionOf(Component $component, mixed $key): int
    {
        $keys = array_keys($component->getRawState() ?? []);
        $index = array_search($key, $keys, true);

        return $index === false ? 0 : $index + 1;
    }

    private function rowNumber(Component $component): string
    {
        $key = Str::afterLast((string) $component->getContainer()->getStatePath(), '.');
        $keys = array_keys(is_array($this->data['items'] ?? null) ? $this->data['items'] : []);
        $index = array_search($key, $keys, true);

        return $index === false ? '' : (string) ($index + 1);
    }

    /**
     * Item errors by the uuid of the row, so each lands under its own cell.
     *
     * @param  list<int|string>  $keys  the repeater keys in the order of the rows
     */
    private static function underRows(ValidationException $exception, array $keys): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            if (preg_match('/^items\.(\d+)\.(.+)$/D', $key, $match) === 1 && isset($keys[(int) $match[1]])) {
                $key = 'items.'.$keys[(int) $match[1]].'.'.$match[2];
            }

            $messages[$key] = $errors;
        }

        return ValidationException::withMessages($messages);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function preview(): DraftEditorPreview
    {
        return $this->preview ??= new DraftEditorPreview($this->invoice(), app(DraftPreview::class));
    }

    /**
     * The client of the draft, an archived one included.
     */
    private function client(): Client
    {
        $client = $this->invoice()->client;
        assert($client instanceof Client);

        return $client;
    }

    private function invoice(): Invoice
    {
        $record = $this->getRecord();
        assert($record instanceof Invoice);

        return $record;
    }
}
