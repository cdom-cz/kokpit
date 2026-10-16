<?php

declare(strict_types=1);

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Actions\UpdateTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\TransactionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

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
                ->modalDescription(__('finance.transactions.actions.delete_description'))
                ->modalSubmitActionLabel(__('finance.transactions.actions.delete'))
                ->successNotificationTitle(__('finance.transactions.notifications.deleted'))
                ->successRedirectUrl(TransactionResource::getUrl('index'))
                ->using(static function (Transaction $record): bool {
                    $actor = auth()->user();
                    assert($actor instanceof User);

                    app(DeleteTransaction::class)->handle($actor, $record);

                    return true;
                }),
        ];
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
