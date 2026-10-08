<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Actions\ResendInvitation;
use App\Domain\Clients\Actions\RevokeInvitation;
use App\Domain\Clients\InvitationMail;
use App\Domain\Clients\InvitationNotAcceptable;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

/**
 * The visible text of an HTML fragment: scripts, styles and tags removed, whitespace collapsed.
 */
function acceptVisibleText(string $html): string
{
    $html = (string) preg_replace('~<(script|style)\b.*?</\1>~si', ' ', $html);

    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
}

/**
 * The users rows with the given e-mail address in any letter case, read raw.
 */
function acceptUsersWithEmail(string $email): int
{
    return DB::table('users')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->count();
}

/**
 * Submits the accept page with the given values.
 *
 * @param  Testable<AcceptInvitation>  $page
 */
function acceptSubmit(Testable $page, string $password, ?string $confirmation = null, string $name = 'Example Person'): Testable
{
    return $page
        ->fillForm(['name' => $name, 'password' => $password, 'passwordConfirmation' => $confirmation ?? $password])
        ->call('accept');
}

it('uses an invitation once: the second submit of the same link is neutral and creates no second user', function (): void {
    [$invitation, $id, $token] = acceptInvite($this->clientA);
    acceptAsGuest();
    $users = acceptUserCount();

    // Two browsers hold the same valid page; the first to submit wins.
    $first = acceptPage($id, $token);
    $second = acceptPage($id, $token);

    acceptSubmit($first, acceptPassword())->assertRedirect(Filament::getLoginUrl());
    acceptSubmit($second, acceptPassword())->assertNoRedirect()->assertSee(__('kokpit.invitations.accept.invalid_message'));

    // The first page itself, submitted again, is neutral too.
    acceptSubmit($first, acceptPassword())->assertSee(__('kokpit.invitations.accept.invalid_message'));

    expect(acceptUserCount())->toBe($users + 1)
        ->and(acceptUsersWithEmail($invitation->email))->toBe(1);
});

it('ends every change between page load and submit in the neutral message and creates no user (Pitfalls 3 and 4)', function (): void {
    $changes = [
        'revoked' => function (ClientInvitation $invitation, string $token): void {
            app(PartnerContext::class)->runAsSystem(fn () => app(RevokeInvitation::class)->handle($invitation));
        },
        'expired' => function (ClientInvitation $invitation, string $token): void {
            test()->travelTo($invitation->expires_at->copy()->addSecond());
        },
        'resent with a new token' => function (ClientInvitation $invitation, string $token): void {
            app(PartnerContext::class)->runAsSystem(fn () => app(ResendInvitation::class)->handle($invitation));
        },
        'client archived' => function (ClientInvitation $invitation, string $token): void {
            app(PartnerContext::class)->runAsSystem(fn () => app(ArchiveClient::class)->handle(Client::query()->findOrFail($invitation->client_id)));
        },
        'e-mail registered meanwhile in another letter case' => function (ClientInvitation $invitation, string $token): void {
            User::factory()->create(['email' => mb_strtoupper($invitation->email)]);
        },
    ];

    $message = __('kokpit.invitations.accept.invalid_message');

    foreach ($changes as $case => $change) {
        [$invitation, $id, $token] = acceptInvite(Client::factory()->create());
        acceptAsGuest();
        $page = acceptPage($id, $token);

        $this->actingAs($this->admin);
        $change($invitation, $token);
        $users = acceptUserCount();
        acceptAsGuest();

        acceptSubmit($page, acceptPassword())
            ->assertNoRedirect()
            ->assertSee($message)
            ->assertDontSee($invitation->email);

        expect(acceptUserCount())->toBe($users, $case)
            ->and(acceptStored($invitation)->accepted_at)->toBeNull($case);

        $this->travelBack();
        $this->actingAs($this->admin);
    }
});

