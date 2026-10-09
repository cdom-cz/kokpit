<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Decides who is told about a task event and sends the notification (TA-07, D-07).
 *
 * Recipients are computed here, at dispatch time, from the actor's role, the
 * internal flag, the assignee and the requester, and every notification is built
 * from scalars with the URL of its recipient's audience: the Admin opens
 * /admin/tasks/KEY-N, a Partner /admin/my-tasks/KEY-N. The queued notification
 * then never reloads a task or a comment (research Pitfall 4).
 *
 * A deactivated account and a Partner who can no longer read the task (another
 * client, a project that is not client-visible or is archived, an archived
 * client) receive nothing. The lookups run as system because the Partner scopes
 * would otherwise hide the very rows being checked.
 *
 * An internal comment reaches no Partner, unconditionally: the flag is read
 * before the candidates are chosen and again before each one is built, and the
 * notification refuses the combination on its own (D-07, D-15).
 *
 * The recipient matrix is assumption A5 of the research, adapted to the literal
 * D-07: a task created by a Partner goes to the Admin; a Partner comment goes to
 * the Admin and to the assignee when that is an eligible account other than the
 * author; a non-internal Admin comment goes to the Partner requester and
 * assignee; the escalation and change recipients belong to plan 05-16.
 */
final class TaskNotifier
{
    private const int EXCERPT_LIMIT = 300;

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

        $facts = $this->facts($task->getKey());

