<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\EscalateTask;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Domain\Tasks\Notifications\TaskChangedNotification;
use App\Domain\Tasks\Notifications\TaskCommentedNotification;
use App\Domain\Tasks\Notifications\TaskCreatedNotification;
use App\Domain\Tasks\Notifications\TaskEscalatedNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Tests\Support\Canary;

/*
 * Notifications of Partner tasks and comments (TA-07, D-07, D-15). Every name and
 * text is fictional; canaries are assembled at runtime. The queue is `sync` in
 * the test run, which would hide worker-context bugs, so recipients and channels
 * are asserted on the fake and the bodies are rendered with no signed-in user,
 * as a worker would.
 */

/**
 * A client-visible project of the client, written through the domain Action.
 */
function taskNotifProject(string $clientId): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
}

/**
 * A task created by the user in the project, signed in as that user the way a request is.
 */
function taskNotifCreate(User $actor, Project $project, ?string $title = null): Task
{
    test()->actingAs($actor);

    return app(CreateTask::class)->handle($actor, $project, ['title' => $title ?? Canary::canary('task')]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->projectA = taskNotifProject($this->clientA);
    $this->partnerA = Canary::partnerFor($this->clientA);
});

it('sends the Admin one queued notification on mail and in the bell when a Partner creates a task', function (): void {
    Notification::fake();

    $task = taskNotifCreate($this->partnerA, $this->projectA);

    Notification::assertCount(1);
    Notification::assertSentTo(
        $this->admin,
        TaskCreatedNotification::class,
        static fn (TaskCreatedNotification $notification, array $channels): bool => $channels === ['mail', 'database']
            && $notification->taskReference === $task->reference
            && str_ends_with($notification->url, '/admin/tasks/'.$task->reference),
    );
    Notification::assertNotSentTo($this->partnerA, TaskCreatedNotification::class);
});

it('sends nothing when the Admin creates a task', function (): void {
    Notification::fake();

    taskNotifCreate($this->admin, $this->projectA);

    Notification::assertNothingSent();
});

it('stores one Filament-format bell entry for the Admin that opens the admin task page', function (): void {
    $task = taskNotifCreate($this->partnerA, $this->projectA);

    $rows = $this->admin->notifications()->get();

    expect($rows)->toHaveCount(1);

    /** @var array<string, mixed> $data */
    $data = $rows[0]->data;

    expect($data['format'])->toBe('filament')
        ->and($data['title'])->toBe(__('kokpit.tasks.notifications.task_created.bell_title', ['reference' => $task->reference]))
        ->and($data['body'])->toBe(__('kokpit.tasks.notifications.task_created.bell_body', ['title' => $task->title, 'project' => $this->projectA->key]))
        ->and($data['actions'][0]['url'])->toEndWith('/admin/tasks/'.$task->reference);

    expect($this->partnerA->notifications()->count())->toBe(0);
});

it('renders the mail and the bell payload complete with no signed-in user', function (): void {
    $task = taskNotifCreate($this->partnerA, $this->projectA);

    auth()->logout();

    $notification = new TaskCreatedNotification(
        taskReference: $task->reference,
        taskTitle: $task->title,
        projectKey: $this->projectA->key,
        actorName: $this->partnerA->name,
        url: route('filament.admin.resources.tasks.view', ['record' => $task->reference]),
    );

    $mail = $notification->toMail($this->admin);
    $html = (string) $mail->render();

    // The title is in the subject (the contracted body line names the project and the author).
    expect($mail->subject)->toBe(__('kokpit.tasks.notifications.task_created.mail_subject', ['reference' => $task->reference, 'title' => $task->title]))
        ->and($mail->subject)->toContain($task->reference)
        ->and($mail->subject)->toContain($task->title)
        ->and($html)->toContain($this->projectA->key)
        ->and($html)->toContain($this->partnerA->name)
        ->and($html)->toContain('/admin/tasks/'.$task->reference);

    $payload = json_encode($notification->toDatabase($this->admin), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($payload)->toContain($task->reference)
        ->and($payload)->toContain($task->title)
        ->and($payload)->toContain($this->projectA->key);
});

/**
 * The task as the signed-in user reads it, found through the scoped query the way a page does.
 */
function taskNotifSeen(User $viewer, Task $task): Task
{
    test()->actingAs($viewer);

    return Task::query()->where('reference', $task->reference)->firstOrFail();
}

/**
 * A comment written by the user, signed in as that user.
 */
function taskNotifComment(User $author, Task $task, string $body, bool $internal = false): TaskComment
{
    return app(AddTaskComment::class)->handle($author, taskNotifSeen($author, $task), $body, internal: $internal);
}

/**
 * Re-points the people of a task directly in the database (a test arrangement).
 */
function taskNotifPeople(Task $task, ?User $assignee = null, ?User $requester = null): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($task, $assignee, $requester): void {
        $people = array_filter([
            'assignee_id' => $assignee?->id,
            'requester_id' => $requester?->id,
        ]);

        Task::query()->whereKey($task->id)->firstOrFail()->forceFill($people)->save();
    });
}