it('answers the same visible text for every invalid case: an unknown link and each submit-time failure', function (): void {
    [$invitation, $id, $token] = acceptInvite($this->clientA);
    $client = $this->clientA;
    acceptAsGuest();

    $texts = [];
    $texts['unknown link'] = acceptVisibleText(acceptPage((string) Str::uuid7(), $token)->html());
    $texts['wrong token'] = acceptVisibleText(acceptPage($id, str_repeat('0', 64))->html());
    $texts['no parameters'] = acceptVisibleText(Livewire::test(AcceptInvitation::class)->html());

    $page = acceptPage($id, $token);
    $this->actingAs($this->admin);
    app(PartnerContext::class)->runAsSystem(fn () => app(RevokeInvitation::class)->handle($invitation));
    acceptAsGuest();
    $texts['revoked after load'] = acceptVisibleText(acceptSubmit($page, acceptPassword())->html());

    expect(array_unique($texts))->toHaveCount(1, 'the neutral text differs: '.implode(' | ', array_keys($texts)));

    $text = reset($texts);

    expect($text)->toContain(__('kokpit.invitations.accept.invalid_message'))
        ->and(str_contains($text, $invitation->email))->toBeFalse()
        ->and(str_contains($text, $client->name))->toBeFalse();
});

it('refuses a password below 12 characters, above 72 bytes or with a wrong confirmation as a field error and keeps the invitation open', function (): void {
    [$invitation, $id, $token] = acceptInvite($this->clientA);
    acceptAsGuest();
    $users = acceptUserCount();
    $tooLongInBytes = str_repeat('ě', 36).'a'; // 37 characters, 73 bytes
    $valid = acceptPassword();

    acceptSubmit(acceptPage($id, $token), substr($valid, 0, 11))->assertHasFormErrors(['password']);
    acceptSubmit(acceptPage($id, $token), $tooLongInBytes)->assertHasFormErrors(['password']);
    acceptSubmit(acceptPage($id, $token), $valid, $valid.'x')->assertHasFormErrors(['password']);
    acceptSubmit(acceptPage($id, $token), $valid, name: '   ')->assertHasFormErrors(['name']);

    expect(mb_strlen($tooLongInBytes))->toBeGreaterThan(12)
        ->and(strlen($tooLongInBytes))->toBe(73)
        ->and(acceptUserCount())->toBe($users)
        ->and(acceptStored($invitation)->accepted_at)->toBeNull();

    // The same invitation still works with a good password afterwards.
    acceptSubmit(acceptPage($id, $token), $valid)->assertRedirect(Filament::getLoginUrl());

    expect(acceptUserCount())->toBe($users + 1);
});

it('applies the password limits in the Action itself, not only in the form', function (): void {
    [$invitation, $id, $token] = acceptInvite($this->clientA);
    acceptAsGuest();
    $users = acceptUserCount();
    $action = app(App\Domain\Clients\Actions\AcceptInvitation::class);

    foreach ([substr(acceptPassword(), 0, 11), str_repeat('ě', 36).'a', '', str_repeat('a', 73)] as $password) {
        expect(fn () => $action->handle($id, $token, 'Example Person', $password))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => $action->handle($id, $token, '   ', acceptPassword()))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($id, $token, str_repeat('n', 256), acceptPassword()))->toThrow(ValidationException::class)
        ->and(acceptUserCount())->toBe($users)
        ->and(acceptStored($invitation)->accepted_at)->toBeNull();

    $user = $action->handle($id, $token, 'Example Person', str_repeat('a', 72));

    expect($user->client_id)->toBe($this->clientA->id);
});

it('throws the neutral exception from the Action for a wrong token, a malformed id and a second use', function (): void {
    [$invitation, $id, $token] = acceptInvite($this->clientA);
    acceptAsGuest();
    $users = acceptUserCount();
    $action = app(App\Domain\Clients\Actions\AcceptInvitation::class);

    expect(fn () => $action->handle($id, str_repeat('0', 64), 'Example Person', acceptPassword()))->toThrow(InvitationNotAcceptable::class)
        ->and(fn () => $action->handle('not-a-uuid', $token, 'Example Person', acceptPassword()))->toThrow(InvitationNotAcceptable::class)
        ->and(fn () => $action->handle((string) Str::uuid7(), $token, 'Example Person', acceptPassword()))->toThrow(InvitationNotAcceptable::class)
        ->and(acceptUserCount())->toBe($users);

    $action->handle($id, $token, 'Example Person', acceptPassword());

    expect(fn () => $action->handle($id, $token, 'Example Person', acceptPassword()))->toThrow(InvitationNotAcceptable::class)
        ->and(acceptUserCount())->toBe($users + 1);
});

