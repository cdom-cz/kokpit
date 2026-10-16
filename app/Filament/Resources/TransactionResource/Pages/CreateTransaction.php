<?php

declare(strict_types=1);

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Identity\Models\User;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\TransactionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Records an income or expense through the domain Action RecordTransaction; a domain error lands under its
 * field. The page never writes a row itself.
 */
final class CreateTransaction extends CreateRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = TransactionResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('finance.transactions.actions.create');
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('finance.transactions.notifications.created');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $this->withFormErrors(
            static fn (): Model => app(RecordTransaction::class)->handle($actor, TransactionResource::actionData($data)),
        );
    }
}
