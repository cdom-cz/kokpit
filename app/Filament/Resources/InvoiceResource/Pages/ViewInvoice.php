<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Domain\ExchangeRates\RateFormat;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RegeneratePdf;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateInternalNote;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
use App\Domain\Payments\Queries\PaymentLinkStatus;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\InvoiceColumns;
use App\Filament\Support\InvoiceTable;
use App\Filament\Support\PayByCardAction;
use App\Filament\Support\PaymentLinkSection;
use App\Filament\Support\RecordPaymentAction;
use App\Filament\Support\SendInvoiceEmailAction;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn as RepeaterColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use OverflowException;
use Throwable;

/**
 * The issued document's detail (IN-03, IN-04, IN-05, IN-06, IN-07; D-12, D-13, D-16, D-18, D-21, D-23; UI-SPEC Surface
 * E): the banners that say whether the document is paid and what is next, the frozen sections (the document, both
 * parties as the snapshots froze them, the lines, the summary with the CNB snapshot, the related documents and the
 * notes), and the two writes that remain after the issue.
 *
 * Nothing frozen is editable here. The internal note goes through {@see UpdateInternalNote} and the missing PDF
 * through {@see RegeneratePdf}, both of which write only the Admin-only `invoice_admin` row (plan 10-04 R-04-6). The
 * one other write is the sanctioned unlock of a sent document ({@see UnlockInvoice}, IN-12; D-03): the locked banner
 * carries the action that asks for a reason and opens the document until the next successful send locks it again. The
 * page is Admin only and the check runs on every Livewire request: a Partner would otherwise be served an own issued
 * invoice through the Partner scope of the model. The state of the document (paid, late, converted) is derived once
 * per request by {@see ReceivablesReader}, so a banner and a badge cannot disagree.
 *
 * The header actions of the later plans (payment, conversion, storno, e-mail) are added to `getHeaderActions()`; a
 * hidden action is not rendered. While the document is open ({@see Invoice::isEditable()}) the first one is "Upravit",
 * the link to the editor, which serves a draft and an open issued document alike.
 *
 * @phpstan-import-type LinkStatusRow from PaymentLinkStatus
 */