it('takes the client of the new account from the locked invitation, never from the input', function (): void {
    [, $idA, $tokenA] = acceptInvite($this->clientA);
    [, $idB, $tokenB] = acceptInvite($this->clientB);
    acceptAsGuest();

    $a = app(App\Domain\Clients\Actions\AcceptInvitation::class)->handle($idA, $tokenA, 'Example A', acceptPassword());
    $b = app(App\Domain\Clients\Actions\AcceptInvitation::class)->handle($idB, $tokenB, 'Example B', acceptPassword());

    expect($a->client_id)->toBe($this->clientA->id)
        ->and($b->client_id)->toBe($this->clientB->id)
        ->and(array_search('client_id', (new User)->getFillable(), true))->toBeFalse()
        ->and(array_search('email_verified_at', (new User)->getFillable(), true))->toBeFalse();
});

it('rate limits the submit of the page and sends the Filament notification', function (): void {
    [$invitation, $id, $token] = acceptInvite($this->clientA);
    acceptAsGuest();
    $users = acceptUserCount();
    $page = acceptPage($id, $token);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        acceptSubmit($page, 'short')->assertHasFormErrors(['password']);
    }

    acceptSubmit($page, acceptPassword())->assertNoRedirect()->assertNotified();

    expect(acceptUserCount())->toBe($users)
        ->and(acceptStored($invitation)->accepted_at)->toBeNull();
});

it('throttles the accept route per IP at ten requests a minute with 429', function (): void {
    [, $id, $token, $url] = acceptInvite($this->clientA);
    acceptAsGuest();

    for ($request = 0; $request < 10; $request++) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertStatus(429);
});

it('does not count an unsigned request against the limiter', function (): void {
    [, $id, $token, $url] = acceptInvite($this->clientA);
    acceptAsGuest();

    for ($request = 0; $request < 12; $request++) {
        $this->get(route(InvitationMail::ACCEPT_ROUTE, ['invitation' => $id, 'token' => $token]))->assertForbidden();
    }

    $this->get($url)->assertOk();
});

it('sends Referrer-Policy no-referrer on every response of the accept route', function (): void {
    [, $id, $token, $url] = acceptInvite($this->clientA);
    $unknown = URL::temporarySignedRoute(InvitationMail::ACCEPT_ROUTE, now()->addDay(), ['invitation' => (string) Str::uuid7(), 'token' => $token]);
    $unsigned = route(InvitationMail::ACCEPT_ROUTE, ['invitation' => $id, 'token' => $token]);
    acceptAsGuest();

    foreach ([$url, $unknown, $unsigned] as $link) {
        expect($this->get($link)->headers->get('Referrer-Policy'))->toBe('no-referrer', $link);
    }

    // The throttled answer carries it as well.
    for ($request = 0; $request < 10; $request++) {
        $this->get($url);
    }

    expect($this->get($url)->assertStatus(429)->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('offers no self-registration: no register route exists', function (): void {
    expect(Route::has('filament.admin.auth.register'))->toBeFalse()
        ->and(Filament::getPanel('admin')->hasRegistration())->toBeFalse();

    acceptAsGuest();

    $this->get('/admin/register')->assertNotFound();
});

it('shows a signed-in visitor a sign-out notice and no form, and leaves the invitation open', function (): void {
    [$invitation, $id, $token, $url] = acceptInvite($this->clientA);
    $users = acceptUserCount();

    // The Admin is still signed in.
    $this->get($url)
        ->assertOk()
        ->assertSee(__('kokpit.invitations.accept.signed_in_message'))
        ->assertDontSee(__('kokpit.invitations.accept.submit'))
        ->assertDontSee(__('kokpit.invitations.accept.fields.password'))
        ->assertDontSee($invitation->email);

    acceptPage($id, $token)
        ->assertSee(__('kokpit.invitations.accept.signed_in_message'))
        ->assertDontSee(__('kokpit.invitations.accept.fields.password'))
        ->call('accept')
        ->assertNoRedirect();

    expect(acceptUserCount())->toBe($users)
        ->and(acceptStored($invitation)->accepted_at)->toBeNull();
});
