<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides who is told about a task event and sends the notification (TA-07, D-07).
 *
 * Recipients are computed here, at dispatch time, from the actor's role, the
 * assignee and the requester, and every notification is built from scalars with
 * the URL of its recipient's audience: the Admin opens /admin/tasks/KEY-N, a
 * Partner /admin/my-tasks/KEY-N. The queued notification then never reloads a
 * task or a comment (research Pitfall 4).
 *
 * A deactivated account and a Partner who can no longer read the task (another
 * client, a project that is not client-visible or is archived, an archived
 * client) receive nothing. The lookups run as system because the Partner scopes
 * would otherwise hide the very rows being checked.
 *
 * The recipient matrix is assumption A5 of the research, adapted to the literal
 * D-07: a task created by a Partner goes to the Admin; the escalation and change
 * recipients belong to plan 05-16.
 */
final class TaskNotifier
{
    /**
     * A Partner created a task: the Admin is told. Anybody else creating a task
     * notifies nobody (the Admin's own tasks, and D-07 does not require a notice
     * to a Partner for a task the Admin created).
     */
    public function taskCreated(Task $task, User $actor): void
    {
        if (! $actor->hasRole(RoleName::Partner->value)) {
            return;
        }

        $facts = $this->facts($task);
        $recipients = $this->admins($actor);

        foreach ($recipients as $admin) {
            $admin->notify(new TaskCreatedNotification(
                taskReference: $facts['reference'],
                taskTitle: $facts['title'],
                projectKey: $facts['project_key'],
                actorName: $actor->name,
                url: $this->url($facts['reference'], partner: false),
            ));
        }
    }

    /**
     * The scalars of the task that the notifications need, read as system.
     *
     * @return array{reference: string, title: string, project_key: string, client_id: string, partners_may_read: bool}
     */
    private function facts(Task $task): array
    {
        return app(PartnerContext::class)->runAsSystem(static function () use ($task): array {
            $project = Project::query()->withTrashed()->whereKey($task->project_id)->firstOrFail();

            $clientId = $project->client_id;
            $partnersMayRead = ! $project->trashed()
                && $project->client_visible
                && Client::query()->whereKey($clientId)->exists();

            return [
                'reference' => $task->reference,
                'title' => $task->title,
                'project_key' => $project->key,
                'client_id' => $clientId,
                'partners_may_read' => $partnersMayRead,
            ];
        });
    }

    /**
     * The active Admin accounts, the actor excluded.
     *
     * @return list<User>
     */
    private function admins(User $actor): array
    {
        /** @var list<User> $admins */
        $admins = app(PartnerContext::class)->runAsSystem(
            static fn (): array => User::query()
                ->whereNull('deactivated_at')
                ->whereKeyNot($actor->getKey())
                ->whereHas('roles', static fn (Builder $roles) => $roles->where('name', RoleName::Admin->value))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->all(),
        );

        return $admins;
    }

    private function url(string $reference, bool $partner): string
    {
        return route(
            $partner ? 'filament.admin.resources.my-tasks.view' : 'filament.admin.resources.tasks.view',
            ['record' => $reference],
        );
    }
}
