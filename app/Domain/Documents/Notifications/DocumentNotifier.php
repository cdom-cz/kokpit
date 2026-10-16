<?php

declare(strict_types=1);

namespace App\Domain\Documents\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tells the Admin about document events (D-05): today only that a Partner handed over files on a task.
 *
 * Recipients are the active Admin accounts and nobody else; a Partner is never told. The notification is sent
 * with notifyNow() so it commits or rolls back together with the upload that triggered it.
 */
final class DocumentNotifier
{
    public function __construct(private readonly PartnerContext $context) {}

    /**
     * One bell per upload submission to every active Admin, naming the Partner, the task reference and the
     * number of files (never a file name).
     */
    public function partnerUploaded(User $partner, Task $task, int $count): void
    {
        if ($count < 1) {
            return;
        }

        foreach ($this->admins() as $admin) {
            $admin->notifyNow(new PartnerUploadedFilesNotification(
                $partner->name,
                $task->reference,
                $count,
                route('filament.admin.resources.tasks.view', ['record' => $task->reference]),
            ));
        }
    }

    /**
     * The active Admin accounts, read as system. The same query as TaskNotifier::admins(), which is private to
     * the tasks module.
     *
     * @return list<User>
     */
    private function admins(): array
    {
        /** @var list<User> $admins */
        $admins = $this->context->runAsSystem(
            static fn (): array => User::query()
                ->whereNull('deactivated_at')
                ->whereHas('roles', static fn (Builder $roles) => $roles->where('name', RoleName::Admin->value))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->all(),
        );

        return $admins;
    }
}
