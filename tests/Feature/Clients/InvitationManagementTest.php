<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\AcceptInvitation;
use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Actions\ResendInvitation;
use App\Domain\Clients\Enums\InvitationState;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\InvitationsRelationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Invitation management in the client detail (US-02, D-01 to D-03): the "Pozvat
 * partnera" header action and the "Pozvánky" tab. Livewire tests as the Admin;
 * every name and e-mail address is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->client = Client::factory()->create();
});

/**
 * The client detail page as the Admin sees it.
 */
function manageDetail(Client $client): Testable
{
    return Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()]);
}

/**
 * The number of invitation rows, read as a system run so no scope hides any.
 */
function manageInvitationCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => ClientInvitation::query()->count());
}

/**
 * The signed links mailed to one address so far.
 *
 * @return list<string>
 */
function manageLinksTo(string $email): array
{
    $urls = [];

    Notification::assertSentOnDemand(
        PartnerInvitation::class,
        static function (PartnerInvitation $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$urls): bool {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }

            $urls[] = $notification->acceptUrl;

            return true;
        },
    );

    return $urls;
}

it('invites a Partner from the client detail: one pending invitation, one mail, a link that opens the guest page (US-02, D-01)', function (): void {
    Notification::fake();
    $email = exampleEmail();

    manageDetail($this->client)
        ->callAction('invitePartner', ['name' => 'Example Invitee', 'email' => $email])
        ->assertHasNoActionErrors()
        ->assertNotified(__('kokpit.invitations.admin.notifications.sent'));

    $invitation = app(PartnerContext::class)->runAsSystem(static fn (): ClientInvitation => ClientInvitation::query()->sole());

    expect($invitation->client_id)->toBe($this->client->id)
        ->and($invitation->email)->toBe($email)
        ->and($invitation->name)->toBe('Example Invitee')
        ->and($invitation->invited_by)->toBe($this->admin->id)
        ->and($invitation->state()->value)->toBe('pending');

    Notification::assertSentOnDemandTimes(PartnerInvitation::class, 1);
    $links = manageLinksTo($email);

    // The invited person is a guest.
    auth()->forgetGuards();
    $this->get($links[0])->assertOk();
});

it('fills name and e-mail from a picked contact of the client and never submits the pick itself', function (): void {
    Notification::fake();
    $email = exampleEmail();
    $contact = app(CreateContact::class)->handle($this->client, ['name' => 'Example Contact', 'email' => $email]);
    $foreign = app(CreateContact::class)->handle(Client::factory()->create(), ['name' => 'Example Foreign', 'email' => exampleEmail()]);

    $page = manageDetail($this->client)
        ->mountAction('invitePartner')
        ->fillForm(['contact_id' => $contact->id])
        ->assertSchemaStateSet(['name' => 'Example Contact', 'email' => $email]);

    // A contact of another client is not an option and fills nothing.
    $page->fillForm(['contact_id' => $foreign->id, 'name' => 'Keep', 'email' => 'keep@'.implode('.', ['example', 'com'])])
        ->assertSchemaStateSet(['name' => 'Keep']);

    $page->fillForm(['contact_id' => $contact->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(manageInvitationCount())->toBe(1);
    manageLinksTo($email);
});

it('shows a duplicate e-mail as a Czech error on the e-mail field and creates nothing (D-03)', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);
    $openEmail = exampleEmail();
    manageDetail($this->client)->callAction('invitePartner', ['name' => 'Example Open', 'email' => $openEmail]);
    $before = manageInvitationCount();

    foreach ([
        'the Admin e-mail' => [$this->admin->email, 'email_has_account'],
        'a Partner e-mail in upper case' => [Str::upper($partner->email), 'email_has_account'],
        'an e-mail with an open invitation' => [$openEmail, 'email_has_open_invitation'],
        'an open invitation in upper case' => [Str::upper($openEmail), 'email_has_open_invitation'],
    ] as $label => [$email, $key]) {
        $page = manageDetail($this->client)
            ->callAction('invitePartner', ['name' => 'Example Person', 'email' => $email])
            ->assertHasActionErrors(['email']);

        expect($page->errors()->get('mountedActions.0.data.email'))->toBe([__('kokpit.invitations.errors.'.$key)], $label)
            ->and(manageInvitationCount())->toBe($before, $label);
    }

    Notification::assertSentOnDemandTimes(PartnerInvitation::class, 1);
});

it('requires a name and a valid e-mail in the invite form', function (): void {
    Notification::fake();

    manageDetail($this->client)
        ->callAction('invitePartner', ['name' => '', 'email' => 'not-an-email'])
        ->assertHasActionErrors(['name' => 'required', 'email' => 'email']);

    expect(manageInvitationCount())->toBe(0);
    Notification::assertNothingSent();
});

it('hides the invite action on an archived client and offers it on an active one', function (): void {
    manageDetail($this->client)->assertActionVisible('invitePartner');

    $this->client->delete();

    manageDetail($this->client)->assertActionHidden('invitePartner');
});

it('refuses a Partner the client detail, so the invite action cannot be mounted', function (): void {
    $this->actingAs(Canary::partnerFor($this->client->id));

    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->assertForbidden();

    expect(manageInvitationCount())->toBe(0);
});

/**
 * The invitations tab of a client as the Admin sees it on the detail page.
 */