/**
 * Sets a user's stored notification preferences.
 *
 * @param  array<string, array<string, bool>>  $preferences
 */
function taskNotifPrefs(User $user, array $preferences): void
{
    $user->forceFill(['notification_preferences' => $preferences])->save();
}

/**
 * How many e-mails the array mailer holds for the user, optionally only those
 * whose subject contains the text.
 */
function taskNotifMailCount(User $user, ?string $subjectContains = null): int
{
    return collect(Mail::getSymfonyTransport()->messages())
        ->filter(static fn (SentMessage $message): bool => collect($message->getEnvelope()->getRecipients())
            ->contains(static fn (Address $address): bool => $address->getAddress() === $user->email))
        ->filter(static fn (SentMessage $message): bool => $subjectContains === null
            || str_contains((string) $message->getOriginalMessage()->getHeaders()->get('Subject')?->getBodyAsString(), $subjectContains))
        ->count();
}

/**
 * The comment notifications sent on the fake to the user.
 *
 * @return Collection<int, mixed>
 */
function taskNotifSent(User $user): Collection
{
    return Notification::sent($user, TaskCommentedNotification::class);
}

describe('comment notifications', function (): void {
    beforeEach(function (): void {
        $this->partnerA2 = Canary::partnerFor($this->clientA);
        $this->partnerB = Canary::partnerFor($this->clientB);
        $this->task = taskNotifCreate($this->partnerA, $this->projectA);
    });

    it('tells the Admin of a Partner comment, with a link to the admin task page, and not the author', function (): void {
        Notification::fake();

        taskNotifComment($this->partnerA, $this->task, '<p>Example remark</p>');

        Notification::assertSentTo(
            $this->admin,
            TaskCommentedNotification::class,
            fn (TaskCommentedNotification $notification, array $channels): bool => $channels === ['mail', 'database']
                && $notification->event === NotificationEvent::Comment
                && ! $notification->recipientIsPartner
                && str_ends_with($notification->url, '/admin/tasks/'.$this->task->reference),
        );
        Notification::assertNotSentTo($this->partnerA, TaskCommentedNotification::class);
        expect(taskNotifSent($this->admin))->toHaveCount(1);
    });

    it('also tells a Partner assignee of the same client, once, and not the author', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        Notification::fake();

        taskNotifComment($this->partnerA, $this->task, '<p>Example remark</p>');

        Notification::assertSentTo(
            $this->partnerA2,
            TaskCommentedNotification::class,
            fn (TaskCommentedNotification $notification): bool => $notification->recipientIsPartner
                && str_ends_with($notification->url, '/admin/my-tasks/'.$this->task->reference)
                && ! str_contains($notification->url, '/admin/tasks/'),
        );
        expect(taskNotifSent($this->partnerA2))->toHaveCount(1)
            ->and(taskNotifSent($this->admin))->toHaveCount(1)
            ->and(taskNotifSent($this->partnerA))->toHaveCount(0);
    });

    it('does not tell a Partner assignee of another client, a deactivated assignee or the author', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerB);
        Notification::fake();

        taskNotifComment($this->partnerA, $this->task, '<p>Example remark</p>');

        expect(taskNotifSent($this->partnerB))->toHaveCount(0)
            ->and(taskNotifSent($this->admin))->toHaveCount(1);

        taskNotifPeople($this->task, assignee: $this->partnerA2);
        $this->partnerA2->forceFill(['deactivated_at' => now()])->save();
        Notification::fake();

        taskNotifComment($this->partnerA, $this->task, '<p>Example second remark</p>');

        expect(taskNotifSent($this->partnerA2))->toHaveCount(0)
            ->and(taskNotifSent($this->admin))->toHaveCount(1);
    });

    it('never tells a Partner assignee of the own comment', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        Notification::fake();

        taskNotifComment($this->partnerA2, $this->task, '<p>Example own remark</p>');

        expect(taskNotifSent($this->partnerA2))->toHaveCount(0)
            ->and(taskNotifSent($this->admin))->toHaveCount(1)
            ->and(taskNotifSent($this->partnerA))->toHaveCount(0);
    });

    it('tells the Partner requester of a non-internal Admin comment, not the Admin author', function (): void {
        Notification::fake();

        taskNotifComment($this->admin, $this->task, '<p>Example answer</p>');

        Notification::assertSentTo(
            $this->partnerA,
            TaskCommentedNotification::class,
            fn (TaskCommentedNotification $notification, array $channels): bool => $channels === ['mail', 'database']
                && $notification->recipientIsPartner
                && ! $notification->internal
                && $notification->excerpt === 'Example answer'
                && str_ends_with($notification->url, '/admin/my-tasks/'.$this->task->reference),
        );
        Notification::assertNotSentTo($this->admin, TaskCommentedNotification::class);
    });

    it('tells both the Partner requester and a Partner assignee of the client, each once', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        Notification::fake();

        taskNotifComment($this->admin, $this->task, '<p>Example answer</p>');

        expect(taskNotifSent($this->partnerA))->toHaveCount(1)
            ->and(taskNotifSent($this->partnerA2))->toHaveCount(1)
            ->and(taskNotifSent($this->partnerB))->toHaveCount(0);
    });

    it('notifies nobody of an internal Admin comment, and leaves no canary in any Partner inbox', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        $canary = Canary::canary('internal');

        taskNotifComment($this->admin, $this->task, '<p>'.$canary.'</p>', internal: true);

        foreach ([$this->partnerA, $this->partnerA2, $this->partnerB] as $partner) {
            expect($partner->notifications()->count())->toBe(0);
        }

        $leaks = DB::table('notifications')->whereRaw('data::text like ?', ['%'.$canary.'%'])->count();

        expect($leaks)->toBe(0);

        Notification::fake();

        taskNotifComment($this->admin, $this->task, '<p>'.$canary.'</p>', internal: true);

        Notification::assertNotSentTo([$this->partnerA, $this->partnerA2, $this->partnerB, $this->admin], TaskCommentedNotification::class);
    });

    it('refuses to build a comment notification of an internal comment for a Partner', function (): void {
        expect(class_exists(TaskCommentedNotification::class))->toBeTrue();

        $build = static fn (bool $partner): TaskCommentedNotification => new TaskCommentedNotification(
            taskReference: 'ABC-1',
            taskTitle: 'Example task',
            projectKey: 'ABC',
            actorName: 'Example Admin',
            excerpt: 'Example excerpt',
            url: 'https://example.com/admin/my-tasks/ABC-1',
            recipientIsPartner: $partner,
            internal: true,
        );

        expect(static fn (): TaskCommentedNotification => $build(true))->toThrow(LogicException::class);

        $internalForAdmin = $build(false);

        expect($internalForAdmin->internal)->toBeTrue()
            ->and($internalForAdmin->excerpt)->toBeNull();
    });

    it('delivers only the bell when the recipient switched the e-mail of comments off', function (): void {
        taskNotifPrefs($this->admin, ['comment' => ['mail' => false]]);
        Notification::fake();

        taskNotifComment($this->partnerA, $this->task, '<p>Example remark</p>');

        Notification::assertSentTo(
            $this->admin,
            TaskCommentedNotification::class,
            static fn (TaskCommentedNotification $notification, array $channels): bool => $channels === ['database'],
        );
    });

    it('delivers nothing to a recipient who switched both channels off, and still tells the others', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        taskNotifPrefs($this->partnerA2, ['comment' => ['mail' => false, 'database' => false]]);

        taskNotifComment($this->partnerA, $this->task, '<p>Example remark</p>');

        expect($this->partnerA2->notifications()->count())->toBe(0)
            ->and(taskNotifMailCount($this->partnerA2))->toBe(0)
            ->and($this->admin->notifications()->where('data->title', __('kokpit.tasks.notifications.comment.bell_title', ['reference' => $this->task->reference]))->count())->toBe(1)
            ->and(taskNotifMailCount($this->admin))->toBeGreaterThanOrEqual(1);
    });

    it('delivers on both channels when the preference column is empty', function (): void {
        taskNotifComment($this->admin, $this->task, '<p>Example answer</p>');

        expect($this->partnerA->notifications()->count())->toBe(1)
            ->and(taskNotifMailCount($this->partnerA))->toBe(1);
    });

    it('does not tell a deactivated Partner requester or a Partner of an archived client', function (): void {
        $this->partnerA->forceFill(['deactivated_at' => now()])->save();

        taskNotifComment($this->admin, $this->task, '<p>Example answer</p>');

        expect($this->partnerA->notifications()->count())->toBe(0)
            ->and(taskNotifMailCount($this->partnerA))->toBe(0);

        $this->partnerA->forceFill(['deactivated_at' => null])->save();
        app(PartnerContext::class)->runAsSystem(fn () => Client::query()->findOrFail($this->clientA)->delete());

        taskNotifComment($this->admin, $this->task, '<p>Example second answer</p>');

        expect($this->partnerA->notifications()->count())->toBe(0)
            ->and(taskNotifMailCount($this->partnerA))->toBe(0);
    });

    it('does not send a comment notification for the comment of an escalation', function (): void {
        Notification::fake();

        app(EscalateTask::class)->handle($this->partnerA, taskNotifSeen($this->partnerA, $this->task), '<p>Example reason</p>');

        Notification::assertNotSentTo([$this->admin, $this->partnerA, $this->partnerA2], TaskCommentedNotification::class);
    });

    it('cuts the excerpt to plain text of at most 300 characters from the cleaned body', function (): void {
        Notification::fake();
        $words = implode(' ', array_fill(0, 120, 'word'));

        taskNotifComment($this->partnerA, $this->task, '<p>First <strong>bold</strong> line</p><p>'.$words.'</p>');

        /** @var TaskCommentedNotification $notification */
        $notification = taskNotifSent($this->admin)->firstOrFail();

        expect($notification->excerpt)->toStartWith('First bold line word')
            ->and(mb_strlen((string) $notification->excerpt))->toBeLessThanOrEqual(300)
            ->and($notification->excerpt)->not->toContain('<')
            ->and($notification->excerpt)->not->toContain('>');
    });

    it('renders the mail and the bell complete with no signed-in user and no tag in the excerpt', function (): void {
        Notification::fake();

        taskNotifComment($this->partnerA, $this->task, '<p>Example <strong>bold</strong> remark</p>');

        /** @var TaskCommentedNotification $notification */
        $notification = taskNotifSent($this->admin)->firstOrFail();

        auth()->logout();

        $mail = $notification->toMail($this->admin);
        $html = (string) $mail->render();
        $payload = json_encode($notification->toDatabase($this->admin), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        expect($mail->subject)->toContain($this->task->reference)
            ->and($html)->toContain('Example bold remark')
            ->and($html)->toContain($this->partnerA->name)
            ->and($html)->toContain($this->task->title)
            ->and($html)->toContain('/admin/tasks/'.$this->task->reference)
            ->and(implode(' ', $mail->introLines))->not->toContain('<strong>')
            ->and($payload)->toContain('Example bold remark')
            ->and($payload)->toContain($this->task->reference)
            ->and($payload)->toContain($this->partnerA->name)
            ->and($payload)->not->toContain('<strong>');
    });

    it('keeps markup of a comment out of the quote block of the mail', function (): void {
        Notification::fake();

        taskNotifComment($this->partnerA, $this->task, '<p>Example [link](https://example.com/x) and *stars*</p>');

        /** @var TaskCommentedNotification $notification */
        $notification = taskNotifSent($this->admin)->firstOrFail();
        $html = (string) $notification->toMail($this->admin)->render();

        expect($html)->not->toContain('href="https://example.com/x"')
            ->and($html)->not->toContain('<em>stars</em>');
    });
});

