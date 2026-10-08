<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Pages\Auth\AcceptInvitation;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Accepting a Partner invitation (US-02, D-01): the guest sets a password, a Partner
 * account of the inviting client is created exactly once and every failure is neutral.
 * Every name, e-mail address and password is fictional and assembled at runtime.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Notification::fake();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->clientA = Client::factory()->create(['currency' => 'CZK']);
    $this->clientB = Client::factory()->create(['currency' => 'CZK']);
});

/**
 * A password of the given length that satisfies the length rules, assembled at runtime.
 */
function acceptPassword(int $length = 20): string
{
    return substr(implode('-', ['Example', bin2hex(random_bytes(16)), 'pw']), 0, $length);
}

/**
 * Invites a fictional person as the Admin and returns [invitation, id, plain token, url].
 *
 * @return array{0: ClientInvitation, 1: string, 2: string, 3: string}
 */
function acceptInvite(Client $client, ?string $email = null, string $name = 'Example Invitee'): array
{
    $email ??= exampleEmail();
    $invitation = app(InvitePartner::class)->handle($client, $name, $email, test()->admin);
    $url = null;

    Notification::assertSentOnDemand(
        PartnerInvitation::class,
        static function (PartnerInvitation $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url): bool {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }

            $url = $notification->acceptUrl;

            return true;
        },
    );

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    return [$invitation, (string) $query['invitation'], (string) $query['token'], (string) $url];
}

/**
 * Forgets the signed-in user: the link is opened by a guest.
 */
function acceptAsGuest(): void
{
    auth()->forgetGuards();
}

/**
 * The accept page as the invited guest opens it.
 */
function acceptPage(string $id, string $token): Testable
{
    return Livewire::withQueryParams(['invitation' => $id, 'token' => $token])->test(AcceptInvitation::class);
}

/**
 * Creates a project of the client in a system run, with an hourly rate.
 */
function acceptProject(Client $client, string $name, bool $visible, string $rate): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => $rate,
        'client_visible' => $visible,
    ]));
}

/**
 * The invitation as stored now, read as a system run so no scope hides it.
 */
function acceptStored(ClientInvitation $invitation): ClientInvitation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ClientInvitation => ClientInvitation::query()->findOrFail($invitation->id));
}

/**
 * The users row count, read as a system run so no scope hides any.
 */
function acceptUserCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => User::query()->count());
}

it('turns the mailed link into a Partner account that logs in and sees only the client\'s visible projects (US-02, D-01)', function (): void {
    $nameVisible = Canary::canary('visible_a');
    $nameHidden = Canary::canary('hidden_a');
    $nameOther = Canary::canary('visible_b');
    $visible = acceptProject($this->clientA, $nameVisible, true, '777,31');
    acceptProject($this->clientA, $nameHidden, false, '654,32');
    acceptProject($this->clientB, $nameOther, true, '543,33');

    [$invitation, $id, $token, $url] = acceptInvite($this->clientA, name: 'Example Invitee');
    $users = acceptUserCount();
    acceptAsGuest();

    $this->get($url)->assertOk()->assertSee($invitation->email);

    $password = acceptPassword();

    acceptPage($id, $token)
        ->assertSet('data.name', 'Example Invitee')
        ->fillForm(['name' => 'Example Person', 'password' => $password, 'passwordConfirmation' => $password])
        ->call('accept')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getLoginUrl());

    $row = DB::table('users')->where('email', $invitation->email)->get();

    expect(acceptUserCount())->toBe($users + 1)
        ->and($row)->toHaveCount(1)
        ->and($row[0]->name)->toBe('Example Person')
        ->and($row[0]->client_id)->toBe($this->clientA->id)
        ->and($row[0]->email_verified_at)->not->toBeNull()
        ->and($row[0]->deactivated_at)->toBeNull()
        ->and(Hash::check($password, $row[0]->password))->toBeTrue()
        ->and($row[0]->password)->not->toContain($password);

    $user = User::query()->findOrFail($row[0]->id);
    $stored = acceptStored($invitation);

    expect($user->hasRole(RoleName::Partner->value))->toBeTrue()
        ->and($user->hasRole(RoleName::Admin->value))->toBeFalse()
        ->and($stored->accepted_at)->not->toBeNull()
        ->and($stored->accepted_user_id)->toBe($user->id);

    // The new Partner signs in through the normal login page.
    auth()->forgetGuards();
    Livewire::test(Login::class)
        ->fillForm(['email' => $invitation->email, 'password' => $password])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->id())->toBe($user->id);

    $billing = app(PartnerContext::class)->runAsSystem(static fn () => $visible->billing?->hourly_rate);
    $body = (string) $this->get(route('filament.admin.resources.my-projects.index'))->assertOk()->getContent();

    expect(str_contains($body, $nameVisible))->toBeTrue()
        ->and(str_contains($body, $nameHidden))->toBeFalse()
        ->and(str_contains($body, $nameOther))->toBeFalse()
        ->and($billing)->not->toBeNull()
        ->and(str_contains($body, (string) $billing?->format('cs')))->toBeFalse()
        ->and(str_contains($body, '777,31'))->toBeFalse()
        ->and(str_contains($body, '654,32'))->toBeFalse()
        ->and(str_contains($body, '543,33'))->toBeFalse();
});
