<?php

declare(strict_types=1);

namespace App\Domain\Documents\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
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
                $project = $this->project($owner->getKey());

                return $this->describe(DocumentOwnerType::Project, $project, true, true);
            }

            if ($owner instanceof Task) {
                $task = Task::query()->withTrashed()->whereKey($owner->getKey())->sharedLock()->firstOrFail();

                return $this->describe(DocumentOwnerType::Task, $this->project($task->project_id), true, true, (string) $task->getKey());
            }

            if ($owner instanceof TaskComment) {
                $comment = TaskComment::query()->whereKey($owner->getKey())->sharedLock()->firstOrFail();
                $task = Task::query()->withTrashed()->whereKey($comment->task_id)->sharedLock()->firstOrFail();

                // An internal comment can never be shared; the others follow the task's default (D-20).
                return $this->describe(DocumentOwnerType::TaskComment, $this->project($task->project_id), ! $comment->is_internal, ! $comment->is_internal, (string) $comment->getKey());
            }

            if ($owner instanceof Client) {
                $client = Client::query()->withTrashed()->whereKey($owner->getKey())->sharedLock()->firstOrFail();
                $clientId = (string) $client->getKey();

                return [
                    'owner_type' => DocumentOwnerType::Client->value,
                    'owner_id' => $clientId,
                    'client_id' => $clientId,
                    'project_id' => null,
                    'shareable' => DocumentOwnerType::Client->isShareable(),
                    'default_visible' => false,
                ];
            }

            if ($owner instanceof Transaction) {
                // A receipt counts for the client and project of its transaction, either of which may be missing (D-11);
                // a transaction is finance data and never shared with a Partner (D-03).
                $transaction = Transaction::query()->whereKey($owner->getKey())->sharedLock()->firstOrFail();

                return [
                    'owner_type' => DocumentOwnerType::Transaction->value,
                    'owner_id' => (string) $transaction->getKey(),
                    'client_id' => $transaction->client_id === null ? null : (string) $transaction->client_id,
                    'project_id' => $transaction->project_id === null ? null : (string) $transaction->project_id,
                    'shareable' => DocumentOwnerType::Transaction->isShareable(),
                    'default_visible' => false,
                ];
            }

            throw new InvalidArgumentException('Unsupported document owner: '.$owner::class);
        });
    }

    /**
     * The project row, archived or not: an archived owner must never yield a wrong client.
     */
    private function project(mixed $id): Project
    {
        return Project::query()->withTrashed()->whereKey($id)->sharedLock()->firstOrFail();
    }

    /**
     * A file that hangs on a project, or on a task or comment of that project: client and project come from
     * the project row, the owner id is the project itself unless a task or comment id is given.
     *
     * @return array{owner_type: ?string, owner_id: ?string, client_id: ?string, project_id: ?string, shareable: bool, default_visible: bool}
     */
    private function describe(DocumentOwnerType $type, Project $project, bool $shareable, bool $defaultVisible, ?string $ownerId = null): array
    {
        return [
            'owner_type' => $type->value,
            'owner_id' => $ownerId ?? (string) $project->getKey(),
            'client_id' => (string) $project->client_id,
            'project_id' => (string) $project->getKey(),
            'shareable' => $shareable && $type->isShareable(),
            'default_visible' => $defaultVisible,
        ];
    }
}
