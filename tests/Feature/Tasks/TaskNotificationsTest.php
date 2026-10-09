<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Notifications\TaskCreatedNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
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

    $payload = json_encode($notification->toDatabase($this->admin), JSON_THROW_ON_ERROR);

    expect($payload)->toContain($task->reference)
        ->and($payload)->toContain($task->title)
        ->and($payload)->toContain($this->projectA->key);
});
