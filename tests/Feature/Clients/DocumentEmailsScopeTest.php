<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Actions\SendPartnerPasswordReset;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Notifications\TaskCommentedNotification;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Canary;

/*
 * The scope of the per-client switch "send documents by e-mail" (Phase 16, D-01, P16-11): it silences the e-mails that
 * carry a document (an invoice, a proforma, a credit note, a payment confirmation) and nothing else. The invitation of
 * a Partner, the password reset and the task notifications to the Partner users of a client whose switch is off go out
 * exactly as before; none of those classes asks the switch. Notifications are faked, nothing is mailed; every name and
 * address is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->client = Client::factory()->withoutDocumentEmails()->create();
});

/**
 * A client-visible project of the client, written through the domain Action as the system.
 */
function docEmailsScopeProject(Client $client): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle($client, [
        'name' => 'Example project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
}

/**
 * The comment notifications the fake holds for the user.
 *
 * @return Collection<int, mixed>
 */
function docEmailsScopeComments(User $user): Collection
{
    return Notification::sent($user, TaskCommentedNotification::class);
}

describe('a client with the document e-mails switched off', function (): void {
    it('has the switch off, so the scope below is not tested against a client that is switched on', function (): void {
        expect($this->client->refresh()->document_emails_enabled)->toBeFalse();
    });

    it('still gets the invitation of a Partner (InvitePartner)', function (): void {
        Notification::fake();
        $address = exampleEmail('new-partner');

        $invitation = app(InvitePartner::class)->handle($this->client, 'Example Invitee', $address, $this->admin);

        expect($invitation->email)->toBe($address);

        Notification::assertSentOnDemandTimes(PartnerInvitation::class, 1);
        Notification::assertSentOnDemand(
            PartnerInvitation::class,
            static fn (PartnerInvitation $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $address,
        );
    });

    it('still sends the password reset to an active Partner of the client (SendPartnerPasswordReset)', function (): void {
        Notification::fake();
        $partner = Canary::partnerFor($this->client->id);

        app(SendPartnerPasswordReset::class)->handle($partner);

        Notification::assertSentToTimes($partner, ResetPasswordNotification::class, 1);
    });

    it('still notifies the Partner requester of a non-internal Admin comment (TaskNotifier, commented)', function (): void {
        $partner = Canary::partnerFor($this->client->id);
        $project = docEmailsScopeProject($this->client);

        $this->actingAs($partner);
        $created = app(CreateTask::class)->handle($partner, $project, ['title' => 'Example task']);

        $this->actingAs($this->admin);
        $task = Task::query()->where('reference', $created->reference)->firstOrFail();
        Notification::fake();

        app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example answer</p>', internal: false);

        Notification::assertSentTo(
            $partner,
            TaskCommentedNotification::class,
            static fn (TaskCommentedNotification $notification, array $channels): bool => $channels === ['mail', 'database']
                && $notification->recipientIsPartner
                && ! $notification->internal,
        );
        expect(docEmailsScopeComments($partner))->toHaveCount(1);
    });
});
