<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
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
