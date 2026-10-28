<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CancelCashReceipt;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\LockCashReceipt;
use App\Domain\Invoicing\Actions\RegenerateCashReceiptPdf;
use App\Domain\Invoicing\Actions\UnlockCashReceipt;
use App\Domain\Invoicing\Actions\UpdateCashReceipt;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Invoicing\Support\CashReceiptPdfUrl;
use App\Domain\Invoicing\Support\SnapshotInput;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\TransactionResource;
use App\Filament\Support\RecordPaymentAction;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use LogicException;
use RuntimeException;

/**
 * The "Platby" tab of an issued invoice or proforma (IN-05, FI-03; D-17, UI-SPEC Surface F): the payments, newest
 * first, with the way they were paid, the income category they were booked under (a link to the transaction, a dash on
 * a proforma, which books no income) and the note and, per payment, whether the confirmation e-mail to the client is waiting, sent, failed or skipped for lack
 * of an invoice e-mail (Phase 13, A-13-7; a dash for a payment without one: a carried copy, or one recorded before the
 * confirmation existed). The column shows the state only, never the recipient or a failure class. Admin only: a
 * payment is finance data and a Partner reads none.
 *
 * A stored payment is never edited (D-19 as amended on 2026-10-17). The tab has two actions: the gray header action
 * "Zaznamenat platbu" (shared with the primary one of the page) and the one destructive row action "Smazat platbu",
 * which deletes the payment together with the income booked from it through {@see DeletePayment} (rulings R-41-1 and
 * R-41-2). A proforma payment that was carried over to an invoice cannot be deleted before the payment on the invoice:
 * the action is disabled and says which invoice. A credit note owes nothing and a draft has no payment, so the tab is
 * not offered for them.
 *
 * Phase 15 (D-07, D-08, D-13; AS-7, AS-10): next to each payment the tab shows its cash receipt, the number and the
 * state (open, locked, cancelled); a copy carried over from a proforma shows the receipt of the proforma payment by
 * reference. A payment whose receipt was cancelled carries the warning "payment without a valid receipt". The receipt
 * actions of a row are the download of the PDF and the cancel with a reason; each re-reads the payment through the
 * invoice of this tab and calls the domain Action. The deletion of a payment is disabled while its receipt is valid:
 * the Admin cancels the receipt first, then deletes the payment. The refusal of {@see DeletePayment} stays the
 * authority; the disabled action only keeps the screen from offering it.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Payments are finance data: a Partner never sees a payment, not even of the own invoice.')]
final class InvoicePaymentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule {
        canViewForRecord as private canViewForAdmin;
    }

    protected static string $relationship = 'payments';

    /**
     * The numbers of the invoices that carry a payment of this proforma, keyed by the id of the proforma payment; read
     * once per request. Not persisted by Livewire.
     *
     * @var array<string, string>|null
     */
    private ?array $carried = null;

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

    /**
     * A payment saved on the page moves what this tab lists; the listener only makes it render again.
     */
    #[On('invoice-payment-saved')]
    public function refreshPayments(): void
    {
        $this->carried = null;
    }

    public function table(Table $table): Table
    {
        $empty = __('invoices.navigation.empty_value');

        return $table
            ->description(__('invoices.payments.helper'))
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['transaction.category', 'confirmation', 'cashReceipt', 'carriedFrom.cashReceipt']))
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
                TextColumn::make('cash_receipt')
                    ->label(__('cash_receipts.admin.columns.receipt'))
                    ->state(fn (InvoicePayment $record): ?string => $this->shownReceipt($record)?->number)
                    ->description(fn (InvoicePayment $record): ?string => $record->cashReceipt === null && $this->shownReceipt($record) !== null
                        ? (string) __('cash_receipts.admin.carried')
                        : null)
                    ->placeholder($empty),
                TextColumn::make('cash_receipt_state')
                    ->label(__('cash_receipts.admin.columns.state'))
                    ->state(fn (InvoicePayment $record): ?string => ($receipt = $this->shownReceipt($record)) === null
                        ? null
                        : (string) __('cash_receipts.admin.state.'.$this->stateOf($receipt)))
                    ->badge()
                    ->color(function (InvoicePayment $record): string {
                        $receipt = $this->shownReceipt($record);

                        return match (true) {
                            $receipt === null => 'gray',
                            $receipt->isCancelled() => 'danger',
                            $receipt->isLocked() => 'success',
                            default => 'gray',
                        };
                    })
                    ->tooltip(fn (InvoicePayment $record): ?string => ($receipt = $this->shownReceipt($record)) !== null && $receipt->isCancelled()
                        ? CashReceiptAudit::cancelReason($receipt)
                        : null)
                    ->placeholder($empty),
                TextColumn::make('without_valid_receipt')
                    ->label(__('cash_receipts.admin.columns.warning'))
                    ->state(fn (InvoicePayment $record): ?string => $record->cashReceipt?->isCancelled() === true
                        ? (string) __('cash_receipts.admin.without_valid_receipt')
                        : null)
                    ->badge()
                    ->color('warning'),
                TextColumn::make('category')
                    ->label(__('invoices.payments.columns.category'))
                    ->state(static fn (InvoicePayment $record): ?string => $record->transaction?->category?->name)
                    ->url(static fn (InvoicePayment $record): ?string => $record->transaction === null ? null : TransactionResource::getUrl('edit', ['record' => $record->transaction]))
                    ->placeholder($empty),
                TextColumn::make('note')
                    ->label(__('invoices.payments.columns.note'))
                    ->placeholder($empty)
                    ->wrap(),
                TextColumn::make('confirmation')
                    ->label(__('invoices.payments.columns.confirmation'))
                    ->state(static fn (InvoicePayment $record): ?string => $record->confirmation?->state->getLabel())
                    ->badge()
                    ->color(static fn (InvoicePayment $record): string => match ($record->confirmation?->state) {
                        PaymentConfirmationState::Sent => 'success',
                        PaymentConfirmationState::Failed => 'danger',
                        default => 'gray',
                    })
                    ->placeholder($empty),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('paid_on')->orderByDesc('id'))
            ->paginated(false)
            ->headerActions([
                RecordPaymentAction::make(fn (): Invoice => $this->invoice(), function (): void {
                    $this->carried = null;
                })
                    ->visible(fn (): bool => RecordPaymentAction::available($this->invoice())),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->downloadReceiptAction(),
                    $this->editReceiptAction(),
                    $this->lockReceiptAction(),
                    $this->unlockReceiptAction(),
                    $this->regenerateReceiptPdfAction(),
                    $this->cancelReceiptAction(),
                ])
                    ->label(__('cash_receipts.admin.group'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray'),
                $this->deletePaymentAction(),
            ])
            ->emptyStateHeading(__('invoices.payments.empty_heading'))
            ->emptyStateDescription(__('invoices.payments.empty_body'));
    }

    /**
     * The confirmation body of the deletion: a card payment says that the money is not returned (UI-SPEC Surface G),
     * a proforma payment that no income goes with it, anything else is the Phase 10 text.
     */
    private function deleteBodyKey(InvoicePayment $record): string
    {
        if ($record->method === PaymentMethod::Card) {
            return 'invoices.payments.delete.body_card';
        }

        return $this->invoice()->kind === InvoiceKind::Proforma ? 'invoices.payments.delete.body_proforma' : 'invoices.payments.delete.body_invoice';
    }

    /**
     * "Smazat platbu" (D-19 as amended, R-41-1, R-41-2): the destructive confirmation names the amount and the date and
     * says what goes with the payment; the write is {@see DeletePayment}, which deletes the income too in one
     * transaction. The payment is read again through the invoice of this tab, so an id of another invoice reaches
     * nothing. A refusal of the Action is shown as a danger notification with its Czech message.
     */
    private function deletePaymentAction(): Action
    {
        return Action::make('deletePayment')
            ->label(__('invoices.payments.delete.trigger'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->disabled(fn (InvoicePayment $record): bool => $this->carriedTo($record) !== null || $this->validReceipt($record) !== null)
            ->tooltip(function (InvoicePayment $record): ?string {
                if (($number = $this->carriedTo($record)) !== null) {
                    return (string) __('invoices.payments.delete.carried', ['number' => $number]);
                }

                $receipt = $this->validReceipt($record);

                return $receipt === null ? null : (string) __('cash_receipts.admin.delete_blocked', ['number' => $receipt->number]);
            })
            ->requiresConfirmation()
            ->modalHeading(__('invoices.payments.delete.heading'))
            ->modalDescription(fn (InvoicePayment $record): string => __(
                $this->deleteBodyKey($record),
                ['amount' => $record->amount()->format('cs'), 'date' => $record->paid_on->format('j. n. Y')],
            ))
            ->modalSubmitActionLabel(__('invoices.payments.delete.submit'))
            ->modalCancelActionLabel(__('invoices.payments.delete.cancel'))
            ->action(function (InvoicePayment $record): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                $payment = $this->invoice()->payments()->whereKey($record->getKey())->first();

                if (! $payment instanceof InvoicePayment) {
                    Notification::make()->danger()->title(__('invoices.errors.payment_missing'))->send();
                    $this->dispatch('invoice-payment-saved');

                    return;
                }

                try {
                    app(DeletePayment::class)->handle($actor, $payment);
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (ValidationException $exception) {
                    $this->carried = null;

                    Notification::make()
                        ->danger()
                        ->title(implode(' ', array_merge(...array_values($exception->errors()))))
                        ->send();
                    $this->dispatch('invoice-payment-saved');

                    return;
                }

                $this->carried = null;
                $this->dispatch('invoice-payment-saved');

                Notification::make()->success()->title(__('invoices.payments.delete.toast'))->send();
            });
    }

    /**
     * The receipt shown on the row: the own one of a cash payment, or, for a copy carried over from a proforma, the one
     * of the proforma payment (D-07, by reference; no receipt is issued for the copy).
     */
    private function shownReceipt(InvoicePayment $record): ?CashReceipt
    {
        return $record->cashReceipt ?? $record->carriedFrom?->cashReceipt;
    }

    /**
     * The own receipt of the payment while it is not cancelled: the one that keeps the payment from being deleted (D-08).
     */
    private function validReceipt(InvoicePayment $record): ?CashReceipt
    {
        $receipt = $record->cashReceipt;

        return $receipt !== null && ! $receipt->isCancelled() ? $receipt : null;
    }

    private function stateOf(CashReceipt $receipt): string
    {
        return match (true) {
            $receipt->isCancelled() => 'cancelled',
            $receipt->isLocked() => 'locked',
            default => 'open',
        };
    }

    /**
     * "Stáhnout PDF" (D-13): the signed link is built at click time from the receipt re-read through the invoice of this
     * tab and followed by a redirect, so the page never carries it (T-15-40). A carried copy offers the receipt of its
     * proforma payment.
     */
    private function downloadReceiptAction(): Action
    {
        return Action::make('downloadCashReceipt')
            ->label(__('cash_receipts.admin.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (InvoicePayment $record): bool => $this->shownReceipt($record)?->pdf_document_id !== null)
            ->action(function (InvoicePayment $record): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $user = auth()->user();
                $payment = $this->invoice()->payments()->whereKey($record->getKey())->with(['cashReceipt', 'carriedFrom.cashReceipt'])->first();
                $receipt = $payment instanceof InvoicePayment ? $this->shownReceipt($payment) : null;

                abort_unless($user instanceof User && $receipt !== null && $receipt->pdf_document_id !== null, 404);

                $this->redirect(CashReceiptPdfUrl::download($receipt, $user));
            });
    }

    /**
     * "Zrušit doklad" (D-08, AS-7): the storno of the own receipt of the payment with a reason of 10 to 500 characters,
     * through {@see CancelCashReceipt}. Offered while the receipt is not cancelled; it is the step before the payment can
     * be deleted.
     */
    private function cancelReceiptAction(): Action
    {
        return Action::make('cancelCashReceipt')
            ->label(__('cash_receipts.admin.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (InvoicePayment $record): bool => $this->validReceipt($record) !== null)
            ->modalHeading(fn (InvoicePayment $record): string => __('cash_receipts.admin.cancel.heading', ['number' => (string) $record->cashReceipt?->number]))
            ->modalDescription(__('cash_receipts.admin.cancel.description'))
            ->modalSubmitActionLabel(__('cash_receipts.admin.cancel.submit'))
            ->schema([$this->reasonField(__('cash_receipts.admin.cancel.reason'), CancelCashReceipt::REASON_MIN, CancelCashReceipt::REASON_MAX)])
            ->action(function (InvoicePayment $record, array $data): void {
                $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';

                $this->runReceiptAction(
                    $record,
                    static fn (User $actor, CashReceipt $receipt): mixed => app(CancelCashReceipt::class)->handle($actor, $receipt, $reason),
                    'cash_receipts.admin.cancel.done',
                );
            });
    }

    /**
     * "Upravit" (D-10): the receipt date, the purpose and the printed customer and supplier lines of an open receipt,
     * prefilled from the stored values, through {@see UpdateCashReceipt}, which judges every value again and renews the
     * stored PDF. Offered only for an open receipt that is not cancelled.
     */
    private function editReceiptAction(): Action
    {
        return Action::make('editCashReceipt')
            ->label(__('cash_receipts.admin.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (InvoicePayment $record): bool => $record->cashReceipt !== null && ! $record->cashReceipt->isCancelled() && ! $record->cashReceipt->isLocked())
            ->modalHeading(fn (InvoicePayment $record): string => __('cash_receipts.admin.edit.heading', ['number' => (string) $record->cashReceipt?->number]))
            ->modalSubmitActionLabel(__('cash_receipts.admin.edit.submit'))
            ->fillForm(fn (InvoicePayment $record): array => $record->cashReceipt === null ? [] : $this->editDefaults($record->cashReceipt))
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    DatePicker::make('received_on')
                        ->label(__('cash_receipts.admin.edit.received_on'))
                        ->native(false)
                        ->displayFormat('j. n. Y')
                        ->required(),
                    Textarea::make('purpose')
                        ->label(__('cash_receipts.admin.edit.purpose'))
                        ->required()
                        ->maxLength(500)
                        ->rows(3),
                ]),
                ...$this->partyFields('customer'),
                ...$this->partyFields('supplier'),
            ])
            ->action(function (InvoicePayment $record, array $data): void {
                $this->runReceiptAction(
                    $record,
                    static fn (User $actor, CashReceipt $receipt): mixed => app(UpdateCashReceipt::class)->handle($actor, $receipt, $data),
                    'cash_receipts.admin.edit.done',
                );
            });
    }

    /**
     * The stored values the edit dialog starts from.
     *
     * @return array<string, mixed>
     */
    private function editDefaults(CashReceipt $receipt): array
    {
        $party = static function (array $snapshot): array {
            $lines = [];

            foreach (['name', 'street', 'city', 'postal_code', 'country', 'company_id', 'vat_id'] as $key) {
                $value = $snapshot[$key] ?? null;
                $lines[$key] = is_scalar($value) ? (string) $value : null;
            }

            return $lines;
        };

        return [
            'received_on' => $receipt->received_on->format('Y-m-d'),
            'purpose' => $receipt->purpose,
            'customer' => $party($receipt->customer_snapshot),
            'supplier' => $party($receipt->supplier_snapshot),
        ];
    }

    /**
     * The printed lines of one party in the edit dialog, under a heading; the limits are the ones of the snapshots
     * ({@see SnapshotInput}), which judge the values again.
     *
     * @return list<Section>
     */
    private function partyFields(string $party): array
    {
        return [
            Section::make(__('cash_receipts.admin.edit.'.$party))
                ->collapsed()
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make($party.'.name')->label(__('cash_receipts.admin.edit.name'))->required()->maxLength(255),
                        TextInput::make($party.'.street')->label(__('cash_receipts.admin.edit.street'))->maxLength(255),
                        TextInput::make($party.'.city')->label(__('cash_receipts.admin.edit.city'))->maxLength(255),
                        TextInput::make($party.'.postal_code')->label(__('cash_receipts.admin.edit.postal_code'))->maxLength(20),
                        TextInput::make($party.'.country')->label(__('cash_receipts.admin.edit.country'))->required()->maxLength(2),
                        TextInput::make($party.'.company_id')->label(__('cash_receipts.admin.edit.company_id'))->maxLength(32),
                        TextInput::make($party.'.vat_id')->label(__('cash_receipts.admin.edit.vat_id'))->maxLength(32),
                    ]),
                ]),
        ];
    }

    /**
     * "Uzamknout" (Phase 16, D-02, D-03): the Admin closes an open receipt by hand, with a confirmation and no reason,
     * through {@see LockCashReceipt}; the way to close the receipt of a hand-served payment, whose confirmation is not
     * sent. Offered only for a receipt that is neither cancelled nor locked. The page never names the lock state case:
     * the Action is the one place that writes it.
     */
    private function lockReceiptAction(): Action
    {
        return Action::make('lockCashReceipt')
            ->label(__('cash_receipts.admin.actions.lock'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('gray')
            ->visible(fn (InvoicePayment $record): bool => $record->cashReceipt !== null && ! $record->cashReceipt->isCancelled() && ! $record->cashReceipt->isLocked())
            ->requiresConfirmation()
            ->modalHeading(fn (InvoicePayment $record): string => __('cash_receipts.admin.lock.heading', ['number' => (string) $record->cashReceipt?->number]))
            ->modalDescription(__('cash_receipts.admin.lock.description'))
            ->modalSubmitActionLabel(__('cash_receipts.admin.lock.submit'))
            ->action(function (InvoicePayment $record): void {
                $this->runReceiptAction(
                    $record,
                    static fn (User $actor, CashReceipt $receipt): mixed => app(LockCashReceipt::class)->handle($actor, $receipt),
                    'cash_receipts.admin.lock.done',
                );
            });
    }

    /**
     * "Odemknout" (D-10, AS-16): the reason (10 to 500 characters, judged again by the Action) opens a receipt that the
     * send locked, through {@see UnlockCashReceipt}. Offered only for a locked receipt that is not cancelled.
     */
    private function unlockReceiptAction(): Action
    {
        return Action::make('unlockCashReceipt')
            ->label(__('cash_receipts.admin.actions.unlock'))
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('warning')
            ->visible(fn (InvoicePayment $record): bool => $record->cashReceipt !== null && ! $record->cashReceipt->isCancelled() && $record->cashReceipt->isLocked())
            ->modalHeading(fn (InvoicePayment $record): string => __('cash_receipts.admin.unlock.heading', ['number' => (string) $record->cashReceipt?->number]))
            ->modalDescription(__('cash_receipts.admin.unlock.description'))
            ->modalSubmitActionLabel(__('cash_receipts.admin.unlock.submit'))
            ->schema([$this->reasonField(__('cash_receipts.admin.unlock.reason'), UnlockCashReceipt::REASON_MIN, UnlockCashReceipt::REASON_MAX)])
            ->action(function (InvoicePayment $record, array $data): void {
                $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';

                $this->runReceiptAction(
                    $record,
                    static fn (User $actor, CashReceipt $receipt): mixed => app(UnlockCashReceipt::class)->handle($actor, $receipt, $reason),
                    'cash_receipts.admin.unlock.done',
                );
            });
    }

    /**
     * "Vytvořit PDF znovu" (AS-12): creates the PDF of a receipt that has none from the row, through
     * {@see RegenerateCashReceiptPdf}. Offered only while the receipt has no PDF; a failure of the storage or the render
     * shows one fixed message.
     */
    private function regenerateReceiptPdfAction(): Action
    {
        return Action::make('regenerateCashReceiptPdf')
            ->label(__('cash_receipts.admin.actions.regenerate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (InvoicePayment $record): bool => $record->cashReceipt !== null && $record->cashReceipt->pdf_document_id === null)
            ->action(function (InvoicePayment $record): void {
                $this->runReceiptAction(
                    $record,
                    static function (User $actor, CashReceipt $receipt): mixed {
                        try {
                            return app(RegenerateCashReceiptPdf::class)->handle($actor, $receipt);
                        } catch (DomainException|LogicException|RuntimeException) {
                            throw ValidationException::withMessages(['receipt' => __('cash_receipts.admin.regenerate.failed')]);
                        }
                    },
                    'cash_receipts.admin.regenerate.done',
                );
            });
    }

    /**
     * The required reason field of a cancel or an unlock; the length is judged again by the Action.
     */
    private function reasonField(string $label, int $min, int $max): Textarea
    {
        return Textarea::make('reason')
            ->label($label)
            ->required()
            ->rows(4)
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($min, $max): void {
                    $length = mb_strlen(is_string($value) ? trim($value) : '');

                    if ($length < $min || $length > $max) {
                        $fail(__('cash_receipts.errors.reason_length'));
                    }
                },
            ]);
    }

    /**
     * The common frame of a receipt action (T-15-38, T-15-39): the Admin check, the payment re-read through the invoice
     * of this tab and its own receipt, the call of the domain Action, and the outcome. A refusal on a form field is
     * shown under the field, any other refusal as a danger notification (as {@see self::deletePaymentAction()} does);
     * the tab always refreshes.
     *
     * @param  Closure(User, CashReceipt): mixed  $call
     */
    private function runReceiptAction(InvoicePayment $record, Closure $call, string $doneKey): void
    {
        abort_unless(InvoiceResource::canAccess(), 403);

        $actor = auth()->user();
        assert($actor instanceof User);

        $payment = $this->invoice()->payments()->whereKey($record->getKey())->first();
        $receipt = $payment instanceof InvoicePayment ? $payment->cashReceipt : null;

        if (! $receipt instanceof CashReceipt) {
            Notification::make()->danger()->title(__('cash_receipts.admin.missing'))->send();
            $this->dispatch('invoice-payment-saved');

            return;
        }

        try {
            $call($actor, $receipt);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $fields = [];

            foreach ($exception->errors() as $key => $messages) {
                if ($key !== 'receipt') {
                    $fields['mountedActions.0.data.'.$key] = $messages;
                }
            }

            if ($fields !== []) {
                throw ValidationException::withMessages($fields);
            }

            $this->carried = null;

            Notification::make()
                ->danger()
                ->title(implode(' ', array_merge(...array_values($exception->errors()))))
                ->send();
            $this->dispatch('invoice-payment-saved');

            return;
        }

        $this->carried = null;
        $this->dispatch('invoice-payment-saved');

        Notification::make()->success()->title(__($doneKey))->send();
    }

    /**
     * The number of the invoice that carries a copy of this proforma payment, or null when it is not carried over.
     */
    private function carriedTo(InvoicePayment $payment): ?string
    {
        if ($this->carried === null) {
            $this->carried = [];
            $ids = $this->invoice()->payments()->pluck('id')->all();

            foreach (InvoicePayment::query()->whereIn('carried_from_payment_id', $ids)->with('invoice')->get() as $copy) {
                $this->carried[(string) $copy->carried_from_payment_id] = (string) $copy->invoice?->number;
            }
        }

        return $this->carried[$payment->id] ?? null;
    }

    private function invoice(): Invoice
    {
        $owner = $this->getOwnerRecord();
        assert($owner instanceof Invoice);

        return $owner;
    }
}
