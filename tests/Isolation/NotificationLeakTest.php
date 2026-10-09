<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\EscalateTask;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Notifications\TaskChangedNotification;
use App\Domain\Tasks\Notifications\TaskCommentedNotification;
use App\Domain\Tasks\Notifications\TaskCreatedNotification;
use App\Domain\Tasks\Notifications\TaskEscalatedNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;

/*
 * Nothing internal, financial or foreign reaches a Partner through a notification
 * (TA-07, D-07, D-13; threats T-05-39, T-05-40, T-05-43). Canaries are put into
 * every place a notification body could be built from: an internal comment, a
 * checklist item, a task tag, the internal billing note, the rates, the price and
 * the estimate of the task, and the title, key and number of a task of client B.
 * Then every event that can reach Partner A is triggered, and the mails (rendered
 * with no signed-in user, as a worker does), the bell rows and the links are
 * searched for them. Every name and value is fictional and assembled at runtime.
 */

/**
 * A client-visible project of the client, created through the domain Action.
 */
function notifLeakProject(string $clientId): Project
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
function notifLeakTask(User $actor, Project $project, ?string $title = null): Task
{
    test()->actingAs($actor);

    return app(CreateTask::class)->handle($actor, $project, ['title' => $title ?? Canary::canary('task')]);
}

/**
 * The task as the signed-in user reads it, through the scoped query a page uses.
 */
function notifLeakSeen(User $viewer, Task $task): Task
{
    test()->actingAs($viewer);

    return Task::query()->where('reference', $task->reference)->firstOrFail();
}

/**
 * Re-points the assignee of a task directly in the database (a test arrangement).
 */
function notifLeakAssign(Task $task, User $assignee): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($task, $assignee): void {
        Task::query()->whereKey($task->id)->firstOrFail()->forceFill(['assignee_id' => $assignee->id])->save();
    });
}

/**
 * Triggers every event that can reach Partner A, and the ones of client B.
 */
function notifLeakRunEvents(object $c): void
{
    $update = static fn (User $actor, Task $task, array $data): Task => app(UpdateTask::class)->handle($actor, notifLeakSeen($actor, $task), $data);
    $comment = static fn (User $actor, Task $task, string $body, bool $internal = false) => app(AddTaskComment::class)->handle($actor, notifLeakSeen($actor, $task), $body, internal: $internal);
    $escalate = static fn (User $actor, Task $task): Task => app(EscalateTask::class)->handle($actor, notifLeakSeen($actor, $task), '<p>Example reason of an escalation</p>');

    // Admin: assignment of the task to Partner A, a visible answer, an internal remark, a status and priority change, a board move.
    $update($c->admin, $c->taskA, ['assignee_id' => $c->partnerA->id]);
    $comment($c->admin, $c->taskA, '<p>Example visible answer</p>');
    $comment($c->admin, $c->taskA, '<p>'.$c->internal.'</p>', true);
    $update($c->admin, $c->taskA, ['status' => ProjectStatus::InProgress->value, 'priority' => ProjectPriority::Urgent->value]);
    test()->actingAs($c->admin);
    app(MoveTask::class)->handle($c->admin, (string) $c->taskA->id, 0, ProjectStatus::InReview, BoardFilters::none());

    // Partner A2 of the same client: a comment and an escalation of the task assigned to Partner A.
    $comment($c->partnerA2, $c->taskA, '<p>Example remark of a colleague</p>');
    $escalate($c->partnerA2, $c->taskA);

    // Partner A: an own task assigned to the Admin, a comment and an escalation (the Admin is told).
    $comment($c->partnerA, $c->taskA2, '<p>Example question of the client</p>');
    $escalate($c->partnerA, $c->taskA2);

    // Client B: the Admin and Partner B on a task of client B, none of it for Partner A.
    $comment($c->admin, $c->taskB, '<p>Example answer to client B</p>');
    $update($c->admin, $c->taskB, ['status' => ProjectStatus::InProgress->value]);
    $escalate($c->partnerB, $c->taskB);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->projectA = notifLeakProject($this->clientA);
    $this->projectB = notifLeakProject($this->clientB);
    $this->partnerA = Canary::partnerFor($this->clientA);
    $this->partnerA2 = Canary::partnerFor($this->clientA);
    $this->partnerB = Canary::partnerFor($this->clientB);

    $this->taskA = notifLeakTask($this->partnerA, $this->projectA);
    $this->taskA2 = notifLeakTask($this->partnerA, $this->projectA);
    $this->taskB = notifLeakTask($this->partnerB, $this->projectB, Canary::canary('clientb'));

    $this->internal = Canary::canary('internal');
    $this->item = Canary::canary('item');
    $this->tag = Canary::canary('tag');
    $this->note = Canary::canary('note');
    $this->rate = random_int(7001, 9999).'.'.random_int(11, 99);
    $this->price = random_int(7001, 9999).'.'.random_int(11, 99);
    $this->estimate = random_int(31, 97).'.5';

    // The canaries sit on the task Partner A is notified about.
    app(PartnerContext::class)->runAsSystem(function (): void {
        $this->taskA->syncTagsWithType([$this->tag], TagType::Task->value);
        $this->taskA->checklistItems()->create(['text' => $this->item, 'position' => 1]);
    });

    app(UpdateTask::class)->handle($this->admin, notifLeakSeen($this->admin, $this->taskA), [
        'billing_type' => 'fixed_price',
        'hourly_rate' => $this->rate,
        'fixed_price' => $this->price,
        'estimate_hours' => $this->estimate,
        'internal_note' => $this->note,
    ]);

    // Nobody had a reason to hold the setup notifications; start every test from an empty inbox.
    DB::table('notifications')->delete();
    Mail::getSymfonyTransport()->flush();

    /** @var list<string> $canaries */
    $this->canaries = [
        $this->internal,
        $this->item,
        $this->tag,
        $this->note,
        $this->rate,
        $this->price,
        $this->estimate,
        $this->taskB->title,
        $this->taskB->reference,
        $this->projectB->key,
    ];
});