function manageTab(Client $client): Testable
{
    return Livewire::test(InvitationsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * Invites a fictional person for the client of the test through the domain Action.
 */
function manageInvite(?Client $client = null, ?string $email = null): ClientInvitation
{
    return app(InvitePartner::class)->handle($client ?? test()->client, 'Example Invitee', $email ?? exampleEmail(), test()->admin);
}

/**
 * Reads the stored row again, as a system run so no scope hides it.
 */
function manageFresh(ClientInvitation $invitation): ClientInvitation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ClientInvitation => ClientInvitation::query()->findOrFail($invitation->getKey()));
}

/**
 * The plain token of the last mail sent to the invitation's address.
 */
function manageTokenOf(ClientInvitation $invitation): string
{
    $links = manageLinksTo($invitation->email);
    parse_str((string) parse_url($links[array_key_last($links)], PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

it('lists the invitations of the client with the Czech state label and none of another client (D-02)', function (): void {
    Notification::fake();
    $own = manageInvite();
    $foreign = manageInvite(Client::factory()->create());

    manageTab($this->client)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee(InvitationState::Pending->getLabel())
        ->assertSee($own->email)
        ->assertSee('Example Invitee');
});

it('resends a pending invitation from the tab: a second mail, a bumped send count', function (): void {
    Notification::fake();
    $invitation = manageInvite();

    manageTab($this->client)
        ->assertActionVisible(TestAction::make('resend')->table($invitation))
        ->callAction(TestAction::make('resend')->table($invitation))
        ->assertNotified(__('kokpit.invitations.admin.notifications.resent'));

    Notification::assertSentOnDemandTimes(PartnerInvitation::class, 2);
    expect(manageFresh($invitation)->send_count)->toBe(2)
        ->and(manageFresh($invitation)->state())->toBe(InvitationState::Pending);
});

it('resends an expired invitation and shows it as pending again', function (): void {
    Notification::fake();
    $invitation = manageInvite();
    $invitation->forceFill(['expires_at' => now()->subHour()])->save();

    manageTab($this->client)
        ->assertSee(InvitationState::Expired->getLabel())
        ->assertActionVisible(TestAction::make('resend')->table($invitation))
        ->assertActionVisible(TestAction::make('revoke')->table($invitation))
        ->callAction(TestAction::make('resend')->table($invitation));

    expect(manageFresh($invitation)->state())->toBe(InvitationState::Pending)
        ->and(manageFresh($invitation)->send_count)->toBe(2);
});

it('revokes an invitation from the tab, shows Zrušena and offers no action any more', function (): void {
    Notification::fake();
    $invitation = manageInvite();

    manageTab($this->client)
        ->callAction(TestAction::make('revoke')->table($invitation))
        ->assertNotified(__('kokpit.invitations.admin.notifications.revoked'))
        ->assertSee(InvitationState::Revoked->getLabel())
        ->assertActionHidden(TestAction::make('resend')->table($invitation))
        ->assertActionHidden(TestAction::make('revoke')->table($invitation));

    expect(manageFresh($invitation)->state())->toBe(InvitationState::Revoked);
    Notification::assertSentOnDemandTimes(PartnerInvitation::class, 1);
});

it('offers neither action on an accepted invitation', function (): void {
    Notification::fake();
    $invitation = manageInvite();
    app(AcceptInvitation::class)->handle($invitation->getKey(), manageTokenOf($invitation), 'Example Person', Str::password(20, symbols: false));

    manageTab($this->client)
        ->assertSee(InvitationState::Accepted->getLabel())
        ->assertActionHidden(TestAction::make('resend')->table($invitation))
        ->assertActionHidden(TestAction::make('revoke')->table($invitation));
});

it('shows a failed resend or revoke of a stale row as a notification instead of an error page', function (): void {
    Notification::fake();
    $invitation = manageInvite();
    $tab = manageTab($this->client);

    // Revoked in another tab of the browser after this list was rendered.
    app(PartnerContext::class)->runAsSystem(static fn () => ClientInvitation::query()->whereKey($invitation->getKey())->update(['revoked_at' => now()]));

    $tab->callAction(TestAction::make('resend')->table($invitation))
        ->assertNotified(__('kokpit.invitations.admin.notifications.failed'));

    expect(manageFresh($invitation)->send_count)->toBe(1);
    Notification::assertSentOnDemandTimes(PartnerInvitation::class, 1);
});

it('refuses the Action to resend an invitation of an archived client and the tab hides the actions', function (): void {
    Notification::fake();
    $invitation = manageInvite();
    $this->client->delete();

    manageTab($this->client)
        ->assertSee(InvitationState::Pending->getLabel())
        ->assertActionHidden(TestAction::make('resend')->table($invitation));

    expect(static fn () => app(ResendInvitation::class)->handle($invitation))
        ->toThrow(DomainException::class, __('kokpit.invitations.errors.client_archived'))
        ->and(manageFresh($invitation)->send_count)->toBe(1);
    Notification::assertSentOnDemandTimes(PartnerInvitation::class, 1);
});

it('offers no create, edit or delete of an invitation in the tab (invitations change only through the Actions)', function (): void {
    $table = manageTab($this->client)->instance()->getTable();

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(array_keys($table->getFlatRecordActions()))->toBe(['resend', 'revoke']);
});

it('refuses a Partner the invitations tab at boot and lists the tab in the client resource', function (): void {
    $invitation = manageInvite();
    $this->actingAs(Canary::partnerFor($this->client->id));

    expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => InvitationsRelationManager::canViewForRecord(test()->client, ViewClient::class)))->toBeFalse()
        ->and(ClientInvitation::query()->count())->toBe(0)
        ->and(ClientResource::getRelations())->toContain(InvitationsRelationManager::class);

    manageTab($this->client)->assertForbidden();
    expect(manageFresh($invitation)->state())->toBe(InvitationState::Pending);
});