final class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /** @var array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}|null */
    private ?array $derived = null;

    /**
     * The card-payment state of the document, read once per request for the link section, its banners and the header
     * action. Not persisted by Livewire.
     *
     * @var LinkStatusRow|null
     */
    private ?array $linkStatus = null;

    /**
     * What is odd about the total after a correction, read once per request for the two warning banners (D-04):
     * {@see IssuedDocumentRules::warnings()}. Not persisted by Livewire.
     *
     * @var array<string, Money>|null
     */
    private ?array $warnings = null;

    /**
     * The lines of the invoice with what each can still be credited, read once per request for the credit dialog. Not
     * persisted by Livewire.
     *
     * @var array<string, array{description: string, original: InvoiceQuantity, unit: string, left: InvoiceQuantity}>|null
     */
    private ?array $creditRows = null;

    /**
     * Whether this load came from the issue (`send=1`) and the e-mail dialog is still to be opened. Set in `mount()`
     * only; not persisted by Livewire.
     */
    private bool $sendDialogPending = false;

    /**
     * The Admin check of every request, before any other code of the page runs. A Partner is refused with 403 before
     * the record is looked up.
     */
    public function boot(): void
    {
        abort_unless(InvoiceResource::canAccess(), 403);
    }

    /**
     * A draft has no detail: its address goes to the editor.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $invoice = $this->invoice();
        $invoice->loadMissing(['items', 'proforma', 'invoiceFromProforma', 'creditNotes', 'creditedInvoice']);

        if ($invoice->isDraft()) {
            $this->redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));

            return;
        }

        // The issue, the storno, a partial credit note and the conversion send the Admin here with `send=1`; the e-mail
        // dialog opens at once (ruling R-29-1) through `wire:init` of the subheading. Closing it leaves the document
        // unsent and the header action available.
        if (request()->boolean('send') && $invoice->isIssued()) {
            $this->sendDialogPending = true;
        }
    }

    public function getTitle(): string
    {
        $invoice = $this->invoice();

        if ($invoice->number !== null) {
            return $invoice->number;
        }

        return match ($invoice->kind) {
            InvoiceKind::Proforma => __('invoices.navigation.draft_proforma'),
            InvoiceKind::CreditNote => __('invoices.navigation.draft_credit_note'),
            InvoiceKind::Invoice => __('invoices.navigation.draft_invoice'),
        };
    }

    /**
     * The number with the kind and the state badges.
     */
    public function getHeading(): Htmlable
    {
        $invoice = $this->invoice();
        $status = $this->derived()['status'];

        $badges = Blade::render(
            '<x-filament::badge color="gray" style="vertical-align: middle;">{{ $kind }}</x-filament::badge> <x-filament::badge :color="$color" style="vertical-align: middle;">{{ $state }}</x-filament::badge>',
            ['kind' => $invoice->kind->getLabel(), 'color' => $status->getColor(), 'state' => $status->getLabel()],
        );

        // The lock of a sent invoice or proforma (IN-11): the page asks `isLocked()` and never names the case.
        if ($invoice->isIssued() && $invoice->kind->carriesPayment()) {
            $badges .= ' '.Blade::render(
                '<x-filament::badge :color="$color" style="vertical-align: middle;">{{ $lock }}</x-filament::badge>',
                ['color' => $invoice->isLocked() ? 'gray' : 'info', 'lock' => $invoice->lock_state->getLabel()],
            );
        }

        return new HtmlString(e($this->getTitle()).' '.$badges);
    }

    /**
     * The client name as a link, under the heading.
     */
    public function getSubheading(): Htmlable
    {
        $invoice = $this->invoice();
        $client = $invoice->client;

        // The header actions are cached after `mount()`, so the dialog of the issue cannot be mounted there: this empty
        // node asks the page for it as soon as the page is loaded.
        $opener = $this->sendDialogPending ? '<span wire:init="openSendDialog"></span>' : '';

        return new HtmlString('<a href="'.e(ClientResource::getUrl('view', ['record' => $invoice->client_id])).'" style="text-decoration: underline;">'.e((string) $client?->name).'</a>'.$opener);
    }

    /**
     * Opens the e-mail dialog of an issued document (see {@see self::getSubheading()}). Anyone who can open the page is
     * an Admin, and a document that cannot be sent has no such action.
     */
    public function openSendDialog(): void
    {
        abort_unless(InvoiceResource::canAccess(), 403);

        if ($this->invoice()->isIssued()) {
            $this->mountAction('sendEmail');
        }
    }

    /**
     * "Upravit" while the document is open, first (D-05, R-10.1-06-4); "Stáhnout PDF" when the stored PDF exists, "Zaznamenat platbu" on a document that still owes something and
     * "Vystavit fakturu" on a paid proforma without an invoice, and the group "Další akce" with the partial credit note and the
     * storno, and "Odeslat e-mailem" (UI-SPEC E2).
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->editAction(),
            $this->downloadPdfAction(),
            SendInvoiceEmailAction::make(fn (): Invoice => $this->invoice()),
            $this->payByCardAction(),
            $this->recordPaymentAction(),
            $this->convertProformaAction(),
            $this->moreActions(),
        ];
    }

    /**
     * "Upravit" (IN-13; D-05, R-10.1-06-4): the link to the editor of the document, offered only while
     * {@see Invoice::isEditable()} says the document can be corrected: open, not cancelled, not a converted proforma,
     * no e-mail on its way. A locked document is opened with "Odemknout" first.
     */
    private function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('invoices.detail.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->url(fn (): string => InvoiceResource::getUrl('edit', ['record' => $this->invoice()]))
            ->visible(fn (): bool => $this->invoice()->isEditable());
    }

    /**
     * "Zaplatit kartou" (PA-02; D-04, UI-SPEC Surface F): the page the client opens, in a new tab. Gray, so that
     * "Zaznamenat platbu" keeps the one accent; shown only for an invoice or proforma that is not paid, not cancelled
     * and has an active link.
     */
    private function payByCardAction(): Action
    {
        return PayByCardAction::make(
            fn (): Invoice => $this->invoice(),
            fn (): bool => $this->linkStatus()['payable'],
        );
    }

    /**
     * A payment saved or deleted in the Platby tab moves the badge, the remaining amount and the banners of this
     * page; the listener only makes the page render again from the database.
     */
    #[On('invoice-payment-saved')]
    public function refreshAfterPaymentChange(): void
    {
        $this->reloadAdminHalf();
    }

    /**
     * "Zaznamenat platbu", primary while something is still owed (an unpaid, partly paid or overdue invoice or
     * proforma). A paid document records an extra payment from the tab (D-17); a credit note, a cancelled invoice and
     * a paid proforma do not offer it here.
     */
    private function recordPaymentAction(): Action
    {
        return RecordPaymentAction::make(fn (): Invoice => $this->invoice(), fn () => $this->reloadAdminHalf(), 'primary')
            ->visible(fn (): bool => RecordPaymentAction::available($this->invoice())
                && in_array($this->derived()['status'], [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true));
    }

    /**
     * "Vystavit fakturu" on a paid proforma that has no invoice (IN-06; D-15, D-18; UI-SPEC E5): one confirmation, then
     * {@see ConvertProforma} and the success path of the issue (toast, the new invoice, the e-mail dialog flag of
     * ruling R-29-1). A failure leaves the proforma as it was.
     */
    private function convertProformaAction(): Action
    {
        return Action::make('convertProforma')
            ->label(__('invoices.detail.actions.issue_invoice'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->visible(fn (): bool => $this->invoice()->isIssued() && $this->derived()['status'] === InvoiceStatus::AwaitingInvoice)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('invoices.conversion.heading', ['number' => (string) $this->invoice()->number]))
            ->modalDescription(__('invoices.conversion.body'))
            ->modalSubmitActionLabel(__('invoices.conversion.submit'))
            ->modalCancelActionLabel(__('invoices.conversion.cancel'))
            ->action(function (): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    $invoice = app(ConvertProforma::class)->handle($actor, $this->invoice());
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (ValidationException $exception) {
                    // The proforma changed in the meantime (converted elsewhere, a payment deleted): say why and show
                    // the state as it is now.
                    $this->invoice()->refresh();
                    $this->reloadAdminHalf();

                    Notification::make()
                        ->danger()
                        ->title(__('invoices.issue.failed_title'))
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))
                        ->persistent()
                        ->send();

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

                Notification::make()->success()->title(__('invoices.issue.toast.invoice'))->send();

                $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice, 'send' => 1]));
            });
    }

    /**
     * "Další akce" (UI-SPEC E2): the partial credit note and the destructive storno, only on an issued invoice that is
     * not cancelled. A credit note, a cancelled invoice and a proforma (an unconverted proforma is simply left, U-7)
     * show neither; each action carries the condition itself, so a hidden group never leaves a callable action behind.
     */
    private function moreActions(): ActionGroup
    {
        return ActionGroup::make([
            $this->creditItemsAction(),
            $this->stornoAction(),
        ])
            ->label(__('invoices.detail.actions.more'))
            ->icon(Heroicon::OutlinedEllipsisVertical)
            ->color('gray')
            ->button()
            ->visible(fn (): bool => $this->canCredit());
    }

    private function canCredit(): bool
    {
        $invoice = $this->invoice();

        return $invoice->kind === InvoiceKind::Invoice && $invoice->isIssued() && ! $invoice->isCancelled();
    }

    /**
     * "Stornovat" (IN-10; D-14, D-16; UI-SPEC E6): one destructive confirmation, then {@see IssueCreditNote::storno()},
     * which issues the credit note of everything still creditable, cancels the invoice and returns its time. A payment
     * and its income stay as they are; the callout says so. Success opens the credit note with the e-mail dialog
     * requested (ruling R-29-1).
     */
    private function stornoAction(): Action
    {
        return Action::make('storno')
            ->label(__('invoices.detail.actions.storno'))
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('danger')
            ->visible(fn (): bool => $this->canCredit())
            ->modalHeading(fn (): string => __('invoices.credit.storno.heading', ['number' => (string) $this->invoice()->number]))
            ->modalDescription(__('invoices.credit.storno.body'))
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalIconColor('danger')
            ->modalSubmitActionLabel(__('invoices.credit.storno.submit'))
            ->modalCancelActionLabel(__('invoices.credit.storno.cancel'))
            ->schema([
                Callout::make(fn (): string => __('invoices.credit.storno.paid_callout', [
                    'paid' => Money::ofMinor($this->derived()['paid'], $this->invoice()->currency)->format('cs'),
                ]))
                    ->warning()
                    ->visible(fn (): bool => $this->derived()['paid'] > 0),
            ])
            ->action(function (): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                $this->issueCredit(fn (): Invoice => app(IssueCreditNote::class)->storno($actor, $this->invoice()));
            });
    }

    /**
     * "Dobropis na položky" (IN-01; D-14; UI-SPEC E7, N8): the lines of the invoice with a checkbox, the original
     * quantity, an editable quantity to credit (default what is still creditable), the amount of the row and the live
     * negative total. The time stays billed. The domain Action reads the lines of the locked invoice only and judges
     * every quantity again; the page maps its field errors back under the quantity of the row.
     */
    private function creditItemsAction(): Action
    {
        return Action::make('creditItems')
            ->label(__('invoices.detail.actions.credit_items'))
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('gray')
            ->visible(fn (): bool => $this->canCredit())
            ->modalHeading(fn (): string => __('invoices.credit.partial.heading', ['number' => (string) $this->invoice()->number]))
            ->modalWidth('4xl')
            ->modalSubmitActionLabel(__('invoices.credit.partial.submit'))
            ->modalCancelActionLabel(__('invoices.credit.partial.cancel'))
            ->fillForm(function (): array {
                $lines = [];

                foreach ($this->creditRows() as $itemId => $row) {
                    $lines[$itemId] = [
                        'id' => $itemId,
                        'selected' => $row['left']->isPositive(),
                        'quantity' => $row['left']->format('en'),
                    ];
                }

                return ['lines' => $lines];
            })
            ->schema([
                Callout::make(__('invoices.credit.partial.callout'))->info(),
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->table([
                        RepeaterColumn::make(__('invoices.credit.partial.columns.select'))->hiddenHeaderLabel()->width('3rem'),
                        RepeaterColumn::make(__('invoices.credit.partial.columns.item')),
                        RepeaterColumn::make(__('invoices.credit.partial.columns.original_quantity'))->width('9rem')->alignEnd(),
                        RepeaterColumn::make(__('invoices.credit.partial.columns.credit_quantity'))->width('10rem')->alignEnd(),
                        RepeaterColumn::make(__('invoices.credit.partial.columns.total'))->width('10rem')->alignEnd(),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        Checkbox::make('selected')
                            ->hiddenLabel()
                            ->live()
                            ->disabled(fn (Get $get): bool => ! $this->creditRow($get)['left']->isPositive()),
                        Text::make(fn (Get $get): string => $this->creditRow($get)['description']),
                        Text::make(fn (Get $get): string => $this->creditRow($get)['original']->format('cs').' '.$this->creditRow($get)['unit'])
                            ->color('gray')
                            ->extraAttributes(['style' => 'text-align: right; font-variant-numeric: tabular-nums;']),
                        TextInput::make('quantity')
                            ->hiddenLabel()
                            ->live(onBlur: true)
                            ->disabled(fn (Get $get): bool => ! $this->creditRow($get)['left']->isPositive())
                            ->extraInputAttributes(['inputmode' => 'decimal', 'style' => 'text-align: right; font-variant-numeric: tabular-nums;'])
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if ($get('selected') !== true) {
                                        return;
                                    }

                                    $message = $this->quantityProblem($this->creditRow($get)['left'], $value);

                                    if ($message !== null) {
                                        $fail($message);
                                    }
                                },
                            ]),
                        Text::make(fn (Get $get): string => $this->rowAmountText($get))
                            ->extraAttributes(['style' => 'text-align: right; font-variant-numeric: tabular-nums;']),
                    ]),
                Text::make(fn (Get $get): string => __('invoices.credit.partial.total').': '.$this->creditTotal($get('lines'))->format('cs'))
                    ->weight(FontWeight::SemiBold)
                    ->size(TextSize::Large),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                $lines = [];

                foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $row) {
                    if (is_array($row) && is_string($row['id'] ?? null) && ($row['selected'] ?? false) === true) {
                        $lines[$row['id']] = is_string($row['quantity'] ?? null) ? $row['quantity'] : '';
                    }
                }

                // The repeater keys its rows with its own ids; a refused quantity is reported under the key of its row.
                $rowKeys = [];
                $raw = $action->getRawData();

                foreach (is_array($raw['lines'] ?? null) ? $raw['lines'] : [] as $key => $row) {
                    if (is_array($row) && is_string($row['id'] ?? null)) {
                        $rowKeys[$row['id']] = (string) $key;
                    }
                }

                $this->issueCredit(fn (): Invoice => app(IssueCreditNote::class)->partial($actor, $this->invoice(), $lines), $rowKeys);
            });
    }

    /**
     * Runs one of the two credit writes and shows what came of it: the toast and the credit note with the e-mail
     * dialog requested on success, the field errors under the dialog for a refused quantity, a danger notification
     * for a document that cannot be credited (anymore) or for a failure, whose cause goes to the log only.
     *
     * @param  Closure(): Invoice  $write
     * @param  array<string, string>  $rowKeys  item id to the key of its row in the dialog
     */
    private function issueCredit(Closure $write, array $rowKeys = []): void
    {
        try {
            $credit = $write();
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            if (array_key_exists('invoice', $exception->errors())) {
                // Cancelled or credited elsewhere in the meantime: say why and show the state as it is now.
                Notification::make()
                    ->danger()
                    ->title(__('invoices.issue.failed_title'))
                    ->body(implode(' ', array_merge(...array_values($exception->errors()))))
                    ->persistent()
                    ->send();

                $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->invoice()]));

                return;
            }

            throw self::creditErrorsUnderModal($exception, $rowKeys);
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

        Notification::make()->success()->title(__('invoices.credit.storno.toast', ['number' => (string) $credit->number]))->send();

        $this->redirect(InvoiceResource::getUrl('view', ['record' => $credit, 'send' => 1]));
    }

    /**
     * The Action reports `lines.<item id>` for a refused quantity and `lines` for an empty selection or a total above
     * the invoice; the form of the modal lives under the action state path, the quantity inside its row.
     *
     * @param  array<string, string>  $rowKeys  item id to the key of its row in the dialog
     */
    private static function creditErrorsUnderModal(ValidationException $exception, array $rowKeys): ValidationException
    {
        $mapped = [];

        foreach ($exception->errors() as $key => $messages) {
            $itemId = str_starts_with((string) $key, 'lines.') ? substr((string) $key, 6) : null;
            $path = 'mountedActions.0.data.'.($itemId === null ? 'lines' : 'lines.'.($rowKeys[$itemId] ?? $itemId).'.quantity');
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }

    /**
     * The lines of the invoice in order with what each can still be credited, read once per request.
     *
     * @return array<string, array{description: string, original: InvoiceQuantity, unit: string, left: InvoiceQuantity}>
     */
    private function creditRows(): array
    {
        if ($this->creditRows !== null) {
            return $this->creditRows;
        }

        $invoice = $this->invoice();
        $left = app(IssueCreditNote::class)->creditable($invoice);
        $rows = [];

        foreach (InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get() as $item) {
            $rows[$item->id] = [
                'description' => $item->description,
                'original' => $item->quantity(),
                'unit' => $item->unit,
                'left' => $left[$item->id] ?? InvoiceQuantity::fromDecimal('0'),
            ];
        }

        return $this->creditRows = $rows;
    }

    /**
     * The row a form component sits in, found by the id of the invoice line its repeater item carries. An id that is
     * no line of this invoice (a forged state) has nothing creditable.
     *
     * @return array{description: string, original: InvoiceQuantity, unit: string, left: InvoiceQuantity}
     */
    private function creditRow(Get $get): array
    {
        $key = $get('id');

        return (is_string($key) ? $this->creditRows()[$key] ?? null : null) ?? [
            'description' => '',
            'original' => InvoiceQuantity::fromDecimal('0'),
            'unit' => '',
            'left' => InvoiceQuantity::fromDecimal('0'),
        ];
    }

    /**
     * The message under the quantity of a selected row, or null when the quantity is fine: a positive decimal not
     * above what is left (N8).
     */
    private function quantityProblem(InvoiceQuantity $left, mixed $value): ?string
    {
        try {
            $quantity = InvoiceQuantity::fromDecimal(is_string($value) || is_int($value) ? trim((string) $value) : '');
        } catch (InvalidArgumentException|OverflowException) {
            return (string) __('invoices.errors.item_quantity');
        }

        if (! $quantity->isPositive()) {
            return (string) __('invoices.errors.item_quantity');
        }

        return $quantity->compareTo($left) > 0 ? (string) __('invoices.errors.credit_quantity') : null;
    }

    /**
     * The amount of one row as the issue would compute it, or the dash for a row that is not selected.
     */
    private function rowAmountText(Get $get): string
    {
        $key = $get('id');

        if ($get('selected') !== true || ! is_string($key)) {
            return __('invoices.navigation.empty_value');
        }

        $amount = app(IssueCreditNote::class)->preview($this->invoice(), [$key => $get('quantity')])[$key] ?? null;

        return $amount === null ? __('invoices.navigation.empty_value') : $amount->format('cs');
    }

    /**
     * The negative total of the selected rows ("Dobropis celkem"), as the issue would compute the amounts.
     */
    private function creditTotal(mixed $lines): Money
    {
        $typed = [];

        foreach (is_array($lines) ? $lines : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && ($row['selected'] ?? false) === true) {
                $typed[$row['id']] = $row['quantity'] ?? null;
            }
        }

        $total = Money::zero($this->invoice()->currency);

        foreach (app(IssueCreditNote::class)->preview($this->invoice(), $typed) as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->banners(),
            $this->documentSection(),
            $this->partiesSection(),
            $this->itemsSection(),
            $this->summarySection(),
            PaymentLinkSection::section(fn (): Invoice => $this->invoice(), fn () => $this->reloadAdminHalf(), fn (): array => $this->linkStatus()),
            $this->relatedSection(),
            $this->notesSection(),
        ]);
    }

    /**
     * The signed link of the stored PDF, built at click time and never rendered into the page (D-21, ruling R-38-1).
     */
    private function downloadPdfAction(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('invoices.detail.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->invoice()->isIssued() && $this->pdfPresent())
            ->action(function (): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $user = auth()->user();

                abort_unless($user instanceof User && $this->pdfPresent(), 404);

                $this->redirect(InvoicePdfUrl::download($this->invoice(), $user));
            });
    }

    /**
     * The callouts that apply, in the order of UI-SPEC E1; the missing PDF closes the list.
     *
     * @return list<Callout>
     */
    private function banners(): array
    {
        return [
            Callout::make(fn (): string => trans_choice('invoices.detail.banners.overdue', $this->derived()['overdue_days'] ?? 0, [
                'count' => $this->derived()['overdue_days'] ?? 0,
                'amount' => ($this->derived()['remaining'] ?? Money::zero($this->invoice()->currency))->format('cs'),
            ]))
                ->danger()
                ->visible(fn (): bool => $this->derived()['status'] === InvoiceStatus::Overdue),
            Callout::make(__('invoices.detail.banners.open_for_edits'))
                ->key('open_for_edits')
                ->info()
                ->visible(fn (): bool => $this->isEditableDocument() && ! $this->invoice()->isLocked()),
            Callout::make(__('invoices.detail.banners.locked'))
                ->key('locked')
                ->warning()
                ->visible(fn (): bool => $this->isSentDocument() && $this->invoice()->isLocked())
                ->actions([$this->unlockAction()]),
            // A total below what was paid or credited is allowed after a correction and flagged, never blocked (D-04).
            ...array_map(
                fn (string $key): Callout => Callout::make(fn (): string => (string) __('invoices.warnings.'.$key, ['amount' => ($this->warnings()[$key] ?? Money::zero($this->invoice()->currency))->format('cs')]))
                    ->key('warning_'.$key)
                    ->warning()
                    ->visible(fn (): bool => isset($this->warnings()[$key])),
                ['total_below_paid', 'total_below_credited'],
            ),
            ...PaymentLinkSection::banners(fn (): Invoice => $this->invoice(), fn () => $this->reloadAdminHalf(), fn (): array => $this->linkStatus()),
            Callout::make(__('invoices.detail.banners.awaiting_invoice'))
                ->danger()
                ->visible(fn (): bool => $this->derived()['status'] === InvoiceStatus::AwaitingInvoice),
            Callout::make(__('invoices.detail.banners.cancelled_paid'))
                ->warning()
                ->visible(fn (): bool => $this->invoice()->isCancelled() && $this->derived()['paid'] > 0),
            Callout::make(fn (): Htmlable => $this->creditExistsText())
                ->info()
                ->visible(fn (): bool => $this->issuedCreditNotes() !== []),
            Callout::make(__('invoices.detail.pdf_missing'))
                ->key('pdf_missing')
                ->warning()
                ->visible(fn (): bool => $this->invoice()->isIssued() && ! $this->pdfPresent())
                ->actions([$this->regeneratePdfAction()]),
        ];
    }

    /**
     * The warnings of the amounts of the document, by their copy key (see {@see self::$warnings}).
     *
     * @return array<string, Money>
     */
    private function warnings(): array
    {
        return $this->warnings ??= IssuedDocumentRules::warnings($this->invoice());
    }

    /**
     * An issued invoice or proforma: the two kinds a send can lock. A credit note is locked by its own issue and has
     * neither the open nor the locked banner.
     */
    private function isSentDocument(): bool
    {
        $invoice = $this->invoice();

        return $invoice->isIssued() && $invoice->kind->carriesPayment();
    }

    /**
     * An issued invoice or proforma that can still be corrected once it is open: not cancelled and, for a proforma,
     * not converted (assumption A3). The state is derived once per request.
     */
    private function isEditableDocument(): bool
    {
        return $this->isSentDocument()
            && ! in_array($this->derived()['status'], [InvoiceStatus::Cancelled, InvoiceStatus::Converted], true);
    }

    /**
     * "Odemknout" (IN-12; D-03, D-13): a modal that asks for the reason (10 to 500 characters, judged again by the
     * Action), then {@see UnlockInvoice}. Visible only for a locked invoice or proforma that is neither cancelled nor
     * converted. A refused call shows the message under the field or, for a document that changed in the meantime, as a
     * notification; the page then shows the state as it is now.
     */
    private function unlockAction(): Action
    {
        return Action::make('unlock')
            ->label(__('invoices.detail.actions.unlock'))
            ->color('warning')
            ->visible(fn (): bool => $this->isEditableDocument() && $this->invoice()->isLocked())
            ->modalHeading(fn (): string => __('invoices.detail.unlock.heading', ['number' => (string) $this->invoice()->number]))
            ->modalDescription(__('invoices.detail.unlock.description'))
            ->modalSubmitActionLabel(__('invoices.detail.unlock.submit'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('invoices.detail.unlock.reason'))
                    ->required()
                    ->rows(4)
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $length = mb_strlen(is_string($value) ? trim($value) : '');

                            if ($length < UnlockInvoice::REASON_MIN || $length > UnlockInvoice::REASON_MAX) {
                                $fail(__('invoices.errors.unlock_reason_length'));
                            }
                        },
                    ]),
            ])
            ->action(function (array $data): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(UnlockInvoice::class)->handle($actor, $this->invoice(), is_string($data['reason'] ?? null) ? $data['reason'] : '');
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (ValidationException $exception) {
                    if (array_key_exists('reason', $exception->errors())) {
                        throw ValidationException::withMessages(['mountedActions.0.data.reason' => $exception->errors()['reason']]);
                    }

                    // Unlocked or cancelled elsewhere in the meantime: say why and show the state as it is now.
                    $this->invoice()->refresh();
                    $this->reloadAdminHalf();

                    Notification::make()
                        ->danger()
                        ->title(__('invoices.issue.failed_title'))
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))
                        ->persistent()
                        ->send();

                    return;
                }

                $this->invoice()->refresh();
                $this->reloadAdminHalf();

                Notification::make()->success()->title(__('invoices.detail.unlock.done'))->send();
            });
    }

    /**
     * "K dokladu existuje dobropis :number." with the number as a link to each credit note.
     */
    private function creditExistsText(): Htmlable
    {
        $links = array_map(
            static fn (Invoice $note): string => '<a href="'.e(InvoiceResource::getUrl('view', ['record' => $note])).'" style="text-decoration: underline;">'.e((string) $note->number).'</a>',
            $this->issuedCreditNotes(),
        );

        return new HtmlString(str_replace(':number', implode(', ', $links), e(__('invoices.detail.banners.credit_exists', ['number' => ':number']))));
    }

    /**
     * "Vytvořit PDF znovu": the PDF is created again from the snapshots. A failure keeps the state and shows the fixed
     * copy; the cause goes to the log and never to the screen (D-23).
     */
    private function regeneratePdfAction(): Action
    {
        return Action::make('regeneratePdf')
            ->label(__('invoices.detail.pdf_regenerate'))
            ->color('gray')
            ->action(function (): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(RegeneratePdf::class)->handle($actor, $this->invoice());
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->reloadAdminHalf();

                    // A document that got its PDF in the meantime needs no message: the callout is gone.
                    if (! $this->pdfPresent()) {
                        Notification::make()
                            ->danger()
                            ->title(__('invoices.detail.pdf_failed_title'))
                            ->body(__('invoices.detail.pdf_failed_body'))
                            ->persistent()
                            ->send();
                    }

                    return;
                }

                $this->reloadAdminHalf();

                Notification::make()->success()->title(__('invoices.detail.pdf_created'))->send();
            });
    }

    private function documentSection(): Section
    {
        $empty = __('invoices.navigation.empty_value');

        return Section::make(__('invoices.detail.sections.document'))
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                TextEntry::make('number')
                    ->label(__('invoices.detail.fields.number'))
                    ->weight(FontWeight::SemiBold)
                    ->placeholder($empty),
                TextEntry::make('kind')
                    ->label(__('invoices.detail.fields.kind'))
                    ->badge()
                    ->color('gray'),
                TextEntry::make('issue_date')
                    ->label(__('invoices.detail.fields.issue_date'))
                    ->date('j. n. Y')
                    ->placeholder($empty),
                TextEntry::make('taxable_supply_date')
                    ->label(__('invoices.detail.fields.taxable_supply_date'))
                    ->date('j. n. Y')
                    ->visible(static fn (Invoice $record): bool => $record->taxable_supply_date !== null),
                TextEntry::make('due_date')
                    ->label(__('invoices.detail.fields.due_date'))
                    ->date('j. n. Y')
                    ->placeholder($empty),
                TextEntry::make('variable_symbol')
                    ->label(__('invoices.detail.fields.variable_symbol'))
                    ->placeholder($empty),
                TextEntry::make('language')
                    ->label(__('invoices.detail.fields.language')),
                TextEntry::make('currency')
                    ->label(__('invoices.detail.fields.currency')),
                TextEntry::make('bank_account')
                    ->label(__('invoices.detail.fields.bank_account'))
                    ->state(static fn (Invoice $record): array => InvoiceColumns::bankLines($record))
                    ->listWithLineBreaks()
                    ->placeholder($empty)
                    // D-01: only a transfer document names an account; an online one prints none and a credit note has no method.
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote && $record->payment_method === DocumentPaymentMethod::Transfer),
                TextEntry::make('source_proforma')
                    ->label(__('invoices.detail.fields.source_proforma'))
                    ->state(static fn (Invoice $record): ?string => $record->proforma?->number)
                    ->url(static fn (Invoice $record): ?string => $record->proforma === null ? null : InvoiceResource::getUrl('view', ['record' => $record->proforma]))
                    ->visible(static fn (Invoice $record): bool => $record->proforma_id !== null),
                TextEntry::make('payment_method')
                    ->label(__('invoices.detail.fields.payment_method'))
                    ->state(static fn (Invoice $record): ?string => $record->payment_method?->getLabel())
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                // The stored card switch (PA-03; UI-SPEC Surface D; editable while an issued transfer document is open, frozen by the lock, Phase 14 OA-1): read only here, no credit note has one.
                TextEntry::make('card_payment')
                    ->label(__('payment_links.frozen.label'))
                    ->state(fn (): string => __($this->linkStatus()['switch'] ? 'payment_links.frozen.yes' : 'payment_links.frozen.no'))
                    ->visible(static fn (Invoice $record): bool => $record->kind->carriesPayment()),
            ]);
    }

    /**
     * Both parties as the snapshots froze them. Neither the client nor the supplier settings are read here, so a later
     * change never shows on an issued document.
     */
    private function partiesSection(): Section
    {
        return Section::make(__('invoices.detail.sections.parties'))
            ->schema([
                Icon::make(Heroicon::OutlinedLockClosed)
                    ->color('gray')
                    ->tooltip(__('invoices.detail.parties.frozen')),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextEntry::make('supplier')
                        ->label(__('invoices.detail.parties.supplier'))
                        ->state(static fn (Invoice $record): array => InvoiceColumns::partyLines($record->supplier_snapshot))
                        ->listWithLineBreaks(),
                    TextEntry::make('customer')
                        ->label(__('invoices.detail.parties.customer'))
                        ->state(static fn (Invoice $record): array => InvoiceColumns::partyLines($record->customer_snapshot))
                        ->listWithLineBreaks(),
                ]),
            ]);
    }

    /**
     * The frozen lines, read only. The amounts of a credit note are stored negative and print negative.
     */
    private function itemsSection(): Section
    {
        return Section::make(__('invoices.detail.sections.items'))
            ->columnSpanFull()
            ->schema([
                RepeatableEntry::make('lines')
                    ->hiddenLabel()
                    ->placeholder(__('invoices.detail.no_items'))
                    ->state(fn (Invoice $record): array => $this->lines($record))
                    ->table([
                        TableColumn::make(__('invoices.draft.columns.index')),
                        TableColumn::make(__('invoices.detail.columns.description')),
                        TableColumn::make(__('invoices.detail.columns.quantity'))->alignEnd(),
                        TableColumn::make(__('invoices.detail.columns.unit_price'))->alignEnd(),
                        TableColumn::make(__('invoices.detail.columns.total'))->alignEnd(),
                    ])
                    ->schema([
                        TextEntry::make('index'),
                        TextEntry::make('description'),
                        TextEntry::make('quantity'),
                        TextEntry::make('unit_price'),
                        TextEntry::make('line_total'),
                    ]),
            ]);
    }

    /**
     * What is owed and what was paid, and for a foreign currency the frozen CNB rate with the total in CZK (IN-07).
     */
    private function summarySection(): Section
    {
        return Section::make(__('invoices.detail.sections.summary'))
            ->columnSpanFull()
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                TextEntry::make('summary_total')
                    ->label(__('invoices.detail.summary.total'))
                    ->state(static fn (Invoice $record): string => $record->total()->format('cs'))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::SemiBold),
                TextEntry::make('summary_paid')
                    ->label(__('invoices.detail.summary.paid'))
                    ->state(fn (Invoice $record): string => Money::ofMinor($this->derived()['paid'], $record->currency)->format('cs'))
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                TextEntry::make('summary_remaining')
                    ->label(__('invoices.detail.summary.remaining'))
                    ->state(fn (Invoice $record): string => ($this->derived()['remaining'] ?? Money::zero($record->currency))->format('cs'))
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                TextEntry::make('summary_rate')
                    ->hiddenLabel()
                    ->state(fn (Invoice $record): ?string => $this->rateText($record))
                    ->color('gray')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $this->rateText($record) !== null),
                TextEntry::make('summary_czk')
                    ->hiddenLabel()
                    ->state(fn (Invoice $record): ?string => $this->czkText($record))
                    ->color('gray')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $this->czkText($record) !== null),
            ]);
    }

    /**
     * The documents that really relate to this one: the source proforma, the invoice made from this proforma, the
     * credit notes of this invoice and the invoice a credit note refers to. No row, no section.
     */
    private function relatedSection(): Section
    {
        return Section::make(__('invoices.detail.sections.related'))
            ->visible(fn (Invoice $record): bool => $this->relatedRows($record) !== [])
            ->schema([
                RepeatableEntry::make('related')
                    ->hiddenLabel()
                    ->state(fn (Invoice $record): array => $this->relatedRows($record))
                    ->table([
                        TableColumn::make(__('invoices.detail.related_columns.kind')),
                        TableColumn::make(__('invoices.detail.related_columns.number')),
                        TableColumn::make(__('invoices.detail.related_columns.status')),
                    ])
                    ->schema([
                        TextEntry::make('kind')->badge()->color('gray'),
                        TextEntry::make('link')->html(),
                        TextEntry::make('status'),
                    ]),
            ]);
    }

    /**
     * The printed note (frozen, read only) and the internal note, the one writable field of an issued document.
     */
    private function notesSection(): Section
    {
        $empty = __('invoices.navigation.empty_value');

        return Section::make(__('invoices.detail.sections.notes'))
            ->schema([
                TextEntry::make('note')
                    ->label(__('invoices.detail.note.printed'))
                    ->placeholder($empty)
                    ->prose(),
                TextEntry::make('internal_note')
                    ->label(__('invoices.detail.note.heading'))
                    ->state(static fn (Invoice $record): ?string => $record->admin?->internal_note)
                    ->placeholder($empty)
                    ->prose()
                    ->hintAction($this->editNoteAction()),
            ]);
    }

    /**
     * "Upravit poznámku": a small modal with a textarea. The write goes through {@see UpdateInternalNote}, which locks
     * the invoice, writes only the Admin-only row and checks the limit again.
     */
    private function editNoteAction(): Action
    {
        return Action::make('editNote')
            ->label(__('invoices.detail.actions.edit_note'))
            ->link()
            ->modalHeading(__('invoices.detail.note.heading'))
            ->modalSubmitActionLabel(__('invoices.detail.note.submit'))
            ->modalCancelActionLabel(__('invoices.detail.note.cancel'))
            ->fillForm(fn (): array => ['internal_note' => $this->invoice()->admin?->internal_note])
            ->schema([
                Textarea::make('internal_note')
                    ->label(__('invoices.detail.note.heading'))
                    ->rows(5)
                    ->maxLength(2000),
            ])
            ->action(function (array $data): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(UpdateInternalNote::class)->handle($actor, $this->invoice(), is_string($data['internal_note'] ?? null) ? $data['internal_note'] : null);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['mountedActions.0.data.internal_note' => $exception->errors()['internal_note'] ?? [__('invoices.errors.internal_note_length')]]);
                }

                $this->reloadAdminHalf();

                Notification::make()->success()->title(__('invoices.detail.note.toast'))->send();
            });
    }

    /**
     * @return list<array{index: string, description: string, quantity: string, unit_price: string, line_total: string}>
     */
    private function lines(Invoice $invoice): array
    {
        $lines = [];

        foreach ($invoice->items as $position => $item) {
            $lines[] = [
                'index' => (string) ($position + 1),
                'description' => $item->description,
                'quantity' => InvoiceQuantity::fromDecimal($item->quantity)->format('cs').' '.$item->unit,
                'unit_price' => Money::ofMinor($item->unit_price_minor, $invoice->currency)->format('cs'),
                'line_total' => Money::ofMinor($item->line_total_minor, $invoice->currency)->format('cs'),
            ];
        }

        return $lines;
    }

    /**
     * "Kurz ČNB ke dni vystavení :date: :rate", or null for a document in CZK or one without the snapshot.
     */
    private function rateText(Invoice $invoice): ?string
    {
        $admin = $invoice->admin;

        if (! $admin instanceof InvoiceAdmin || $invoice->currency === 'CZK' || $admin->exchange_rate === null || $admin->exchange_rate_date === null) {
            return null;
        }

        $unit = $admin->exchange_unit_amount ?? 1;

        return (string) __('invoices.detail.summary.rate', [
            'date' => $admin->exchange_rate_date->format('j. n. Y'),
            'rate' => RateFormat::display((string) $admin->exchange_rate).($unit === 1 ? '' : ' / '.$unit),
        ]);
    }

    private function czkText(Invoice $invoice): ?string
    {
        $czk = $this->rateText($invoice) === null ? null : $invoice->admin?->czkTotal();

        return $czk === null ? null : (string) __('invoices.detail.summary.czk_total', ['czk' => $czk->format('cs')]);
    }

    /**
     * @return list<array{kind: string, link: string, status: string}>
     */
    private function relatedRows(Invoice $invoice): array
    {
        $related = [];

        if ($invoice->proforma_id !== null && $invoice->proforma instanceof Invoice) {
            $related[] = $invoice->proforma;
        }

        if ($invoice->kind === InvoiceKind::Proforma && $invoice->invoiceFromProforma instanceof Invoice && $invoice->invoiceFromProforma->isIssued()) {
            $related[] = $invoice->invoiceFromProforma;
        }

        foreach ($this->issuedCreditNotes() as $note) {
            $related[] = $note;
        }

        if ($invoice->kind === InvoiceKind::CreditNote && $invoice->creditedInvoice instanceof Invoice) {
            $related[] = $invoice->creditedInvoice;
        }

        if ($related === []) {
            return [];
        }

        $states = app(ReceivablesReader::class)->statuses($related, ReceivablesReader::today());
        $rows = [];

        foreach ($related as $document) {
            $rows[] = [
                'kind' => $document->kind->getLabel(),
                'link' => '<a href="'.e(InvoiceResource::getUrl('view', ['record' => $document])).'" style="text-decoration: underline;">'.e($document->number ?? __('invoices.navigation.empty_value')).'</a>',
                'status' => $states[$document->id]['status']->getLabel(),
            ];
        }

        return $rows;
    }

    /**
     * The issued credit notes of this invoice, oldest first.
     *
     * @return list<Invoice>
     */
    private function issuedCreditNotes(): array
    {
        $invoice = $this->invoice();

        if ($invoice->kind !== InvoiceKind::Invoice) {
            return [];
        }

        return array_values($invoice->creditNotes->filter(static fn (Invoice $note): bool => $note->isIssued())->all());
    }

    /**
     * The state of the document, derived once per request.
     *
     * @return array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}
     */
    private function derived(): array
    {
        return $this->derived ??= InvoiceTable::statusOf($this->invoice());
    }

    /**
     * The card-payment state of the document, read once per request (an Admin-only read).
     *
     * @return LinkStatusRow
     */
    private function linkStatus(): array
    {
        return $this->linkStatus ??= PaymentLinkStatus::for($this->invoice());
    }

    private function pdfPresent(): bool
    {
        return $this->invoice()->admin?->pdfDocument !== null;
    }

    /**
     * Reads the Admin-only half again after a write, and forgets what was derived from it.
     */
    private function reloadAdminHalf(): void
    {
        $invoice = $this->invoice();
        $invoice->unsetRelation('admin');
        $invoice->load('admin.pdfDocument');

        // Time added to or released from an open document moves its lines and totals as well (plan 10.1-09).
        $totals = Invoice::query()->whereKey($invoice->getKey())->first(['subtotal_minor', 'vat_minor', 'total_minor']);

        if ($totals instanceof Invoice) {
            $invoice->forceFill($totals->only(['subtotal_minor', 'vat_minor', 'total_minor']))->syncOriginal();
            $invoice->unsetRelation('items');
            $invoice->load('items');
        }

        $this->derived = null;
        $this->linkStatus = null;
        $this->warnings = null;
    }

    private function invoice(): Invoice
    {
        $record = $this->getRecord();
        assert($record instanceof Invoice);

        return $record;
    }
}
