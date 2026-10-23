<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\TransactionResource;
use App\Filament\Support\RecordPaymentAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

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
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['transaction.category', 'confirmation']))
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
            ->recordActions([$this->deletePaymentAction()])
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
            ->disabled(fn (InvoicePayment $record): bool => $this->carriedTo($record) !== null)
            ->tooltip(fn (InvoicePayment $record): ?string => ($number = $this->carriedTo($record)) === null
                ? null
                : (string) __('invoices.payments.delete.carried', ['number' => $number]))
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