/**
 * The subject and the decoded text and HTML parts of a sent mail (the raw message is
 * quoted-printable wrapped, which could split a leaked value across lines).
 */
function notifLeakDecoded(SentMessage $message): string
{
    $original = $message->getOriginalMessage();

    return $original instanceof Email
        ? implode("\n", [(string) $original->getSubject(), (string) $original->getTextBody(), (string) $original->getHtmlBody()])
        : $message->toString();
}

/**
 * Every notification of the classes sent to the user on the fake.
 *
 * @return list<mixed>
 */
function notifLeakSent(User $user): array
{
    $sent = [];

    foreach ([TaskCreatedNotification::class, TaskCommentedNotification::class, TaskEscalatedNotification::class, TaskChangedNotification::class] as $class) {
        array_push($sent, ...Notification::sent($user, $class)->all());
    }

    return $sent;
}

it('renders no mail or bell payload of Partner A with any internal, billing or foreign canary, after the sign-out of a worker', function (): void {
    Notification::fake();

    notifLeakRunEvents($this);

    $sent = notifLeakSent($this->partnerA);

    // The scenario is not vacuous: Partner A is told of comments, an escalation and changes.
    expect(Notification::sent($this->partnerA, TaskCommentedNotification::class))->toHaveCount(2)
        ->and(Notification::sent($this->partnerA, TaskEscalatedNotification::class))->toHaveCount(1)
        ->and(Notification::sent($this->partnerA, TaskChangedNotification::class))->toHaveCount(3)
        ->and(Notification::sent($this->partnerA, TaskCreatedNotification::class))->toHaveCount(0);

    auth()->logout();

    foreach ($sent as $notification) {
        $html = (string) $notification->toMail($this->partnerA)->render();
        $subject = (string) $notification->toMail($this->partnerA)->subject;
        $payload = json_encode($notification->toDatabase($this->partnerA), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        foreach ($this->canaries as $canary) {
            expect($html)->not->toContain($canary)
                ->and($subject)->not->toContain($canary)
                ->and($payload)->not->toContain($canary);
        }

        expect($notification->url)->toContain('/admin/my-tasks/'.$this->taskA->reference)
            ->and($notification->url)->not->toContain('/admin/tasks/')
            ->and($html)->toContain('/admin/my-tasks/'.$this->taskA->reference)
            ->and($html)->not->toContain('/admin/tasks/')
            ->and($payload)->not->toContain('/admin/tasks/');
    }
});

it('stores no canary in any row of Partner A, with only own rows that all open the my-tasks page', function (): void {
    notifLeakRunEvents($this);

    $rows = DatabaseNotification::query()->where('notifiable_id', $this->partnerA->id)->get();

    // Not vacuous, and no trace of the internal remark: the visible answer and the colleague's remark are the only two comments.
    $count = static fn (string $key): int => $rows->where('data.title', __('kokpit.tasks.notifications.'.$key, ['reference' => test()->taskA->reference]))->count();

    expect($rows)->toHaveCount(6)
        ->and($count('comment.bell_title'))->toBe(2)
        ->and($count('escalated.bell_title'))->toBe(1)
        ->and($count('changed.bell_title'))->toBe(3);

    foreach ($rows as $row) {
        $data = json_encode($row->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        foreach ($this->canaries as $canary) {
            expect($data)->not->toContain($canary);
        }

        /** @var array<string, mixed> $stored */
        $stored = $row->data;
        $url = (string) $stored['actions'][0]['url'];

        expect($url)->toContain('/admin/my-tasks/'.$this->taskA->reference)
            ->and($url)->not->toContain('/admin/tasks/');
    }

    // Raw search over the rows of both Partners of client A: the canary of client B is theirs to never see either.
    foreach ($this->canaries as $canary) {
        $leaks = DB::table('notifications')
            ->whereIn('notifiable_id', [$this->partnerA->id, $this->partnerA2->id])
            ->whereRaw('data::text like ?', ['%'.$canary.'%'])
            ->count();

        expect($leaks)->toBe(0);
    }
});

it('sends no mail to Partner A that holds any canary', function (): void {
    notifLeakRunEvents($this);

    $messages = collect(Mail::getSymfonyTransport()->messages())
        ->filter(fn (SentMessage $message): bool => collect($message->getEnvelope()->getRecipients())
            ->contains(fn (Address $address): bool => $address->getAddress() === $this->partnerA->email));

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        $raw = notifLeakDecoded($message);

        foreach ($this->canaries as $canary) {
            expect($raw)->not->toContain($canary);
        }
    }
});

it('returns only the own rows from the bell of Partner A, none of the Admin and none of another client', function (): void {
    notifLeakRunEvents($this);

    $this->actingAs($this->partnerA);

    $page = Livewire::test(DatabaseNotifications::class)->instance()->getNotifications();

    /** @var list<DatabaseNotification> $bell */
    $bell = $page instanceof Paginator ? $page->items() : $page->all();
    $ids = array_map(static fn (DatabaseNotification $row): string => (string) $row->id, $bell);

    expect($ids)->not->toBeEmpty();

    foreach ($bell as $row) {
        expect($row->notifiable_id)->toBe($this->partnerA->id)
            ->and($row->notifiable_type)->toBe((new User)->getMorphClass());
    }

    $foreign = DatabaseNotification::query()->where('notifiable_id', '!=', $this->partnerA->id)->pluck('id')->map(strval(...))->all();

    expect($foreign)->not->toBeEmpty()
        ->and(array_intersect($ids, $foreign))->toBe([])
        ->and(count($ids))->toBe(DatabaseNotification::query()->where('notifiable_id', $this->partnerA->id)->count());
});

it('links every notification of the Admin to the tasks route and never to my-tasks, with no foreign canary', function (): void {
    notifLeakRunEvents($this);

    $rows = DatabaseNotification::query()->where('notifiable_id', $this->admin->id)->get();

    // A Partner's comment and escalations reach the Admin; the Admin is told of client B's escalation too.
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        /** @var array<string, mixed> $stored */
        $stored = $row->data;
        $url = (string) $stored['actions'][0]['url'];

        expect($url)->toContain('/admin/tasks/')
            ->and($url)->not->toContain('/admin/my-tasks/');
    }

    // The escalation of Partner A's task assigned to the Admin names nothing of client B.
    $title = __('kokpit.tasks.notifications.escalated.bell_title', ['reference' => $this->taskA2->reference]);
    $escalation = $rows->first(static fn (DatabaseNotification $row): bool => ($row->data['title'] ?? null) === $title);

    expect($escalation)->not->toBeNull();

    $data = json_encode($escalation->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    foreach ([$this->taskB->title, $this->projectB->key] as $foreign) {
        expect($data)->not->toContain($foreign);
    }

    $messages = collect(Mail::getSymfonyTransport()->messages())
        ->filter(fn (SentMessage $message): bool => collect($message->getEnvelope()->getRecipients())
            ->contains(fn (Address $address): bool => $address->getAddress() === $this->admin->email))
        ->filter(fn (SentMessage $message): bool => str_contains(notifLeakDecoded($message), $this->taskA2->reference));

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        expect(notifLeakDecoded($message))->not->toContain($this->taskB->title)
            ->and(notifLeakDecoded($message))->not->toContain($this->projectB->key);
    }
});
