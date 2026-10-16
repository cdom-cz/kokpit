<?php

declare(strict_types=1);

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Derives, once at upload, what a file hangs on: the owner pair, the client and the project of the
 * denormalized columns, whether the file can ever be shared with a Partner, and its default visibility
 * (D-01, D-03, D-11, D-20).
 *
 * The owner is re-read from the database as system, archived rows included, with a shared row lock, so a
 * stale or forged instance can never yield a wrong client and the owner cannot be archived mid-upload
 * unnoticed. An owner class that has no branch here is refused.
 */
final class DocumentOwnerResolver
{
    public function __construct(private readonly PartnerContext $context) {}

    /**
     * @return array{owner_type: ?string, owner_id: ?string, client_id: ?string, project_id: ?string, shareable: bool, default_visible: bool}
     *
     * @throws InvalidArgumentException for an owner of an unknown class
     */
    public function resolve(?Model $owner): array
    {
        if ($owner === null) {
            return [
                'owner_type' => null,
                'owner_id' => null,
                'client_id' => null,
                'project_id' => null,
                'shareable' => false,
                'default_visible' => false,
            ];
        }

        return $this->context->runAsSystem(function () use ($owner): array {
            if ($owner instanceof Project) {
                $project = Project::query()->withTrashed()->whereKey($owner->getKey())->sharedLock()->firstOrFail();

                return $this->describe(DocumentOwnerType::Project, (string) $project->getKey(), (string) $project->client_id, (string) $project->getKey(), true);
            }

            throw new InvalidArgumentException('Unsupported document owner: '.$owner::class);
        });
    }

    /**
     * @return array{owner_type: ?string, owner_id: ?string, client_id: ?string, project_id: ?string, shareable: bool, default_visible: bool}
     */
    private function describe(DocumentOwnerType $type, string $ownerId, ?string $clientId, ?string $projectId, bool $defaultVisible): array
    {
        return [
            'owner_type' => $type->value,
            'owner_id' => $ownerId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'shareable' => $type->isShareable(),
            'default_visible' => $defaultVisible,
        ];
    }
}
