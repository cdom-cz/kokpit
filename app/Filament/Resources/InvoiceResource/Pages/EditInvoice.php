<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\ExchangeRates\RateFormat;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\AddFixedAmount;
use App\Domain\Invoicing\Actions\DeleteDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\DraftPreview;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Payments\Support\CardPaymentSwitch;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Pages\SettingsPage;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ExchangeRateResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\DraftEditorPreview;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Throwable;

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

    /**
     * Autosave is not used, so leaving the page with unsaved edits raises Filament's stock alert (UI-SPEC Surface C).
     */
    protected ?bool $hasUnsavedDataChangesAlert = true;

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
     * "Smazat koncept" (IN-10): the destructive confirmation, DeleteDraft, and back to the list; "Vystavit" (IN-01,
     * UI-SPEC Surface D): the pre-issue check and the confirmation of the issue.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->deleteDraftAction(), $this->issueAction()];
    }

    /**
     * "Vystavit" (UI-SPEC Surface D): saves the form, runs the pre-issue check and opens the confirmation only when
     * nothing blocks. The number shown in the confirmation is the preview of the counter and may change (F-14); the
     * allocation happens in {@see IssueDocument}, in one transaction, and a failure there leaves the draft and the
     * counter as they were.
     */
    private function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('invoices.draft.actions.issue'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalWidth(Width::Large)
            ->modalHeading(fn (): string => (string) __('invoices.issue.heading', ['kind' => $this->kindAccusative()]))
            ->modalSubmitActionLabel(fn (): string => (string) __($this->invoice()->kind === InvoiceKind::Proforma ? 'invoices.issue.submit.proforma' : 'invoices.issue.submit.invoice'))
            ->modalCancelActionLabel(__('invoices.issue.cancel'))
            ->mountUsing(function (Action $action, ?Schema $schema): void {
                $this->precheckIssue($action);
                $schema?->fill();
            })
            ->schema(fn (): array => $this->issueConfirmation())
            ->action(function (): void {
                $this->issue();
            });
    }

    /**
     * Saves the form, then lists every blocker without opening the modal. A form that does not validate stays on its
     * fields; a document that was issued in the meantime goes to its page.
     */
    private function precheckIssue(Action $action): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        $invoice = $this->invoice()->refresh();

        if (! $invoice->isDraft()) {
            $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));

            $action->halt();
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            $action->halt();
        }

        $blockers = app(IssueChecks::class)->blockers($invoice, IssueContext::today());

        if ($blockers !== []) {
            $this->notifyBlockers($blockers);

            $action->halt();
        }
    }

    /**
     * The danger notification of the blockers: one line each, with the links where a screen can fix the cause.
     *
     * @param  list<array{key: string, message: string}>  $blockers
     */
    private function notifyBlockers(array $blockers): void
    {
        $items = '';
        $links = [];

        foreach ($blockers as $blocker) {
            $items .= '<li>'.e($blocker['message']).'</li>';

            match ($blocker['key']) {
                'supplier_missing', 'bank_missing', 'vat_payer_unsupported', 'pattern_invalid' => $links['settings'] = Action::make('openSettings')
                    ->label(__('invoices.issue.links.settings'))
                    ->url(SettingsPage::getUrl())
                    ->link(),
                'cnb_missing' => $links['rates'] = Action::make('openRates')
                    ->label(__('invoices.issue.links.rates'))
                    ->url(ExchangeRateResource::getUrl('index'))
                    ->link(),
                default => null,
            };
        }

        Notification::make()
            ->danger()
            ->title(__('invoices.issue.blockers_title'))
            ->body('<ul>'.$items.'</ul>')
            ->actions(array_values($links))
            ->persistent()
            ->send();
    }

    /**
     * The content of the confirmation: the figures the issue will freeze, the CNB preview of a foreign currency and
     * the callout with the expected number.
     *
     * @return array<Component>
     */
    private function issueConfirmation(): array
    {
        $invoice = $this->invoice();
        $day = $this->issueDay();

        $rows = [
            TextEntry::make('issue_client')
                ->label(__('invoices.issue.summary.client'))
                ->state(fn (): string => $this->client()->name),
            TextEntry::make('issue_total')
                ->label(__('invoices.issue.summary.total'))
                ->state(fn (): string => $this->invoice()->total()->format('cs')),
            TextEntry::make('issue_date')
                ->label(__('invoices.issue.summary.issue_date'))
                ->state(fn (): string => IssueChecks::czechDate($this->issueDay())),
            TextEntry::make('issue_due')
                ->label(__('invoices.issue.summary.due_date'))
                ->state(fn (): string => IssueChecks::czechDate($this->invoice()->due_date?->format('Y-m-d') ?? $this->defaultDueDate($this->issueDay()))),
            TextEntry::make('issue_currency')
                ->label(__('invoices.issue.summary.currency'))
                ->state(fn (): string => $this->invoice()->currency),
            TextEntry::make('issue_card_payment')
                ->label(__('invoices.issue.summary.card_payment'))
                ->state(fn (): string => (string) __($this->cardPaymentAtIssue() ? 'invoices.issue.summary.card_payment_yes' : 'invoices.issue.summary.card_payment_no')),
        ];

        $quote = $invoice->currency === 'CZK' ? null : $this->czkQuote($day);

        if ($quote !== null) {
            $rows[] = TextEntry::make('issue_rate')
                ->hiddenLabel()
                ->state(fn (): string => (string) __('invoices.issue.summary.rate', [
                    'rate' => RateFormat::display($quote['rate']).($quote['unit'] === 1 ? '' : ' / '.$quote['unit']),
                    'czk' => $quote['czk']->format('cs'),
                ]));
        }

        return [
            ...$rows,
            Callout::make(fn (): string => (string) __('invoices.issue.callout', ['number' => app(IssueDocument::class)->expectedNumber($this->invoice())]))
                ->warning(),
        ];
    }

    /**
     * What the issue will freeze as the card switch (D-06): the stored draft switch ANDed with the global setting and a
     * configured Stripe, for an invoice or a proforma only.
     */
    private function cardPaymentAtIssue(): bool
    {
        return app(CardPaymentSwitch::class)->effective($this->invoice()->kind, $this->storedCardPayment());
    }

    /**
     * The switch stored on the Admin-only row of the draft, read fresh so a save just made is seen.
     */
    private function storedCardPayment(): bool
    {
        return InvoiceAdmin::query()->where('invoice_id', $this->invoice()->getKey())->value('card_payment_enabled') === true;
    }

    /**
     * The CNB preview of a foreign-currency draft for the issue day, or null when the list is missing (the blockers
     * have named that already).
     *
     * @return array{czk: Money, rate: string, unit: int, rate_date: string}|null
     */
    private function czkQuote(string $day): ?array
    {
        try {
            return app(DraftPreview::class)->czkPreview($this->invoice(), $day);
        } catch (ExchangeRateMissing) {
            return null;
        }
    }

    /**
     * Issues the draft through {@see IssueDocument}. A refusal that comes from the blockers is listed like the
     * pre-check; any other failure shows the fixed copy and nothing of the exception: the cause goes to the log.
     */
    private function issue(): void
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            $issued = app(IssueDocument::class)->handle($actor, $this->invoice());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $blockers = [];

            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $blockers[] = ['key' => (string) $key, 'message' => $message];
                }
            }

            $this->notifyBlockers($blockers);

            return;
        } catch (DomainException) {
            Notification::make()->danger()->title(__('invoices.errors.not_draft'))->send();

            $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->invoice()]));

            return;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__('invoices.issue.failed_title'))
                ->body(__('invoices.issue.failed_body'))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__($issued->kind === InvoiceKind::Proforma ? 'invoices.issue.toast.proforma' : 'invoices.issue.toast.invoice'))
            ->send();

        // Plan 10-31 opens the e-mail dialog from this flag (ruling R-29-1).
        $this->redirect(InvoiceResource::getUrl('view', ['record' => $issued, 'send' => 1]));
    }

    /**
     * The day the issue will use: the typed issue date or today.
     */
    private function issueDay(): string
    {
        return $this->invoice()->issue_date?->format('Y-m-d') ?? IssueContext::today();
    }

    private function kindAccusative(): string
    {
        return (string) __('invoices.issue.kind_accusative.'.$this->invoice()->kind->value);
    }

    /**
     * Throws the draft away through {@see DeleteDraft}, which frees every reserved time entry in the same transaction
     * and refuses an issued document on the locked row. A draft has no number, so nothing is consumed.
     */
    private function deleteDraftAction(): Action
    {
        return Action::make('deleteDraft')
            ->label(__('invoices.list.actions.delete_draft'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('invoices.list.delete.heading'))
            ->modalDescription(__('invoices.list.delete.body'))
            ->modalSubmitActionLabel(__('invoices.list.delete.submit'))
            ->modalCancelActionLabel(__('invoices.list.delete.cancel'))
            ->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(DeleteDraft::class)->handle($actor, $this->invoice());
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('invoices.list.delete.toast'))->send();

                $this->redirect(InvoiceResource::getUrl('index'));
            });
    }

    /**
     * Lines that were stored outside the form (a fixed amount, time added in the tab) join the rows of the form,
     * without touching the rows being edited.
     */
    #[On('draft-lines-changed')]
    public function mergeStoredLines(): void
    {
        $this->preview = null;
        $this->appendStoredLines();
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
            // A disabled switch shows off whatever is stored; it is not dehydrated, so the stored value stays.
            'card_payment_enabled' => app(CardPaymentSwitch::class)->available() && $this->storedCardPayment(),
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

        $values = [
            'currency' => $data['currency'] ?? null,
            'language' => $data['language'] ?? null,
            'issue_date' => self::typedDate($data['issue_date'] ?? null, $data['issue_date_default'] ?? null),
            'due_date' => self::typedDate($data['due_date'] ?? null, $data['due_date_default'] ?? null),
            'note' => $data['note'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'items' => $items,
        ];

        // The switch travels only when the toggle was editable (a disabled or hidden field is not dehydrated), so a
        // draft kept off by the settings keeps the value it had (D-06).
        if (array_key_exists('card_payment_enabled', $data) && $this->invoice()->kind->carriesPayment()) {
            $values['card_payment_enabled'] = (bool) $data['card_payment_enabled'];
        }

        return $values;
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

    /**
     * The helper of the card switch: the standing note, or the reason the switch is disabled (UI-SPEC Surface D).
     */
    private function cardPaymentHelper(): string
    {
        return (string) match (app(CardPaymentSwitch::class)->unavailableReason()) {
            CardPaymentSwitch::SETTINGS_OFF => __('invoices.draft.helpers.card_payment_settings_off'),
            CardPaymentSwitch::STRIPE_MISSING => __('invoices.draft.helpers.card_payment_stripe_missing'),
            default => __('invoices.draft.helpers.card_payment'),
        };
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
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old): void {
                        $this->currencyChosen($get, $set, $state, $old);
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
                Toggle::make('card_payment_enabled')
                    ->label(__('invoices.draft.fields.card_payment'))
                    ->helperText(fn (): string => $this->cardPaymentHelper())
                    ->disabled(fn (): bool => ! app(CardPaymentSwitch::class)->available())
                    ->visible(fn (): bool => $this->invoice()->kind->carriesPayment()),
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
        $max = $this->maxItems();

        return Section::make(__('invoices.draft.sections.items'))
            ->schema([
                Text::make(__('invoices.draft.helpers.items'))->color('gray'),
                Text::make(__('invoices.draft.empty_items'))
                    ->color('gray')
                    ->visible(fn (): bool => $this->lineStates() === []),
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
                        Group::make([
                            TextInput::make('description')
                                ->label(__('invoices.draft.columns.description'))
                                ->hiddenLabel()
                                ->maxLength(500)
                                ->extraInputAttributes(['data-draft-description' => 'true']),
                            Text::make(__('invoices.draft.badges.from_time'))
                                ->badge()
                                ->color('gray')
                                ->icon(Heroicon::OutlinedLink)
                                ->tooltip(__('invoices.draft.badges.from_time_tooltip'))
                                ->visible(fn (Get $get): bool => $this->lineOf($get)['from_time']),
                        ]),
                        Group::make([
                            TextInput::make('quantity')
                                ->label(__('invoices.draft.columns.quantity'))
                                ->hiddenLabel()
                                ->default('1')
                                ->live(debounce: 500)
                                ->extraInputAttributes(['inputmode' => 'decimal', 'style' => 'text-align: right; font-variant-numeric: tabular-nums;']),
                            Text::make(fn (Get $get): string => DurationFormat::hoursMinutes($this->lineOf($get)['seconds'] ?? 0))
                                ->color('gray')
                                ->extraAttributes(['style' => 'text-align: right; font-variant-numeric: tabular-nums;'])
                                ->visible(fn (Get $get): bool => $this->lineOf($get)['seconds'] !== null),
                        ]),
                        TextInput::make('unit')
                            ->label(__('invoices.draft.columns.unit'))
                            ->hiddenLabel()
                            ->default('ks')
                            ->datalist(['h', 'ks', 'den', 'měs.', 'paušál'])
                            ->maxLength(16),
                        Group::make([
                            TextInput::make('unit_price')
                                ->label(__('invoices.draft.columns.unit_price'))
                                ->hiddenLabel()
                                ->live(debounce: 500)
                                ->extraInputAttributes(['inputmode' => 'decimal', 'style' => 'text-align: right; font-variant-numeric: tabular-nums;']),
                            Checkbox::make('confirm_zero_price')
                                ->label(__('invoices.draft.fields.confirm_zero_price'))
                                ->visible(fn (Get $get): bool => $this->lineOf($get)['unit_price']?->isZero() === true),
                            Text::make(fn (Get $get): string => (string) __('invoices.draft.badges.preview_rate', ['rate' => $this->lineOf($get)['rate'] ?? '']))
                                ->color('gray')
                                ->extraAttributes(['style' => 'text-align: right;'])
                                ->visible(fn (Get $get): bool => $this->lineOf($get)['converted'] && $this->lineOf($get)['rate'] !== null),
                            Text::make(__('invoices.draft.badges.convert_at_issue'))
                                ->badge()
                                ->color('info')
                                ->visible(fn (Get $get): bool => $this->lineOf($get)['converted']),
                        ]),
                        Text::make(fn (Get $get): string => $this->lineTotalText($get))
                            ->weight(FontWeight::Medium)
                            ->extraAttributes(['style' => 'text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;']),
                    ])
                    ->maxItems($max)
                    ->addActionLabel(__('invoices.draft.item_actions.add'))
                    ->addAction(fn (Action $action): Action => $action
                        ->icon(Heroicon::OutlinedPlus)
                        ->after(function (): void {
                            // The new row takes the focus in its description.
                            $this->js("setTimeout(() => { const fields = document.querySelectorAll('[data-draft-description]'); fields[fields.length - 1]?.focus() }, 50)");
                        }))
                    ->defaultItems(0)
                    ->reorderable()
                    ->reorderableWithButtons(false)
                    ->deleteAction(fn (Action $action, Component $component): Action => $action
                        ->label(fn (array $arguments): string => __('invoices.draft.item_actions.delete', ['n' => $this->positionOf($component, $arguments['item'] ?? null)]))
                        ->icon(Heroicon::OutlinedTrash))
                    ->moveUpAction(fn (Action $action): Action => $action->label(__('invoices.draft.item_actions.move_up')))
                    ->moveDownAction(fn (Action $action): Action => $action->label(__('invoices.draft.item_actions.move_down'))),
                Text::make(__('invoices.errors.items_cap', ['max' => $max]))
                    ->color('gray')
                    ->visible(fn (): bool => count($this->lineStates()) >= $max),
                Actions::make([
                    $this->addFixedAmountAction(),
                    $this->addTimeAction(),
                ])->key('itemActions'),
            ]);
    }

    /**
     * "Přidat pevnou částku" (UI-SPEC C3, D-07): the modal hands its data to {@see AddFixedAmount}, which re-checks the
     * project, the amount and the cap. The line is stored at once and joins the rows of the form; what the Admin
     * edited in the form and did not save yet stays as it is.
     */
    private function addFixedAmountAction(): Action
    {
        return Action::make('addFixedAmount')
            ->label(__('invoices.draft.item_actions.add_fixed'))
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->visible(fn (): bool => count($this->lineStates()) < $this->maxItems())
            ->modalHeading(__('invoices.draft.fixed_amount.heading'))
            ->modalSubmitActionLabel(__('invoices.draft.fixed_amount.submit'))
            ->modalCancelActionLabel(__('invoices.draft.fixed_amount.cancel'))
            ->schema([
                Select::make('project_id')
                    ->label(__('invoices.draft.fixed_amount.fields.project'))
                    ->options(fn (): array => $this->fixedProjectOptions())
                    ->native(false)
                    ->required()
                    ->live(),
                TextInput::make('description')
                    ->label(__('invoices.draft.fixed_amount.fields.description'))
                    ->placeholder(fn (Get $get): ?string => $this->fixedProject($get('project_id'))?->name)
                    ->maxLength(500),
                TextInput::make('amount')
                    ->label(__('invoices.draft.fixed_amount.fields.amount'))
                    ->suffix($this->currentCurrency())
                    ->required()
                    ->extraInputAttributes(['inputmode' => 'decimal'])
                    ->helperText(fn (Get $get): ?string => $this->fixedAmountHelper($get('project_id'))),
                Checkbox::make('link_time')
                    ->label(__('invoices.draft.fixed_amount.fields.mark_time'))
                    ->default(false)
                    ->helperText(fn (Get $get): ?string => $this->linkTimeHelper($get('project_id'))),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(AddFixedAmount::class)->handle($actor, $this->invoice(), [
                        'project_id' => $data['project_id'] ?? null,
                        'description' => $data['description'] ?? null,
                        'amount' => $data['amount'] ?? null,
                        'link_time' => (bool) ($data['link_time'] ?? false),
                    ]);
                } catch (ValidationException $exception) {
                    throw self::underModal($exception);
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                $this->preview = null;
                $this->appendStoredLines();

                Notification::make()->success()->title(__('invoices.draft.fixed_amount.toast'))->send();
            });
    }

    /**
     * "Přidat nový čas" in the row of the add buttons: it opens the same dialog as the header action of the tab
     * "Záznamy času" (UI-SPEC C2, C4).
     */
    private function addTimeAction(): Action
    {
        return Action::make('addTime')
            ->label(__('invoices.draft.item_actions.add_time'))
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->visible(fn (): bool => count($this->lineStates()) < $this->maxItems())
            ->action(function (): void {
                $this->dispatch('draft-add-time');
            });
    }

    /**
     * Asks before the currency of a draft with prices typed by hand changes (UI-SPEC C1): those prices are not
     * converted and the Admin checks them afterwards. The change itself is applied here, on confirmation only.
     */
    protected function changeCurrencyAction(): Action
    {
        return Action::make('changeCurrency')
            ->requiresConfirmation()
            ->modalHeading(__('invoices.draft.currency_change.heading'))
            ->modalDescription(__('invoices.draft.currency_change.body'))
            ->modalSubmitActionLabel(__('invoices.draft.currency_change.submit'))
            ->modalCancelActionLabel(__('invoices.draft.currency_change.cancel'))
            ->action(function (array $arguments): void {
                $currency = strtoupper(is_string($arguments['currency'] ?? null) ? $arguments['currency'] : '');

                if (! array_key_exists($currency, $this->currencyOptions())) {
                    return;
                }

                $data = $this->data ?? [];
                $data['currency'] = $currency;
                $data['items'] = $this->repricedItems(is_array($data['items'] ?? null) ? $data['items'] : [], $currency);
                $this->data = $data;
            });
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
     * The Admin picked a currency. With prices typed by hand in the lines the change waits for the confirmation (the
     * field keeps the old value meanwhile); without them it applies at once.
     */
    private function currencyChosen(Get $get, Set $set, mixed $state, mixed $old): void
    {
        $currency = is_string($state) ? strtoupper($state) : '';

        if ($currency === '' || ! array_key_exists($currency, $this->currencyOptions())) {
            $set('currency', is_string($old) ? $old : $this->invoice()->currency);

            return;
        }

        $items = is_array($get('items')) ? $get('items') : [];

        if ($this->hasTypedPrices($items, is_string($old) ? $old : $this->invoice()->currency)) {
            $set('currency', is_string($old) ? $old : $this->invoice()->currency);
            $this->mountAction('changeCurrency', ['currency' => $currency]);

            return;
        }

        $set('items', $this->repricedItems($items, $currency));
    }

    /**
     * Whether any line carries a price that was typed by hand: a line that does not follow an hourly rate.
     *
     * @param  array<array-key, mixed>  $items
     */
    private function hasTypedPrices(array $items, string $currency): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $line = $this->preview()->line(self::text($item['id'] ?? null), $item['quantity'] ?? null, $item['unit_price'] ?? null, $currency);

            if (! $line['generated'] && DraftEditorPreview::price($item['unit_price'] ?? null, $currency) instanceof Money) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows with the price of every generated line shown in the new currency (the Action stores the same figure);
     * the prices typed by hand are never changed.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<array-key, mixed>
     */
    private function repricedItems(array $items, string $currency): array
    {
        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $line = $this->preview()->line(self::text($item['id'] ?? null), $item['quantity'] ?? null, null, $currency);

            if ($line['generated'] && $line['unit_price'] instanceof Money) {
                $items[$key]['unit_price'] = DraftEditorPreview::priceText($line['unit_price']);
            }
        }

        return $items;
    }

    /**
     * The stored lines that are not rows of the form yet join it at the end, in their stored order.
     */
    private function appendStoredLines(): void
    {
        $data = $this->data ?? [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $known = array_filter(array_column($items, 'id'), 'is_string');

        foreach (InvoiceItem::query()->where('invoice_id', $this->invoice()->id)->orderBy('position')->get() as $item) {
            if (! in_array($item->id, $known, true)) {
                $items[(string) Str::uuid()] = $this->itemState($item, $this->invoice()->currency);
            }
        }

        $data['items'] = $items;
        $this->data = $data;
    }

    /**
     * The fixed-price projects of the client, archived ones included (UI-SPEC C3).
     *
     * @return array<string, string>
     */
    private function fixedProjectOptions(): array
    {
        $query = Project::query()
            ->withTrashed()
            ->where('projects.client_id', $this->invoice()->client_id)
            ->whereHas('billing', static fn ($billing) => $billing->where('billing_type', BillingType::FixedPrice->value));

        $options = [];

        foreach (CzechCollation::orderBy($query, 'projects.name')->orderBy('projects.id')->get() as $project) {
            $options[$project->id] = $project->trashed() ? (string) __('invoices.create.archived_project', ['name' => $project->name]) : $project->name;
        }

        return $options;
    }

    /**
     * A fixed-price project of this client by id, or null; a forged id finds nothing.
     */
    private function fixedProject(mixed $id): ?Project
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Project::query()
            ->withTrashed()
            ->with('billing')
            ->where('projects.client_id', $this->invoice()->client_id)
            ->whereKey($id)
            ->first();
    }

    /**
     * "Pevná cena projektu: :price. Dosud vyfakturováno: :billed." under the amount.
     */
    private function fixedAmountHelper(mixed $projectId): ?string
    {
        $project = $this->fixedProject($projectId);

        if (! $project instanceof Project) {
            return null;
        }

        $context = app(AddFixedAmount::class)->context($this->invoice(), $project);
        $empty = (string) __('invoices.navigation.empty_value');
        $billed = $context['billed'] === []
            ? Money::zero($this->invoice()->currency)->format('cs')
            : implode(', ', array_map(static fn (Money $money): string => $money->format('cs'), $context['billed']));

        return (string) __('invoices.draft.fixed_amount.helper', [
            'price' => $context['price']?->format('cs') ?? $empty,
            'billed' => $billed,
        ]);
    }

    /**
     * The count and the H:MM of the time the option would take along.
     */
    private function linkTimeHelper(mixed $projectId): ?string
    {
        $project = $this->fixedProject($projectId);

        if (! $project instanceof Project) {
            return null;
        }

        $context = app(AddFixedAmount::class)->context($this->invoice(), $project);

        return trans_choice('invoices.draft.fixed_amount.mark_time_helper', $context['unbilled_count'], [
            'count' => $context['unbilled_count'],
            'duration' => DurationFormat::hoursMinutes($context['unbilled_seconds']),
        ]);
    }

    private function maxItems(): int
    {
        return (int) config('kokpit.invoicing.max_items');
    }

    /**
     * The figures of the row the closure runs in.
     *
     * @return array{quantity: InvoiceQuantity|null, unit_price: Money|null, total: Money|null, from_time: bool, seconds: int|null, generated: bool, converted: bool, rate: string|null, stored: bool}
     */
    private function lineOf(Get $get): array
    {
        return $this->preview()->line(self::text($get('id')), $get('quantity'), $get('unit_price'), $this->currentCurrency());
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path.
     */
    private static function underModal(ValidationException $exception): ValidationException
    {
        $mapped = [];

        foreach ($exception->errors() as $key => $messages) {
            $field = in_array($key, ['project_id', 'description', 'amount'], true) ? $key : 'amount';
            $path = 'mountedActions.0.data.'.$field;
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
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
