<?php

declare(strict_types=1);

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Actions\UpdateTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\TransactionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Corrects a transaction through the domain Action UpdateTransaction, which owns the recompute-versus-manual
 * rule of the CZK figure. The page never writes a row itself.
 */
final class EditTransaction extends EditRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = TransactionResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up: the deny-all scope of finance would
     * answer 404 first.
     */
    public function mount(int|string $record): void
    {
        abort_unless(TransactionResource::canAccess(), 403);

        parent::mount($record);
    }

    public function getTitle(): string
    {
        return __('finance.transactions.actions.edit_heading');
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('finance.transactions.notifications.saved');
    }

    /**
     * "Smazat transakci" behind a confirmation, through DeleteTransaction (D-14). The row goes for real; the
     * change stays in the activity log.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('finance.transactions.actions.delete'))
                ->requiresConfirmation()
                ->modalHeading(__('finance.transactions.actions.delete_heading'))
                ->modalDescription(static fn (Transaction $record): string => self::deleteDescription($record))
                ->modalSubmitActionLabel(__('finance.transactions.actions.delete'))
                ->successNotificationTitle(__('finance.transactions.notifications.deleted'))
                ->successRedirectUrl(TransactionResource::getUrl('index'))
                ->failureNotificationTitle(__('finance.transactions.notifications.delete_failed'))
                ->using(static function (Transaction $record, DeleteAction $action): bool {
                    $actor = auth()->user();
                    assert($actor instanceof User);

                    try {
                        app(DeleteTransaction::class)->handle($actor, $record);
                    } catch (ValidationException $e) {
                        // The income of an invoice payment goes only with its payment (FI-03, R-19-1): the refusal of the
                        // Action is shown as it is, with the way to the invoice where the payment is deleted.
                        self::notifyPaymentDeleteRefused($record, $e);
                        $action->cancel();

                        return false;
                    } catch (RuntimeException $e) {
                        // The database refused the delete: nothing was deleted, the receipts and their objects stay. A refused
                        // object after the commit never gets here (it is queued); the report carries no path.
                        report($e);

                        return false;
                    }

                    return true;
                }),
        ];
    }

    /**
     * The danger notification of a refused deletion of a payment income: the message of DeleteTransaction as the
     * title and the action "Otevřít fakturu" to the invoice detail. The row stays.
     */
    private static function notifyPaymentDeleteRefused(Transaction $transaction, ValidationException $refusal): void
    {
        $message = collect($refusal->errors())->flatten()->first();

        $notification = Notification::make()
            ->danger()
            ->title(is_string($message) ? $message : (string) __('finance.transactions.notifications.delete_failed'));

        if ($transaction->isPaymentIncome() && $transaction->invoice_id !== null) {
            $notification->actions([
                Action::make('open_invoice')
                    ->label(__('invoices.transactions.open_invoice'))
                    ->url(InvoiceResource::getUrl('view', ['record' => $transaction->invoice_id]))
                    ->button(),
            ]);
        }

        $notification->send();
    }

    /**
     * The confirmation names how many receipts go with the transaction, so the permanent deletion of their files
     * is never a surprise (D-14).
     */
    private static function deleteDescription(Transaction $transaction): string
    {
        $receipts = $transaction->documents()->count();

        if ($receipts === 0) {
            return __('finance.transactions.actions.delete_description');
        }

        return trans_choice('finance.transactions.receipts.delete_with_receipts', $receipts, ['count' => $receipts]);
    }

    /**
     * The form is filled with the keys of the form only, the amounts as a person types them; nothing else of
     * the row travels to the browser as part of the Livewire snapshot.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        assert($record instanceof Transaction);

        return TransactionResource::formState($record);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Transaction);

        $actor = auth()->user();
        assert($actor instanceof User);

        return $this->withFormErrors(
            static fn (): Model => app(UpdateTransaction::class)->handle($actor, $record, TransactionResource::actionData($data)),
        );
    }
}
