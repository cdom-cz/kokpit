<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Domain\Clients\Actions\CreateClient as CreateClientAction;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\ClientResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates a client through the domain Action; domain errors land next to their fields.
 */
final class CreateClient extends CreateRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = ClientResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormErrors(
            static fn (): Model => app(CreateClientAction::class)->handle(ClientResource::actionData($data)),
        );
    }
}
