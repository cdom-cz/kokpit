<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Domain\Clients\Actions\UpdateClient;
use App\Domain\Clients\Models\Client;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\ClientResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edits a client through the domain Action; the rate is shown as text with a decimal comma.
 */
final class EditClient extends EditRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = ClientResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up. The deny-all
     * scope of the client would otherwise answer 404 first, and the Admin-only
     * routes of the app answer 403 for a Partner whatever record is requested.
     */
    public function mount(int|string $record): void
    {
        abort_unless(ClientResource::canAccess(), 403);

        parent::mount($record);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ClientResource::archiveAction(DeleteAction::make()),
            ClientResource::restoreAction(RestoreAction::make()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $client = $this->getRecord();

        return $client instanceof Client ? ClientResource::fillRateState($client, $data) : $data;
    }

    /**
     * Saving clears the "loaded from ARES" marks.
     */
    protected function afterSave(): void
    {
        $this->data['ares_changed'] = [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Client);

        return $this->withFormErrors(
            static fn (): Client => app(UpdateClient::class)->handle($record, ClientResource::actionData($data)),
        );
    }
}