/**
 * The escalation notifications sent on the fake to the user.
 *
 * @return Collection<int, mixed>
 */
function taskNotifEscalations(User $user): Collection
{
    return Notification::sent($user, TaskEscalatedNotification::class);
}

/**
 * The user escalates the task with a visible reason, signed in as that user.
 */
function taskNotifEscalate(User $actor, Task $task, string $reason = '<p>Example reason</p>'): Task
{
    return app(EscalateTask::class)->handle($actor, taskNotifSeen($actor, $task), $reason);
}

describe('escalation notifications', function (): void {
    beforeEach(function (): void {
        $this->partnerA2 = Canary::partnerFor($this->clientA);
        $this->partnerB = Canary::partnerFor($this->clientB);
        $this->task = taskNotifCreate($this->partnerA, $this->projectA);
    });

    it('tells the Admin assignee on mail and in the bell, with the comment excerpt and the admin link', function (): void {
        Notification::fake();

        taskNotifEscalate($this->partnerA, $this->task);

        Notification::assertSentTo(
            $this->admin,
            TaskEscalatedNotification::class,
            fn (TaskEscalatedNotification $notification, array $channels): bool => $channels === ['mail', 'database']
                && $notification->event === NotificationEvent::Escalation
                && ! $notification->recipientIsPartner
                && $notification->excerpt === 'Example reason'
                && $notification->actorName === $this->partnerA->name
                && str_ends_with($notification->url, '/admin/tasks/'.$this->task->reference),
        );
        expect(taskNotifEscalations($this->admin))->toHaveCount(1)
            ->and(taskNotifEscalations($this->partnerA))->toHaveCount(0)
            ->and(taskNotifSent($this->admin))->toHaveCount(0);
    });

    it('tells a Partner assignee of the same client on both channels with the my-tasks link, and not the Admin', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        Notification::fake();

        taskNotifEscalate($this->partnerA, $this->task);

        Notification::assertSentTo(
            $this->partnerA2,
            TaskEscalatedNotification::class,
            fn (TaskEscalatedNotification $notification, array $channels): bool => $channels === ['mail', 'database']
                && $notification->recipientIsPartner
                && $notification->excerpt === 'Example reason'
                && str_ends_with($notification->url, '/admin/my-tasks/'.$this->task->reference)
                && ! str_contains($notification->url, '/admin/tasks/'),
        );
        expect(taskNotifEscalations($this->partnerA2))->toHaveCount(1)
            ->and(taskNotifEscalations($this->admin))->toHaveCount(0);
        Notification::assertNothingSentTo($this->partnerA, TaskEscalatedNotification::class);
    });

    it('falls back to the Admin when the assignee is a deactivated Partner', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        $this->partnerA2->forceFill(['deactivated_at' => now()])->save();
        Notification::fake();

        taskNotifEscalate($this->partnerA, $this->task);

        expect(taskNotifEscalations($this->admin))->toHaveCount(1)
            ->and(taskNotifEscalations($this->partnerA2))->toHaveCount(0);
    });

    it('falls back to the Admin when the assignee is a Partner of another client', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerB);
        Notification::fake();

        taskNotifEscalate($this->partnerA, $this->task);

        expect(taskNotifEscalations($this->admin))->toHaveCount(1)
            ->and(taskNotifEscalations($this->partnerB))->toHaveCount(0);
    });

    it('notifies the Admin and not the Partner when the escalating Partner is the assignee', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA);
        Notification::fake();

        taskNotifEscalate($this->partnerA, $this->task);

        expect(taskNotifEscalations($this->admin))->toHaveCount(1)
            ->and(taskNotifEscalations($this->partnerA))->toHaveCount(0);
    });

    it('notifies exactly one recipient per escalation', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        Notification::fake();

        taskNotifEscalate($this->partnerA, $this->task);

        Notification::assertSentTimes(TaskEscalatedNotification::class, 1);
    });

    it('delivers only the bell to an Admin who switched the e-mail of escalations off', function (): void {
        taskNotifPrefs($this->admin, ['escalation' => ['mail' => false]]);
        Notification::fake();

        taskNotifEscalate($this->partnerA, $this->task);

        Notification::assertSentTo(
            $this->admin,
            TaskEscalatedNotification::class,
            static fn (TaskEscalatedNotification $notification, array $channels): bool => $channels === ['database'],
        );
    });

    it('A13: tells nobody when the assignee switched escalations off on both channels, the Admin is not a preference fallback', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);
        taskNotifPrefs($this->partnerA2, ['escalation' => ['mail' => false, 'database' => false]]);

        taskNotifEscalate($this->partnerA, $this->task);

        $title = __('kokpit.tasks.notifications.escalated.bell_title', ['reference' => $this->task->reference]);
        $subject = __('kokpit.tasks.notifications.escalated.mail_subject', ['reference' => $this->task->reference, 'title' => $this->task->title]);

        expect($this->partnerA2->notifications()->where('data->title', $title)->count())->toBe(0)
            ->and(taskNotifMailCount($this->partnerA2, $subject))->toBe(0)
            ->and($this->admin->notifications()->where('data->title', $title)->count())->toBe(0)
            ->and(taskNotifMailCount($this->admin, $subject))->toBe(0);
    });

    it('stores one escalation entry of the audience in the bell and renders the mail with no signed-in user', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA2);

        taskNotifEscalate($this->partnerA, $this->task, '<p>Example <strong>urgent</strong> reason</p>');

        $title = __('kokpit.tasks.notifications.escalated.bell_title', ['reference' => $this->task->reference]);
        $row = $this->partnerA2->notifications()->where('data->title', $title)->firstOrFail();

        /** @var array<string, mixed> $data */
        $data = $row->data;

        expect($data['format'])->toBe('filament')
            ->and($data['body'])->toBe($this->partnerA->name.': Example urgent reason')
            ->and($data['actions'][0]['url'])->toEndWith('/admin/my-tasks/'.$this->task->reference);

        auth()->logout();

        $notification = new TaskEscalatedNotification(
            taskReference: $this->task->reference,
            taskTitle: $this->task->title,
            projectKey: $this->projectA->key,
            actorName: $this->partnerA->name,
            excerpt: 'Example urgent reason',
            url: route('filament.admin.resources.my-tasks.view', ['record' => $this->task->reference]),
            recipientIsPartner: true,
        );
        $mail = $notification->toMail($this->partnerA2);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe(__('kokpit.tasks.notifications.escalated.mail_subject', ['reference' => $this->task->reference, 'title' => $this->task->title]))
            ->and($html)->toContain($this->task->title)
            ->and($html)->toContain($this->partnerA->name)
            ->and($html)->toContain('Example urgent reason')
            ->and($html)->toContain('/admin/my-tasks/'.$this->task->reference);
    });

    it('refuses to build an internal escalation notification for a Partner', function (): void {
        $build = static fn (bool $partner): TaskEscalatedNotification => new TaskEscalatedNotification(
            taskReference: 'ABC-1',
            taskTitle: 'Example task',
            projectKey: 'ABC',
            actorName: 'Example Admin',
            excerpt: 'Example excerpt',
            url: 'https://example.com/admin/my-tasks/ABC-1',
            recipientIsPartner: $partner,
            internal: true,
        );

        expect(static fn (): TaskEscalatedNotification => $build(true))->toThrow(LogicException::class)
            ->and($build(false)->excerpt)->toBeNull();
    });
});