        foreach ($this->admins() as $admin) {
            if ($admin->getKey() === $actor->getKey()) {
                continue;
            }

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
     * A comment was added: the people on the other side of the task are told,
     * never the author. An internal comment goes to no Partner.
     */
    public function commented(TaskComment $comment, User $actor): void
    {
        $internal = $comment->is_internal;
        $facts = $this->facts($comment->task_id);

        $candidates = [];

        if ($actor->hasRole(RoleName::Partner->value)) {
            foreach ($this->admins() as $admin) {
                $candidates[(string) $admin->getKey()] = $admin;
            }

            $this->addCandidate($candidates, $facts['assignee_id'], $facts);
        } elseif ($actor->hasRole(RoleName::Admin->value) && ! $internal) {
            $this->addCandidate($candidates, $facts['requester_id'], $facts, partnersOnly: true);
            $this->addCandidate($candidates, $facts['assignee_id'], $facts, partnersOnly: true);
        }

        unset($candidates[(string) $actor->getKey()]);

        $excerpt = $internal ? null : self::excerpt($comment->body);

        foreach ($candidates as $recipient) {
            $isPartner = $recipient->hasRole(RoleName::Partner->value);

            // The second lock: an internal comment is never built for a Partner.
            if ($internal && $isPartner) {
                continue;
            }

            $recipient->notify(new TaskCommentedNotification(
                taskReference: $facts['reference'],
                taskTitle: $facts['title'],
                projectKey: $facts['project_key'],
                actorName: $actor->name,
                excerpt: $excerpt,
                url: $this->url($facts['reference'], partner: $isPartner),
                recipientIsPartner: $isPartner,
                internal: $internal,
            ));
        }
    }

    /**
     * A task was escalated: exactly one recipient is told (D-06, D-07). That is
     * the assignee when the account can receive it (active, and an Admin or a
     * Partner of the project's client who can still read the task) and is not the
     * actor; otherwise the Admin, again unless the Admin is the actor.
     *
     * The fallback depends only on whether the assignee CAN receive the event,
     * never on the assignee's preferences: an assignee who switched escalations
     * off on both channels gets nothing and the Admin is not told in that place
     * (D-15 preferences only narrow; assumption A13 of plan 05-16). If the owner
     * wants the Admin told in that case, this is the one branch to change.
     */
    public function escalated(Task $task, User $actor, TaskComment $comment): void
    {
        $internal = $comment->is_internal;
        $facts = $this->facts($task->getKey());

        $candidates = [];
        $this->addCandidate($candidates, $facts['assignee_id'], $facts);
        unset($candidates[(string) $actor->getKey()]);

        // An internal comment must never reach a Partner (an escalation comment never is internal).
        if ($internal) {
            $candidates = array_filter($candidates, static fn (User $user): bool => ! $user->hasRole(RoleName::Partner->value));
        }

        $recipient = array_values($candidates)[0] ?? $this->admins()[0] ?? null;

        if ($recipient === null || $recipient->getKey() === $actor->getKey()) {
            return;
        }

        $isPartner = $recipient->hasRole(RoleName::Partner->value);

        $recipient->notify(new TaskEscalatedNotification(
            taskReference: $facts['reference'],
            taskTitle: $facts['title'],
            projectKey: $facts['project_key'],
            actorName: $actor->name,
            excerpt: $internal ? null : self::excerpt($comment->body),
            url: $this->url($facts['reference'], partner: $isPartner),
            recipientIsPartner: $isPartner,
            internal: $internal,
        ));
    }

    /**
     * The plain text of a sanitised body, cut to the excerpt limit: block ends
     * become spaces, tags and entities are resolved to text, and anything that
     * still looks like a tag is removed, so no markup survives.
     */
    public static function excerpt(string $sanitisedBody): string
    {
        $spaced = (string) preg_replace('/<\/(?:p|li|h[1-6]|blockquote|tr|div)>|<br\s*\/?>/i', ' ', $sanitisedBody);
        $text = strip_tags(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, self::EXCERPT_LIMIT - 1, '…');
    }

    /**
     * Adds the user to the candidates when the account may receive the event:
     * active, and either an Admin or a Partner of the task's client who can still
     * read the task.
     *
     * @param  array<string, User>  $candidates
     * @param  array{reference: string, title: string, project_key: string, client_id: string, partners_may_read: bool, assignee_id: string, requester_id: string}  $facts
     */
    private function addCandidate(array &$candidates, string $userId, array $facts, bool $partnersOnly = false): void
    {
        $user = app(PartnerContext::class)->runAsSystem(
            static fn (): ?User => User::query()->whereKey($userId)->whereNull('deactivated_at')->first(),
        );

        if ($user === null) {
            return;
        }

        if ($user->hasRole(RoleName::Partner->value)) {
            $mayReceive = $facts['partners_may_read'] && $user->client_id === $facts['client_id'];
        } else {
            $mayReceive = ! $partnersOnly && $user->hasRole(RoleName::Admin->value);
        }

        if ($mayReceive) {
            $candidates[(string) $user->getKey()] = $user;
        }
    }

    /**
     * The scalars of the task that the notifications need, read as system.
     *
     * @return array{reference: string, title: string, project_key: string, client_id: string, partners_may_read: bool, assignee_id: string, requester_id: string}
     */
    private function facts(mixed $taskId): array
    {
        return app(PartnerContext::class)->runAsSystem(static function () use ($taskId): array {
            $task = Task::query()->withTrashed()->whereKey($taskId)->firstOrFail();
            $project = Project::query()->withTrashed()->whereKey($task->project_id)->firstOrFail();

            $partnersMayRead = ! $project->trashed()
                && $project->client_visible
                && Client::query()->whereKey($project->client_id)->exists();

            return [
                'reference' => $task->reference,
                'title' => $task->title,
                'project_key' => $project->key,
                'client_id' => $project->client_id,
                'partners_may_read' => $partnersMayRead,
                'assignee_id' => $task->assignee_id,
                'requester_id' => $task->requester_id,
            ];
        });
    }

    /**
     * The active Admin accounts.
     *
     * @return list<User>
     */
    private function admins(): array
    {
        /** @var list<User> $admins */
        $admins = app(PartnerContext::class)->runAsSystem(
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

    private function url(string $reference, bool $partner): string
    {
        return route(
            $partner ? 'filament.admin.resources.my-tasks.view' : 'filament.admin.resources.tasks.view',
            ['record' => $reference],
        );
    }
}
