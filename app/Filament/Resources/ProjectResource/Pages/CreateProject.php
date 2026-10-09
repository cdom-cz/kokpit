<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject as CreateProjectAction;
use App\Domain\Projects\Models\Project;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\ProjectResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Creates a project and its billing row through the domain Action. The tags input
 * saves its own relationship after the record exists, so the Action gets no tags.
 */
final class CreateProject extends CreateRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = ProjectResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormErrors(function () use ($data): Project {
            $clientId = $data['client_id'] ?? null;
            $client = is_string($clientId) ? Client::query()->withTrashed()->find($clientId) : null;

            if (! $client instanceof Client) {
                throw ValidationException::withMessages(['client_id' => __('kokpit.projects.errors.client_required')]);
            }

            return app(CreateProjectAction::class)->handle($client, ProjectResource::actionData($data));
        });
    }
}