/**
 * The change notifications sent on the fake to the user.
 *
 * @return Collection<int, mixed>
 */
function taskNotifChanges(User $user): Collection
{
    return Notification::sent($user, TaskChangedNotification::class);
}

/**
 * The user saves the task through the edit action, signed in as that user.
 *
 * @param  array<string, mixed>  $data
 */
function taskNotifUpdate(User $actor, Task $task, array $data): Task
{
    // @phpstan-ignore argument.type
    return app(UpdateTask::class)->handle($actor, taskNotifSeen($actor, $task), $data);
}

/**
 * The user drops the card into the column at the index, signed in as that user.
 */
function taskNotifMove(User $actor, Task $task, ProjectStatus $status, int $index = 0): Task
{
    test()->actingAs($actor);

    return app(MoveTask::class)->handle($actor, (string) $task->id, $index, $status, BoardFilters::none());
}

describe('change notifications', function (): void {
    beforeEach(function (): void {
        $this->partnerA2 = Canary::partnerFor($this->clientA);
        $this->partnerB = Canary::partnerFor($this->clientB);
        $this->task = taskNotifCreate($this->partnerA, $this->projectA);
        $this->oldStatus = $this->task->status;
        $this->newStatus = $this->oldStatus === ProjectStatus::InProgress ? ProjectStatus::ToClarify : ProjectStatus::InProgress;
        $this->oldPriority = $this->task->priority;
        $this->newPriority = $this->oldPriority === ProjectPriority::Urgent ? ProjectPriority::Low : ProjectPriority::Urgent;
    });

    it('tells the Partner requester once with every change of one save, and not the Admin', function (): void {
        Notification::fake();

        taskNotifUpdate($this->admin, $this->task, ['status' => $this->newStatus->value, 'priority' => $this->newPriority->value]);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(1)
            ->and(taskNotifChanges($this->admin))->toHaveCount(0);

        /** @var TaskChangedNotification $notification */
        $notification = taskNotifChanges($this->partnerA)->firstOrFail();

        expect($notification->event)->toBe(NotificationEvent::AssignmentChange)
            ->and($notification->recipientIsPartner)->toBeTrue()
            ->and($notification->changedLabels)->toBe([
                __('kokpit.tasks.notifications.changed.status', ['old' => $this->oldStatus->getLabel(), 'new' => $this->newStatus->getLabel()]),
                __('kokpit.tasks.notifications.changed.priority', ['old' => $this->oldPriority->getLabel(), 'new' => $this->newPriority->getLabel()]),
            ])
            ->and($notification->url)->toEndWith('/admin/my-tasks/'.$this->task->reference);

        Notification::assertSentTo($this->partnerA, TaskChangedNotification::class, static fn ($n, array $channels): bool => $channels === ['mail', 'database']);
    });

    it('tells a newly assigned Partner and the requester, each once, with the assignee change', function (): void {
        Notification::fake();

        taskNotifUpdate($this->admin, $this->task, ['assignee_id' => $this->partnerA2->id]);

        expect(taskNotifChanges($this->partnerA2))->toHaveCount(1)
            ->and(taskNotifChanges($this->partnerA))->toHaveCount(1)
            ->and(taskNotifChanges($this->admin))->toHaveCount(0)
            ->and(taskNotifChanges($this->partnerB))->toHaveCount(0);

        /** @var TaskChangedNotification $notification */
        $notification = taskNotifChanges($this->partnerA2)->firstOrFail();

        expect($notification->changedLabels)->toBe([
            __('kokpit.tasks.notifications.changed.assignee', ['old' => $this->admin->name, 'new' => $this->partnerA2->name]),
        ])->and($notification->url)->toEndWith('/admin/my-tasks/'.$this->task->reference);
    });

    it('tells a Partner who is both requester and assignee only once', function (): void {
        taskNotifPeople($this->task, assignee: $this->partnerA);
        Notification::fake();

        taskNotifUpdate($this->admin, $this->task, ['status' => $this->newStatus->value]);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(1);
    });

    it('sends nothing for a change of the dates, the title or the description', function (): void {
        Notification::fake();

        taskNotifUpdate($this->admin, $this->task, [
            'due_date' => '2031-01-15',
            'title' => 'Example renamed task',
            'description' => '<p>Example text</p>',
        ]);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(0)
            ->and(taskNotifChanges($this->admin))->toHaveCount(0);
    });

    it('sends nothing when a save names the stored status, priority and assignee again', function (): void {
        Notification::fake();

        taskNotifUpdate($this->admin, $this->task, [
            'status' => $this->oldStatus->value,
            'priority' => $this->oldPriority->value,
            'assignee_id' => $this->task->assignee_id,
        ]);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(0);
    });

    it('sends nothing for a task with no Partner on it', function (): void {
        $own = taskNotifCreate($this->admin, $this->projectA);
        Notification::fake();

        taskNotifUpdate($this->admin, $own, ['status' => $this->newStatus->value]);

        Notification::assertNothingSent();
    });

    it('tells the Partner requester once when a board move changes the column, and not for a reorder', function (): void {
        Notification::fake();

        taskNotifMove($this->admin, $this->task, $this->newStatus);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(1)
            ->and(taskNotifChanges($this->admin))->toHaveCount(0);

        /** @var TaskChangedNotification $notification */
        $notification = taskNotifChanges($this->partnerA)->firstOrFail();

        expect($notification->changedLabels)->toBe([
            __('kokpit.tasks.notifications.changed.status', ['old' => $this->oldStatus->getLabel(), 'new' => $this->newStatus->getLabel()]),
        ]);

        $second = taskNotifCreate($this->partnerA, $this->projectA);
        taskNotifMove($this->admin, $second, $this->newStatus);
        Notification::fake();

        taskNotifMove($this->admin, $second, $this->newStatus, index: 0);
        taskNotifMove($this->admin, $this->task, $this->newStatus, index: 1);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(0);
    });

    it('tells the Partner when a card is dropped into the done column', function (): void {
        Notification::fake();

        taskNotifMove($this->admin, $this->task, ProjectStatus::Done);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(1);
    });

    it('delivers nothing to a Partner who switched the change notifications off on both channels', function (): void {
        taskNotifPrefs($this->partnerA, ['assignment_change' => ['mail' => false, 'database' => false]]);

        taskNotifUpdate($this->admin, $this->task, ['status' => $this->newStatus->value]);

        $title = __('kokpit.tasks.notifications.changed.bell_title', ['reference' => $this->task->reference]);

        expect($this->partnerA->notifications()->where('data->title', $title)->count())->toBe(0)
            ->and(taskNotifMailCount($this->partnerA, $title))->toBe(0);
    });

    it('delivers only the bell to a Partner who switched the e-mail of change notifications off', function (): void {
        taskNotifPrefs($this->partnerA, ['assignment_change' => ['mail' => false]]);
        Notification::fake();

        taskNotifUpdate($this->admin, $this->task, ['status' => $this->newStatus->value]);

        Notification::assertSentTo(
            $this->partnerA,
            TaskChangedNotification::class,
            static fn ($notification, array $channels): bool => $channels === ['database'],
        );
    });

    it('does not tell a deactivated Partner requester', function (): void {
        $this->partnerA->forceFill(['deactivated_at' => now()])->save();
        Notification::fake();

        taskNotifUpdate($this->admin, $this->task, ['status' => $this->newStatus->value]);

        expect(taskNotifChanges($this->partnerA))->toHaveCount(0);
    });

    it('stores one bell entry with a line per change that opens the Partner page, and renders the mail with no signed-in user', function (): void {
        taskNotifUpdate($this->admin, $this->task, ['status' => $this->newStatus->value, 'priority' => $this->newPriority->value]);

        $title = __('kokpit.tasks.notifications.changed.bell_title', ['reference' => $this->task->reference]);
        $rows = $this->partnerA->notifications()->where('data->title', $title)->get();

        expect($rows)->toHaveCount(1);

        /** @var array<string, mixed> $data */
        $data = $rows[0]->data;

        expect($data['format'])->toBe('filament')
            ->and($data['body'])->toContain($this->newStatus->getLabel())
            ->and($data['body'])->toContain($this->newPriority->getLabel())
            ->and($data['actions'][0]['url'])->toEndWith('/admin/my-tasks/'.$this->task->reference);

        auth()->logout();

        $notification = new TaskChangedNotification(
            taskReference: $this->task->reference,
            taskTitle: $this->task->title,
            projectKey: $this->projectA->key,
            actorName: $this->admin->name,
            url: route('filament.admin.resources.my-tasks.view', ['record' => $this->task->reference]),
            changedLabels: ['Stav: A → B', 'Priorita: C → D'],
        );
        $mail = $notification->toMail($this->partnerA);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe(__('kokpit.tasks.notifications.changed.mail_subject', ['reference' => $this->task->reference, 'title' => $this->task->title]))
            ->and($html)->toContain('Stav: A → B')
            ->and($html)->toContain('Priorita: C → D')
            ->and($html)->toContain('/admin/my-tasks/'.$this->task->reference);
    });
});
