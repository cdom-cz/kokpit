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
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\DocumentPayment;
use App\Domain\Invoicing\Support\DraftPreview;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
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
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use App\Filament\Support\DraftEditorPreview;
use App\Filament\Support\InvoiceColumns;
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
use Filament\Schemas\Components\Fieldset;
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
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Throwable;

/**
 * The document editor (IN-02, IN-07, IN-08, IN-10, IN-13, IN-14; UI-SPEC Surface C): the header fields, the items table
 * and the summary, as a thin layer over the domain Actions. It serves two kinds of document with one form (research:
 * generalise the editor, never fork it):
 *
 * - a draft, in draft mode: "Uložit koncept" hands the form state to {@see UpdateDraft}, and the header actions
 *   issue and delete the draft;
 * - an open issued invoice or proforma, in issued mode (R-10.1-06-1): reached from the detail, the number and the kind
 *   are shown read-only, "Vystavit" and "Smazat koncept" are absent, and "Uložit změny" hands the lines, the notes, the
 *   header, the payment method, the bank account and the card switch to {@see UpdateIssuedDocument} (Phase 14: the
 *   three payment fields follow the rules of the draft editor, and the Action re-checks them). The address of a document that cannot be edited (locked, a credit note, cancelled, converted, an e-mail on its way, see
 *   {@see IssuedDocumentRules::editBlocker()}) redirects to the detail with the reason as a notification. A line that a
 *   credit note credits shows its quantity, unit and unit price disabled and cannot be removed (A4); the Action keeps
 *   that rule whatever the browser sends.
 *
 * The form state is a plain array. The Action re-validates every key and computes every amount, so a value the
 * browser sends for a total, a position or an id of another document changes nothing (T-10-66).
 * `Repeater::relationship()` is not used and the page never writes a row itself.
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

    /**
     * Whether the page was opened on an issued document. Locked, so the browser cannot flip the mode.
     */
    #[Locked]
    public bool $issuedEditor = false;

    protected ?DraftEditorPreview $preview = null;

    /**
     * The issue date the document had when the save began, `Y-m-d`, to say after the save that the rates stayed.
     */
    protected ?string $issueDateBefore = null;

    /**
     * The credited line ids of an issued document, read once per request (see {@see self::creditedIds()}).
     *
     * @var array<string, true>|null
     */
    protected ?array $credited = null;

    /**
     * The Admin check of every request, before any other code of the page runs. A Partner is refused with 403 before
     * the record is read: the Partner scope of a document would answer 404 for a draft.
     */
    public function boot(): void
    {
        abort_unless(InvoiceResource::canAccess(), 403);
    }

    /**
     * A draft opens as it always did. An issued document opens only while it can be edited
     * ({@see Invoice::isEditable()}, which is {@see IssuedDocumentRules::editBlocker()} being null); any other issued document goes to the document page with the reason
     * as a warning (R-10.1-06-1).
     */
    public function mount(int|string $record): void
    {
        // The form is built while the parent mounts, so the mode is known before that.
        $first = $this->resolveRecord($record);
        $this->issuedEditor = $first instanceof Invoice && $first->isIssued();

        parent::mount($record);

        $invoice = $this->invoice();

        if ($invoice->isDraft()) {
            return;
        }

        $blocker = IssuedDocumentRules::editBlocker($invoice);

        if ($blocker === null) {
            return;
        }

        Notification::make()->warning()->title(__('invoices.errors.'.$blocker))->send();

        $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
    }

    /**
     * Whether the page edits an issued document. It is decided once, by the record of the first request ({@see
     * self::mount()}), and kept for the whole life of the page: an editor opened on a draft stays a draft editor even
     * when the draft is issued in another tab meanwhile, so its "Vystavit" and "Uložit koncept" meet the refusal of the
     * Action and send the Admin to the detail instead of losing their buttons.
     */
    private function issuedMode(): bool
    {
        return $this->issuedEditor;
    }

    /**
     * An issued document has only the tab of its linked time under its editor: the payments, the e-mail log and the
     * history live on the detail, and a payment recorded here would move the amounts under an open edit.
     */
    public function getRelationManagers(): array
    {
        if (! $this->issuedMode()) {
            return parent::getRelationManagers();
        }

        // The time tab stays: "Přidat nový čas" of the editor opens its dialog (plan 10.1-09).
        return array_values(array_filter(parent::getRelationManagers(), static fn (mixed $manager): bool => $manager === InvoiceEntriesRelationManager::class));
    }

    public function getTitle(): string
    {
        if ($this->issuedMode()) {
            return (string) __('invoices.edit_issued.title', ['number' => (string) $this->invoice()->number]);
        }

        return match ($this->invoice()->kind) {
            InvoiceKind::Proforma => __('invoices.navigation.draft_proforma'),
            InvoiceKind::CreditNote => __('invoices.navigation.draft_credit_note'),
            InvoiceKind::Invoice => __('invoices.navigation.draft_invoice'),
        };
    }

    public function getHeading(): Htmlable
    {
        if ($this->issuedMode()) {
            $badges = Blade::render(
                '<x-filament::badge color="gray" style="vertical-align: middle;">{{ $kind }}</x-filament::badge> <x-filament::badge color="info" style="vertical-align: middle;">{{ $label }}</x-filament::badge>',
                ['kind' => $this->invoice()->kind->getLabel(), 'label' => __('invoices.edit_issued.badge')],
            );

            return new HtmlString(e($this->getTitle()).' '.$badges);
        }

        $badge = Blade::render('<x-filament::badge color="gray" style="vertical-align: middle;">{{ $label }}</x-filament::badge>', ['label' => __('invoices.draft.badge')]);

        return new HtmlString(e($this->getTitle()).' '.$badge);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                $this->documentSection(),
                $this->partiesSection(),
                $this->itemsSection(),
                $this->summarySection(),
            ]);
    }

    /**
     * "Smazat koncept" (IN-10): the destructive confirmation, DeleteDraft, and back to the list; "Vystavit" (IN-01,
     * UI-SPEC Surface D): the pre-issue check and the confirmation of the issue.
     *
     * An issued document has neither: it is already issued and it is not thrown away here.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        if ($this->issuedMode()) {
            return [];
        }

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
                'supplier_missing', 'bank_missing', 'online_unavailable', 'vat_payer_unsupported', 'pattern_invalid' => $links['settings'] = Action::make('openSettings')
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
            TextEntry::make('issue_payment_method')
                ->label(__('invoices.issue.summary.payment_method'))
                ->state(fn (): string => $this->storedMethod()->getLabel())
                ->visible(fn (): bool => $this->invoice()->kind->carriesPayment()),
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
        // An online document always offers card payment (D-03); the switch it stores is forced on. A cash document stores it off.
        return app(CardPaymentSwitch::class)->effective($this->invoice()->kind, $this->storedMethod()->forcesCardPayment() || $this->storedCardPayment());
    }

    /**
     * The payment method stored on the draft; a legacy draft without one is a transfer document.
     */
    private function storedMethod(): DocumentPaymentMethod
    {
        return $this->invoice()->payment_method ?? DocumentPaymentMethod::Transfer;
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
     * A single "Uložit koncept" in the form footer; autosave is not used. An issued document has "Uložit změny" and a
     * gray link back to the document.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        if ($this->issuedMode()) {
            return [
                $this->getSaveFormAction()->label(__('invoices.edit_issued.save')),
                Action::make('cancel')
                    ->label(__('invoices.edit_issued.cancel'))
                    ->url(InvoiceResource::getUrl('view', ['record' => $this->invoice()]))
                    ->color('gray'),
            ];
        }

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
            // A disabled switch is not dehydrated, so the stored value stays. An online document shows it on: the switch is
            // forced on for it (D-03). A draft shows a switch that cannot be edited now as off; an issued document shows
            // the value it stores (R-14-10-1).
            'card_payment_enabled' => match (true) {
                $this->storedMethod()->forcesCardPayment() && $invoice->kind->carriesPayment() => true,
                $this->issuedMode() => $this->storedCardPayment(),
                default => app(CardPaymentSwitch::class)->available() && $this->storedCardPayment(),
            },
            // An issued document shows the stored account only while it is still configured; a document whose account was
            // removed, or a legacy one without an id, shows none and keeps its printed one (R-14-10-1, D-05).
            ...($invoice->kind->carriesPayment() ? [
                'payment_method' => $this->storedMethod()->value,
                'bank_account_id' => $this->issuedMode() ? $this->filledAccountId() : DocumentPayment::account($invoice)?->id,
            ] : []),
            'items' => $items,
            ...($this->issuedMode() ? [
                'customer' => $this->partyState($invoice->customer_snapshot, self::CUSTOMER_FIELDS),
                'supplier' => $this->partyState($invoice->supplier_snapshot, self::SUPPLIER_FIELDS),
                'reload_bank' => false,
            ] : []),
        ];
    }

    /**
     * The lines of a party snapshot as the form shows them: the listed fields, text or null.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    private function partyState(?array $snapshot, array $fields): array
    {
        $state = [];

        foreach ($fields as $field) {
            $value = $snapshot[$field] ?? null;
            $state[$field] = is_scalar($value) ? (string) $value : null;
        }

        return $state;
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
        if ($this->issuedMode()) {
            return $this->issuedValues($data);
        }

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

        // The method and the account travel when their fields were dehydrated: the account field is hidden for an online
        // document, so an online save sends none and the Action keeps the stored one. The Action re-checks both.
        if ($this->invoice()->kind->carriesPayment()) {
            foreach (['payment_method', 'bank_account_id'] as $key) {
                if (array_key_exists($key, $data)) {
                    $values[$key] = $data[$key];
                }
            }
        }

        return $values;
    }

    /**
     * The values {@see UpdateIssuedDocument} takes: the lines, the two notes and the header the editor offers (the
     * currency, the dates, the language, the customer and supplier lines, the bank refill flag) and, for an invoice or a
     * proforma, the payment method, the card switch and the bank account. The number and the kind are not read from the
     * form at all. The card switch is read from the form (owner answer OA-1, which reverses T-10.1-24) and the Action
     * re-checks it, like the method and the account. The account travels when the Admin changed it, and also whenever
     * the currency changes in the same save and an account is chosen, so a re-picked stored account is kept rather than
     * replaced by the preselection of the new currency (CR-01, D-05, D-06); an unrelated save of a document whose account
     * was removed still sends none (R-14-10-2, T-14-34). A
     * credited line whose fields did not travel (a disabled field is not part of the state) takes them from the stored
     * line; the Action compares them with the stored line again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function issuedValues(array $data): array
    {
        $invoice = $this->invoice();
        $credited = IssuedDocumentRules::creditedLineIds($invoice);
        $stored = InvoiceItem::query()->where('invoice_id', $invoice->id)->get()->keyBy('id');
        $items = [];

        foreach (array_values(is_array($data['items'] ?? null) ? $data['items'] : []) as $item) {
            $line = is_array($item) ? $item : [];
            $id = is_string($line['id'] ?? null) ? $line['id'] : null;
            $row = [
                'id' => $id,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'unit' => $line['unit'] ?? null,
                'unit_price' => $line['unit_price'] ?? null,
                'confirm_zero_price' => (bool) ($line['confirm_zero_price'] ?? false),
            ];

            $own = $id === null ? null : $stored->get($id);

            if ($own instanceof InvoiceItem && isset($credited[$own->id])) {
                $state = $this->itemState($own, $invoice->currency);

                foreach (['quantity', 'unit', 'unit_price'] as $field) {
                    if (! array_key_exists($field, $line)) {
                        $row[$field] = $state[$field];
                    }
                }
            }

            $items[] = $row;
        }

        $values = [
            'items' => $items,
            'note' => $data['note'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'issue_date' => $data['issue_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'language' => $data['language'] ?? null,
        ];

        // The Action re-checks the code against the list of the editor, the payments, the credit notes, the account and
        // the CNB lists, so a forged value changes nothing (T-10.1-31).
        if (is_string($data['currency'] ?? null)) {
            $values['currency'] = $data['currency'];
        }

        foreach (['customer', 'supplier'] as $party) {
            if (is_array($data[$party] ?? null)) {
                $values[$party] = $data[$party];
            }
        }

        // The account itself never travels, only its id and the wish to take it again from the settings (T-10.1-28).
        if (($data['reload_bank'] ?? false) === true) {
            $values['reload_bank'] = true;
        }

        if ($invoice->kind->carriesPayment()) {
            // The method and the switch travel when their fields were dehydrated (a disabled or hidden field is not part
            // of the state); the Action re-checks both (T-14-33).
            if (array_key_exists('payment_method', $data)) {
                $values['payment_method'] = $data['payment_method'];
            }

            if (array_key_exists('card_payment_enabled', $data)) {
                $values['card_payment_enabled'] = (bool) $data['card_payment_enabled'];
            }

            // The account travels when the Admin changed it, and also when the currency changes in the same save and an
            // account is chosen (CR-01, R-14-12-1): a currency change preselects the account of the new currency, so a
            // re-picked stored account equals the filled one and would otherwise be dropped, letting the Action print the
            // preselection instead. A document whose stored account was removed, or a legacy one without an id, still keeps
            // its printed snapshot on an unrelated save (T-14-34, R-14-10-2).
            if (array_key_exists('bank_account_id', $data)) {
                $account = self::text($data['bank_account_id']);
                $currencyChanged = is_string($data['currency'] ?? null) && strtoupper($data['currency']) !== $invoice->currency;

                if ($account !== $this->filledAccountId() || ($currencyChanged && $account !== null)) {
                    $values['bank_account_id'] = $account;
                }
            }
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

        if ($this->issuedMode()) {
            return $this->updateIssued($actor, $record, $data);
        }

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
     * Saves an open issued document through {@see UpdateIssuedDocument}. An error of a line is shown under its row,
     * exactly as for a draft; the error `document` (the document was locked, sent or queued in the meantime) is a
     * notification and nothing is written; a total that is not positive is shown under the table of lines.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateIssued(User $actor, Invoice $record, array $data): Invoice
    {
        $this->issueDateBefore = $record->issue_date?->format('Y-m-d');
        $keys = array_keys(is_array($this->data['items'] ?? null) ? $this->data['items'] : []);

        return $this->withFormErrors(function () use ($actor, $record, $data, $keys): Invoice {
            try {
                return app(UpdateIssuedDocument::class)->handle($actor, $record, $data);
            } catch (ValidationException $exception) {
                $errors = $exception->errors();

                if (isset($errors['document'])) {
                    Notification::make()->danger()->title(implode(' ', $errors['document']))->send();

                    throw new Halt;
                }

                if (isset($errors['total_not_positive'])) {
                    $errors['items'] = [...($errors['items'] ?? []), ...$errors['total_not_positive']];
                    unset($errors['total_not_positive']);
                }

                throw self::underRows(ValidationException::withMessages($errors), $keys);
            }
        });
    }

    /**
     * The stored lines have their ids, positions and previews after a save, so the form is filled again. After the save
     * of an issued document the page says what happened (D-09, D-10): the PDF was created again and nothing was sent,
     * and one warning for each amount the new total fell below (D-04, {@see IssuedDocumentRules::warnings()}); the
     * redirect to the detail follows.
     */
    protected function afterSave(): void
    {
        $this->invoice()->refresh();
        $this->preview = null;
        $this->credited = null;
        $this->fillForm();

        if (! $this->issuedMode()) {
            return;
        }

        Notification::make()->success()->title(__('invoices.edit_issued.saved'))->send();

        foreach (IssuedDocumentRules::warnings($this->invoice()) as $key => $amount) {
            Notification::make()
                ->warning()
                ->title(__('invoices.warnings.'.$key, ['amount' => $amount->format('cs')]))
                ->persistent()
                ->send();
        }

        // A moved issue date keeps the CNB rate and the converted lines of the original date (A6).
        if ($this->issueDateBefore !== $this->invoice()->issue_date?->format('Y-m-d') && IssuedDocumentRules::keepsIssueRates($this->invoice())) {
            Notification::make()
                ->warning()
                ->title(__('invoices.warnings.issue_date_rates_kept'))
                ->persistent()
                ->send();
        }
    }

    /**
     * An issued document sends its own message from {@see self::afterSave()}, in the order success first.
     */
    protected function getSavedNotification(): ?Notification
    {
        return $this->issuedMode() ? null : parent::getSavedNotification();
    }

    /**
     * An issued document goes back to its detail after the save, where the new total, the PDF and the warnings are.
     */
    protected function getRedirectUrl(): ?string
    {
        return $this->issuedMode() ? InvoiceResource::getUrl('view', ['record' => $this->invoice()]) : parent::getRedirectUrl();
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

    /**
     * The helper of the card switch for the method in the form: online forces it on, cash locks it off (Phase 15 D-03),
     * transfer shows the standing note or the reason the switch is disabled.
     */
    private function cardPaymentStateHelper(DocumentPaymentMethod $method): string
    {
        return match (true) {
            $method->forcesCardPayment() => (string) __('invoices.draft.helpers.card_payment_online'),
            ! $method->allowsCardChoice() => (string) __('invoices.draft.helpers.card_payment_cash'),
            default => $this->cardPaymentHelper(),
        };
    }

    /**
     * The helper of the method: the standing note, or why the online option cannot be chosen (D-03, P14-09).
     */
    private function paymentMethodHelper(): string
    {
        return (string) match ($this->onlineUnavailableReason()) {
            CardPaymentSwitch::SETTINGS_OFF => __('invoices.draft.helpers.payment_method_settings_off'),
            CardPaymentSwitch::STRIPE_MISSING => __('invoices.draft.helpers.payment_method_stripe_missing'),
            DocumentPayment::CLIENT_OFF => __('invoices.draft.helpers.payment_method_client_off'),
            default => __('invoices.draft.helpers.payment_method'),
        };
    }

    /**
     * Why the Admin cannot choose online now, or null when they can or the draft is online already (the Action lets an
     * online draft stay online).
     */
    private function onlineUnavailableReason(): ?string
    {
        return $this->storedMethod() === DocumentPaymentMethod::Online ? null : DocumentPayment::onlineUnavailableReason($this->invoice());
    }

    /**
     * The method of a form state: the enum, or the enum of its string value; transfer for anything else (a credit note
     * has no method field, and an empty state shows the default). The editor asks the predicates of the enum about it
     * instead of comparing methods (Phase 15).
     */
    private function methodOf(mixed $state): DocumentPaymentMethod
    {
        if ($state instanceof DocumentPaymentMethod) {
            return $state;
        }

        return (is_string($state) ? DocumentPaymentMethod::tryFrom($state) : null) ?? DocumentPaymentMethod::Transfer;
    }

    /**
     * "Způsob úhrady" of a draft invoice or proforma (D-01, D-02, D-03): transfer is preselected, online is the Admin's
     * choice by hand and is disabled with the reason while card payment is not available. The form is advisory; the
     * Action refuses a crafted online value again (T-14-26).
     */
    private function paymentMethodField(): Select
    {
        return Select::make('payment_method')
            ->label(__('invoices.draft.fields.payment_method'))
            ->options(DocumentPaymentMethod::class)
            ->native(false)
            ->required()
            ->live()
            ->disableOptionWhen(fn (string $value): bool => $value === DocumentPaymentMethod::Online->value && $this->onlineUnavailableReason() !== null)
            ->helperText(fn (): string => $this->paymentMethodHelper())
            ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old): void {
                $this->methodChosen($get, $set, $state, $old);
            })
            ->visible(fn (): bool => $this->invoice()->kind->carriesPayment());
    }

    /**
     * The account the document will print, chosen by hand from every configured account whatever its currency (D-05,
     * D-06). Hidden for a document that prints no bank details (online, cash), so such a save sends none and the Action
     * keeps the stored one (Phase 15 D-05).
     */
    private function bankAccountField(): Select
    {
        return Select::make('bank_account_id')
            ->label(__('invoices.draft.fields.bank_account'))
            ->options(fn (): array => $this->bankAccountOptions())
            ->placeholder(__('invoices.draft.helpers.bank_account_none'))
            ->native(false)
            ->live()
            ->helperText(fn (Get $get): ?string => $this->accountHintShown($get) ? null : (string) __('invoices.draft.helpers.bank_account'))
            ->hint(fn (Get $get): ?Htmlable => $this->accountHintShown($get) ? $this->bankAccountMissingHint() : null)
            ->hintColor('danger')
            ->visible(fn (Get $get): bool => $this->invoice()->kind->carriesPayment() && $this->methodOf($get('payment_method'))->printsBankDetails());
    }

    /**
     * Whether the field shows the danger line of an empty account: a draft without an account cannot be issued. An issued
     * document keeps its printed account whatever the field shows, so it has no such line (the section of the bank account
     * says what the save will print).
     */
    private function accountHintShown(Get $get): bool
    {
        return ! $this->issuedMode() && blank($get('bank_account_id'));
    }

    /**
     * The id of the account the issued editor fills: the stored one while the settings still hold it, else none. A
     * document whose account was removed, or a legacy one without an id, fills none and keeps its printed snapshot until
     * the Admin picks another (R-14-10-1, R-14-10-2).
     */
    private function filledAccountId(): ?string
    {
        $id = $this->invoice()->admin?->bank_account_id;

        return is_string($id) && app(BankAccountSettings::class)->find($id) instanceof BankAccount ? $id : null;
    }

    /**
     * The danger line of an empty account: a transfer document cannot be issued without one, with the link to the
     * settings (UI-SPEC C1).
     */
    private function bankAccountMissingHint(): HtmlString
    {
        $message = e(__('invoices.draft.helpers.bank_account_missing'));
        $link = '<a href="'.e(SettingsPage::getUrl()).'" style="text-decoration: underline;">'.e(__('invoices.draft.settings_link')).'</a>';

        return new HtmlString(str_replace(e(__('invoices.draft.settings_link')), $link, $message));
    }

    /**
     * Every configured account that has an id, keyed by id, in the order of the settings (D-05, D-06).
     *
     * @return array<string, string>
     */
    private function bankAccountOptions(): array
    {
        $options = [];

        foreach (app(BankAccountSettings::class)->accounts as $account) {
            if ($account->id !== null) {
                $options[$account->id] = $account->label.' · '.self::accountNumber($account).' ('.$account->currency.')';
            }
        }

        return $options;
    }

    /**
     * The method changed in the form (OA-1, D-03; Phase 15 D-03, D-04): online turns the switch on, cash turns it off;
     * back to transfer from either sets it to the client flag (when card payment is available) and fills an empty
     * account with the first account of the currency.
     */
    private function methodChosen(Get $get, Set $set, mixed $state, mixed $old): void
    {
        $method = $this->methodOf($state);

        if ($method->forcesCardPayment()) {
            $set('card_payment_enabled', true);

            return;
        }

        if (! $method->allowsCardChoice()) {
            $set('card_payment_enabled', false);

            return;
        }

        if ($this->methodOf($old)->allowsCardChoice()) {
            return;
        }

        $set('card_payment_enabled', $this->client()->online_payment_enabled && app(CardPaymentSwitch::class)->available());

        if (blank($get('bank_account_id'))) {
            $set('bank_account_id', DocumentPayment::preselect($this->currentCurrency()));
        }
    }

    private function documentSection(): Section
    {
        // An issued document keeps its number and kind read-only here (D-08); the currency, the dates and the language are
        // editable (D-06).
        $issued = $this->issuedMode();

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
                TextEntry::make('document_number')
                    ->label(__('invoices.detail.fields.number'))
                    ->state(fn (): string => (string) $this->invoice()->number)
                    ->visible($issued),
                Text::make(__('invoices.edit_issued.number_hint'))
                    ->color('gray')
                    ->visible($issued),
                Select::make('currency')
                    ->label(__('invoices.draft.fields.currency'))
                    ->options(fn (): array => $this->currencyOptions())
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old): void {
                        $this->currencyChosen($get, $set, $state, $old);
                    }),
                $this->paymentMethodField(),
                $this->bankAccountField(),
                Select::make('language')
                    ->label(__('invoices.draft.fields.language'))
                    ->options(InvoiceLanguage::class)
                    ->native(false)
                    ->required(),
                Group::make([
                    DatePicker::make('issue_date')
                        ->label(__('invoices.draft.fields.issue_date'))
                        ->required($issued)
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $this->followIssueDate($get, $set);
                        }),
                    Hidden::make('issue_date_default'),
                ]),
                Group::make([
                    DatePicker::make('due_date')
                        ->label(__('invoices.draft.fields.due_date'))
                        ->required($issued),
                    Hidden::make('due_date_default'),
                ]),
                // IN-08: the taxable-supply date exists on a VAT payer's document only; in the non-payer mode the
                // field is not rendered at all.
                DatePicker::make('taxable_supply_date')
                    ->label(__('invoices.draft.fields.taxable_supply_date'))
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->invoice()->vat_mode === VatMode::Payer),
                Toggle::make('card_payment_enabled')
                    ->label(__('invoices.draft.fields.card_payment'))
                    ->helperText(fn (Get $get): string => $this->cardPaymentStateHelper($this->methodOf($get('payment_method'))))
                    ->disabled(fn (Get $get): bool => ! app(CardPaymentSwitch::class)->available() || ! $this->methodOf($get('payment_method'))->allowsCardChoice())
                    ->visible(fn (): bool => $this->invoice()->kind->carriesPayment()),
                TextEntry::make('variable_symbol')
                    ->label(__('invoices.draft.fields.variable_symbol'))
                    ->state(fn (): string => $this->issuedMode() ? (string) $this->invoice()->variable_symbol : (string) __('invoices.draft.helpers.variable_symbol'))
                    ->color(fn (): ?string => $this->issuedMode() ? null : 'gray'),
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

    /** The customer lines the editor offers, in the order of the form. */
    private const array CUSTOMER_FIELDS = ['name', 'street', 'city', 'postal_code', 'country', 'company_id', 'vat_id'];

    /** The supplier lines the editor offers; the VAT payer flag is not one of them (A9). */
    private const array SUPPLIER_FIELDS = ['name', 'street', 'city', 'postal_code', 'country', 'company_id', 'vat_id', 'email', 'phone', 'website', 'registration_note'];

    /**
     * "Odběratel a dodavatel" of an issued document (D-06, A9): the lines printed on this document, free fields of its
     * own snapshots, each party with an action that fills the fields again from the card or the settings, and the bank
     * account read-only (the account is chosen in the document section) with an action that takes it again from the
     * settings. Nothing is written until "Uložit změny".
     */
    private function partiesSection(): Section
    {
        return Section::make(__('invoices.edit_issued.parties'))
            ->visible($this->issuedMode())
            ->columns(['default' => 1, 'lg' => 2])
            ->schema([
                Fieldset::make(__('invoices.edit_issued.customer'))
                    ->columns(1)
                    ->schema([
                        ...$this->partyFields('customer', self::CUSTOMER_FIELDS),
                        Actions::make([$this->refillCustomerAction()])->key('customerActions'),
                    ]),
                Fieldset::make(__('invoices.edit_issued.supplier'))
                    ->columns(1)
                    ->schema([
                        ...$this->partyFields('supplier', self::SUPPLIER_FIELDS),
                        Actions::make([$this->refillSupplierAction()])->key('supplierActions'),
                    ]),
                Group::make([
                    Hidden::make('reload_bank')->default(false),
                    TextEntry::make('issued_bank_account')
                        ->label(__('invoices.draft.fields.bank_account'))
                        ->state(fn (Get $get): HtmlString => $this->issuedBankState($get))
                        ->html(),
                    Actions::make([$this->refillBankAction()])
                        ->key('bankActions')
                        ->visible(fn (Get $get): bool => $this->methodOf($get('payment_method'))->printsBankDetails()),
                ])->columnSpanFull()->visible(fn (): bool => $this->invoice()->kind->carriesPayment()),
            ]);
    }

    /**
     * "Načíst z karty klienta": the customer fields take the client card as it is now. The form changes, the document
     * does not, until the save.
     */
    private function refillCustomerAction(): Action
    {
        return Action::make('refillCustomer')
            ->label(__('invoices.edit_issued.refill_customer'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                $this->fillParty('customer', app(InvoiceSnapshot::class)->customer($this->client()), self::CUSTOMER_FIELDS);
            });
    }

    /**
     * "Načíst z nastavení dodavatele": the supplier fields take the supplier settings as they are now.
     */
    private function refillSupplierAction(): Action
    {
        return Action::make('refillSupplier')
            ->label(__('invoices.edit_issued.refill_supplier'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                $this->fillParty('supplier', app(InvoiceSnapshot::class)->supplier(), self::SUPPLIER_FIELDS);
            });
    }

    /**
     * "Načíst bankovní účet z nastavení": marks the snapshot of the account to be taken again from the settings at the
     * save: the account chosen in the form, or the first account of the currency when none is chosen. Nothing to take
     * is refused here already, with the message of the Action (R-14-10-3).
     */
    private function refillBankAction(): Action
    {
        return Action::make('refillBank')
            ->label(__('invoices.edit_issued.refill_bank'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                $chosen = self::text($this->data['bank_account_id'] ?? null);

                if (! $this->accountToPrint($chosen, $this->currentCurrency()) instanceof BankAccount) {
                    Notification::make()->danger()->title(__('invoices.errors.bank_account_required'))->send();

                    return;
                }

                $data = $this->data ?? [];
                $data['reload_bank'] = true;
                $this->data = $data;
            });
    }

    /**
     * Replaces the fields of one party in the form by the given lines.
     *
     * @param  array<string, mixed>  $lines
     * @param  list<string>  $fields
     */
    private function fillParty(string $party, array $lines, array $fields): void
    {
        $data = $this->data ?? [];
        $data[$party] = $this->partyState($lines, $fields);
        $this->data = $data;
    }

    /**
     * What the section of the bank account shows (R-14-10-3, D-01): the printed account while nothing changes; the
     * account the save will print when the chosen account differs from the stored one, the method moves from online or
     * cash to transfer, the currency changed or the refill is marked; and the line that an online or a cash document
     * prints no bank details.
     */
    private function issuedBankState(Get $get): HtmlString
    {
        $method = $this->methodOf($get('payment_method'));

        if (! $method->printsBankDetails()) {
            return new HtmlString(e(__($method === DocumentPaymentMethod::Cash ? 'invoices.edit_issued.bank_cash' : 'invoices.edit_issued.bank_online')));
        }

        $chosen = self::text($get('bank_account_id'));
        $currency = $this->currentCurrency();
        $changes = $get('reload_bank') === true
            || ! $this->storedMethod()->printsBankDetails()
            || $currency !== $this->invoice()->currency
            || $chosen !== $this->filledAccountId();

        if (! $changes) {
            $lines = InvoiceColumns::bankLines($this->invoice());

            return new HtmlString($lines === [] ? e(__('invoices.navigation.empty_value')) : implode('<br>', array_map('e', $lines)));
        }

        $account = $this->accountToPrint($chosen, $currency);
        $text = $account instanceof BankAccount
            ? e($account->label.' · '.self::accountNumber($account))
            : e(__('invoices.errors.bank_account_required'));

        return new HtmlString(e(__('invoices.edit_issued.bank_pending')).'<br>'.$text);
    }

    /**
     * The account a save of the form will print: the chosen one when the settings hold it; with none chosen and none
     * filled (a legacy document, or one whose account was removed) the first account of the currency, which is what the
     * Action takes when no account is sent (D-05); else none, which the Action refuses as `bank_account_required`.
     */
    private function accountToPrint(?string $chosen, string $currency): ?BankAccount
    {
        $settings = app(BankAccountSettings::class);

        if ($chosen !== null) {
            return $settings->find($chosen);
        }

        if ($this->filledAccountId() !== null) {
            return null;
        }

        $preselected = DocumentPayment::preselect($currency);

        return $preselected === null ? null : $settings->find($preselected);
    }

    /**
     * The text inputs of one party, under the state path `<party>.<field>`.
     *
     * @param  list<string>  $fields
     * @return list<TextInput>
     */
    private function partyFields(string $party, array $fields): array
    {
        return array_map(
            static fn (string $field): TextInput => TextInput::make("{$party}.{$field}")->label(__('invoices.edit_issued.party_fields.'.$field)),
            $fields,
        );
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
                            Text::make(__('invoices.edit_issued.credited_hint'))
                                ->color('gray')
                                ->visible(fn (Get $get): bool => $this->isCreditedRow($get)),
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
                                ->disabled(fn (Get $get): bool => $this->isCreditedRow($get))
                                ->dehydrated()
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
                            ->disabled(fn (Get $get): bool => $this->isCreditedRow($get))
                            ->dehydrated()
                            ->maxLength(16),
                        Group::make([
                            TextInput::make('unit_price')
                                ->label(__('invoices.draft.columns.unit_price'))
                                ->hiddenLabel()
                                ->disabled(fn (Get $get): bool => $this->isCreditedRow($get))
                                ->dehydrated()
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
                        ->icon(Heroicon::OutlinedTrash)
                        // A line a credit note credits cannot be removed (A4); the Action refuses it as well.
                        ->visible(fn (array $arguments, Component $component): bool => ! $this->isCreditedKey($component, $arguments['item'] ?? null)))
                    ->moveUpAction(fn (Action $action): Action => $action->label(__('invoices.draft.item_actions.move_up')))
                    ->moveDownAction(fn (Action $action): Action => $action->label(__('invoices.draft.item_actions.move_down'))),
                Text::make(__('invoices.errors.items_cap', ['max' => $max]))
                    ->color('gray')
                    ->visible(fn (): bool => count($this->lineStates()) >= $max),
                // An open issued document takes new time and a fixed amount exactly as a draft does (D-07, R-10.1-09-5).
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

                $notification = Notification::make()->success()->title(__('invoices.draft.fixed_amount.toast'));

                // An issued document also made its PDF again; nothing was sent (R-10.1-09-5).
                if ($this->issuedMode()) {
                    $notification->body(__('invoices.edit_issued.saved'));
                }

                $notification->send();
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
        // An issued document says that the CNB figures, the account and the card link follow (D-06, D-11).
        $copy = $this->issuedMode() ? 'invoices.edit_issued.currency_change' : 'invoices.draft.currency_change';

        return Action::make('changeCurrency')
            ->requiresConfirmation()
            ->modalHeading(__($copy.'.heading'))
            ->modalDescription(__($copy.'.body'))
            ->modalSubmitActionLabel(__($copy.'.submit'))
            ->modalCancelActionLabel(__($copy.'.cancel'))
            ->action(function (array $arguments): void {
                $currency = strtoupper(is_string($arguments['currency'] ?? null) ? $arguments['currency'] : '');

                if (! array_key_exists($currency, $this->currencyOptions())) {
                    return;
                }

                $data = $this->data ?? [];
                $data['currency'] = $currency;
                $data['items'] = $this->repricedItems(is_array($data['items'] ?? null) ? $data['items'] : [], $currency);
                // The account follows the confirmed currency like it follows an unconfirmed one (research Pitfall 10), in a
                // draft and in an issued document; the Admin may pick another one afterwards (D-05, D-06).
                $data['bank_account_id'] = DocumentPayment::preselect($currency);
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
     * @return array{subtotal: string|null, vat: string|null, total: string, czk: string|null, czkNote: string|null, czkMissing: string|null}
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
        $note = null;
        $missing = null;

        if ($summary['czk'] !== null) {
            $quote = $summary['czk'];
            $rate = RateFormat::display($quote['rate']).($quote['unit'] === 1 ? '' : ' / '.$quote['unit']);

            if ($this->issuedMode()) {
                // The figures stored at issue, as the detail shows them; the rate is never looked up again.
                $czk = (string) __('invoices.detail.summary.czk_total', ['czk' => $quote['czk']->format('cs')]);
                $note = (string) __('invoices.detail.summary.rate', ['date' => IssueChecks::czechDate($quote['rate_date']), 'rate' => $rate]);
            } else {
                $czk = (string) __('invoices.draft.summary.czk_preview', [
                    'czk' => $quote['czk']->format('cs'),
                    'rate' => $rate,
                    'date' => IssueChecks::czechDate($quote['rate_date']),
                ]);
            }
        } elseif ($summary['czk_missing']) {
            // An issued document is priced with the lists of its issue date, a draft with today's.
            $day = $this->issuedMode() ? ($this->invoice()->issue_date?->format('Y-m-d') ?? DraftEditorPreview::today()) : DraftEditorPreview::today();
            $missing = (string) __('invoices.errors.cnb_missing', ['currency' => $currency, 'date' => IssueChecks::czechDate($day)]);
        }

        // The non-payer mode has no VAT line, and the subtotal equals the total (IN-08).
        return [
            'subtotal' => null,
            'vat' => null,
            'total' => $summary['total']->format('cs'),
            'czk' => $czk,
            'czkNote' => $note,
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
        // The account suggested by the currency (D-05): the first one of it, or none, which the field says out loud.
        $set('bank_account_id', DocumentPayment::preselect($currency));
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
     * The union list of D-07, the same one the create-draft modal and the issued editor offer: every currency CNB has a
     * rate for, the currency of every bank account, the one of the client, the one of the document and CZK. The list is
     * independent of the bank account and of the payment method.
     *
     * @return array<string, string>
     */
    private function currencyOptions(): array
    {
        $codes = IssuedDocumentRules::currencyChoices($this->invoice());

        return array_combine($codes, $codes);
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
     * The ids of the lines of this document that a credit note line names (A4), read once per request; empty for a draft.
     * It is recomputed from the database on every request and never kept in the Livewire state, where a browser could
     * change it.
     *
     * @return array<string, true>
     */
    private function creditedIds(): array
    {
        if (! $this->issuedMode()) {
            return [];
        }

        return $this->credited ??= IssuedDocumentRules::creditedLineIds($this->invoice());
    }

    /**
     * Whether the row the closure runs in is a line that a credit note credits.
     */
    private function isCreditedRow(Get $get): bool
    {
        $id = self::text($get('id'));

        return $id !== null && isset($this->creditedIds()[$id]);
    }

    /**
     * Whether the row with the repeater key is a line that a credit note credits.
     */
    private function isCreditedKey(Component $component, mixed $key): bool
    {
        if (! is_string($key) && ! is_int($key)) {
            return false;
        }

        $row = ($component->getRawState() ?? [])[$key] ?? null;
        $id = is_array($row) ? self::text($row['id'] ?? null) : null;

        return $id !== null && isset($this->creditedIds()[$id]);
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
