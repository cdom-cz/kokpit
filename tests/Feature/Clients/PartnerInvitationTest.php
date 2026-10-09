<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Actions\ResendInvitation;
use App\Domain\Clients\Actions\RevokeInvitation;
use App\Domain\Clients\Enums\InvitationState;
use App\Domain\Clients\InvitationMail;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * Partner invitations (US-02, D-01 to D-03): the record, as the Admin issues it.
 * Every name and e-mail address is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->client = Client::factory()->create();
});

/**
 * Invites a fictional person for the client of the test and returns the invitation.
 */
function invite(string $email, ?Client $client = null, string $name = 'Example Invitee'): ClientInvitation
{
    return app(InvitePartner::class)->handle($client ?? test()->client, $name, $email, test()->admin);
}

/**
 * The message of the validation error on the e-mail field, or null when the call passes.
 */
function emailErrorOf(string $email, ?Client $client = null): ?string
{
    try {
        invite($email, $client);
    } catch (ValidationException $e) {
        return $e->errors()['email'][0] ?? 'no email error: '.json_encode($e->errors());
    }

    return null;
}

/**
 * The number of invitation rows, read as a system run so no scope hides any.
 */
function invitationCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => ClientInvitation::query()->count());
}

it('stores one hashed, expiring invitation and creates no user (D-01, D-02)', function (): void {
    $users = User::query()->count();
    $email = Str::upper(exampleEmail());

    $invitation = invite($email);
    $row = DB::table('client_invitations')->first();

    expect(invitationCount())->toBe(1)
        ->and(User::query()->count())->toBe($users)
        ->and($row->client_id)->toBe($this->client->id)
        ->and($row->email)->toBe(Str::lower($email))
        ->and($row->token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->send_count)->toBe(1)
        ->and($row->invited_by)->toBe($this->admin->id)
        ->and($row->accepted_at)->toBeNull()
        ->and($row->revoked_at)->toBeNull()
        ->and($invitation->expires_at->equalTo($invitation->last_sent_at->copy()->addDays(7)))->toBeTrue()
        ->and($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue();
});

it('takes the expiry from the configured number of days', function (): void {
    config(['kokpit.invitations.ttl_days' => 3]);

    $invitation = invite(exampleEmail());

    expect($invitation->expires_at->equalTo($invitation->last_sent_at->copy()->addDays(3)))->toBeTrue();
});

it('keeps the token hash out of the serialised model and stores no other 64-character value', function (): void {
    $invitation = invite(exampleEmail());

    expect($invitation->toArray())->not->toHaveKey('token_hash')
        ->and($invitation->toJson())->not->toContain($invitation->token_hash);

    $hashes = collect((array) DB::table('client_invitations')->first())
        ->filter(static fn (mixed $value): bool => is_string($value) && strlen($value) === 64);

    expect($hashes->keys()->all())->toBe(['token_hash']);
});

it('gives every invitation its own token hash and never logs the invitation in the activity log', function (): void {
    $first = invite(exampleEmail());
    $second = invite(exampleEmail());

    expect($first->token_hash)->not->toBe($second->token_hash)
        ->and(DB::table('activity_log')->where('subject_type', 'client_invitation')->count())->toBe(0)
        ->and(str_contains((string) json_encode(DB::table('activity_log')->get()), $first->token_hash))->toBeFalse();
});

it('shows Partner A zero invitations, whichever client they belong to', function (): void {
    $other = Client::factory()->create();
    invite(exampleEmail());
    invite(exampleEmail(), $other);

    $partner = Canary::partnerFor($this->client->id);
    $this->actingAs($partner);

    expect(ClientInvitation::query()->count())->toBe(0)
        ->and($partner->can('viewAny', ClientInvitation::class))->toBeFalse();
});

it('refuses a database row whose e-mail address is not lower case', function (): void {
    $invitation = invite(exampleEmail());

    RawSql::expectSqlState('23514', static function () use ($invitation): void {
        DB::table('client_invitations')->where('id', $invitation->id)->update(['email' => 'MIXED@'.implode('.', ['example', 'com'])]);
    });
});

it('refuses an e-mail that already belongs to the Admin or to a Partner of any client, in any letter case (D-03)', function (): void {
    $otherClient = Client::factory()->create();
    $partnerOfOther = Canary::partnerFor($otherClient->id);
    $partnerOfSame = Canary::partnerFor($this->client->id);

    foreach ([$this->admin, $partnerOfOther, $partnerOfSame] as $user) {
        foreach ([$user->email, Str::upper($user->email), '  '.Str::ucfirst($user->email).' '] as $email) {
            expect(emailErrorOf($email))->toBe(__('kokpit.invitations.errors.email_has_account'), $email);
        }
    }

    expect(invitationCount())->toBe(0);
});

it('never re-links an existing account to another client through an invitation (D-03)', function (): void {
    $otherClient = Client::factory()->create();
    $partner = Canary::partnerFor($otherClient->id);

    expect(emailErrorOf($partner->email))->not->toBeNull()
        ->and($partner->fresh()?->client_id)->toBe($otherClient->id)
        ->and(invitationCount())->toBe(0);
});

it('refuses an e-mail with an open invitation, pending or expired, for any client, with the resend hint', function (): void {
    $other = Client::factory()->create();
    $pending = invite(exampleEmail());
    $expired = invite(exampleEmail(), $other);
    app(PartnerContext::class)->runAsSystem(static fn () => $expired->forceFill(['expires_at' => now()->subDay()])->save());

    foreach ([$pending, $expired] as $invitation) {
        expect(emailErrorOf($invitation->email))->toBe(__('kokpit.invitations.errors.email_has_open_invitation'))
            ->and(emailErrorOf(Str::upper($invitation->email), $other))->toBe(__('kokpit.invitations.errors.email_has_open_invitation'));
    }

    expect(invitationCount())->toBe(2);
});

it('invites an e-mail again once its earlier invitation is revoked or accepted', function (): void {
    $revoked = invite(exampleEmail());
    app(PartnerContext::class)->runAsSystem(static fn () => $revoked->forceFill(['revoked_at' => now()])->save());

    $accepted = invite(exampleEmail());
    $user = Canary::partnerFor($this->client->id);
    app(PartnerContext::class)->runAsSystem(static fn () => $accepted->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save());
    // The accepted person now has an account, so the invitation is refused by the account rule instead.
    DB::table('users')->where('id', $user->id)->update(['email' => exampleEmail()]);

    expect(emailErrorOf($revoked->email))->toBeNull()
        ->and(emailErrorOf($accepted->email))->toBeNull()
        ->and(invitationCount())->toBe(4);
});

it('refuses a second open invitation for one e-mail in the database with a unique violation', function (): void {
    $first = invite(exampleEmail());

    RawSql::expectSqlState('23505', static function () use ($first): void {
        $row = (array) DB::table('client_invitations')->where('id', $first->id)->first();
        $row['id'] = (string) Str::uuid7();
        $row['token_hash'] = hash('sha256', bin2hex(random_bytes(32)));

        DB::table('client_invitations')->insert($row);
    });
});

it('refuses to invite for an archived client and stores nothing', function (): void {
    $archived = Client::factory()->create();
    $archived->delete();

    try {
        invite(exampleEmail(), $archived);
        $thrown = null;
    } catch (ValidationException $e) {
        $thrown = $e->errors();
    }

    expect($thrown)->toHaveKey('client')
        ->and($thrown['client'][0])->toBe(__('kokpit.invitations.errors.client_archived'))
        ->and(invitationCount())->toBe(0);
});

it('refuses an archived client even when the instance in hand was loaded before the archive', function (): void {
    $stale = Client::factory()->create();
    Client::query()->whereKey($stale->id)->first()?->delete();

    expect(fn () => invite(exampleEmail(), $stale))->toThrow(ValidationException::class)
        ->and(invitationCount())->toBe(0);
});

it('refuses a missing name and a malformed e-mail on the matching field', function (): void {
    expect(fn () => app(InvitePartner::class)->handle($this->client, '  ', exampleEmail(), $this->admin))
        ->toThrow(ValidationException::class);

    try {
        invite('not-an-email');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('email');
    }

    expect(invitationCount())->toBe(0);
});

/**
 * The signed links mailed to one address so far, oldest first. Asserts that at least
 * one invitation was sent on demand to that address.
 *
 * @return list<string>
 */
function sentLinks(string $email): array
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

/**
 * The query parameters of a signed link.
 *
 * @return array<string, string>
 */
function linkQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return array_map('strval', $query);
}

/**
 * A freshly signed link for the given invitation id and token, valid for a day.
 */
function signedLink(?string $id, ?string $token): string
{
    return URL::temporarySignedRoute(InvitationMail::ACCEPT_ROUTE, now()->addDay(), array_filter(
        ['invitation' => $id, 'token' => $token],
        static fn (?string $value): bool => $value !== null,
    ));
}

/**
 * Forgets the signed-in user: the link is opened by a guest.
 */
function asGuest(): void
{
    auth()->forgetGuards();
}

/**
 * The visible text of a response body: tags, scripts and attributes removed.
 */
function visibleText(string $html): string
{
    $html = (string) preg_replace('~<(script|style)\b.*?</\1>~si', ' ', $html);

    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
}

/**
 * Invites one fictional person with the mail faked and returns [invitation, plain token, url].
 *
 * @return array{0: ClientInvitation, 1: string, 2: string}
 */
function inviteWithLink(?string $email = null): array
{
    Notification::fake();
    $invitation = invite($email ?? exampleEmail());
    $url = sentLinks($invitation->email)[0];

    return [$invitation, linkQuery($url)['token'], $url];
}

it('queues one on-demand mail with a signed link to the accept route (D-01, D-02)', function (): void {
    Notification::fake();

    $invitation = invite(exampleEmail());
    $clientName = $this->client->name;

    Notification::assertSentOnDemandTimes(PartnerInvitation::class, 1);
    Notification::assertSentOnDemand(
        PartnerInvitation::class,
        static function (PartnerInvitation $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation, $clientName): bool {
            $request = Request::create($notification->acceptUrl);

            expect($notification)->toBeInstanceOf(ShouldQueue::class)
                ->and($notification->afterCommit)->toBeTrue()
                ->and($channels)->toBe(['mail'])
                ->and($notifiable->routes['mail'])->toBe($invitation->email)
                ->and($notification->inviteeName)->toBe('Example Invitee')
                ->and($notification->clientName)->toBe($clientName)
                ->and(URL::hasValidSignature($request))->toBeTrue()
                ->and(Route::getRoutes()->match($request)->getName())->toBe(InvitationMail::ACCEPT_ROUTE)
                ->and(linkQuery($notification->acceptUrl))->toHaveKeys(['invitation', 'token', 'expires', 'signature'])
                ->and(linkQuery($notification->acceptUrl)['invitation'])->toBe($invitation->id)
                ->and((int) linkQuery($notification->acceptUrl)['expires'])->toBe($invitation->expires_at->getTimestamp());

            return true;
        },
    );
});

it('mails the token of which only the hash is stored, and the token is in no column', function (): void {
    [$invitation, $token, $url] = inviteWithLink();
    $row = DB::table('client_invitations')->where('id', $invitation->id)->first();

    expect($token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and(str_contains((string) json_encode($row), $token))->toBeFalse()
        ->and($url)->toContain($token);
});

it('builds the mail from scalars only, in Czech', function (): void {
    [$invitation, , $url] = inviteWithLink();

    $notification = new PartnerInvitation('Example Invitee', 'Example Client', $url, '1. 1. 2030 12:00');
    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe(__('kokpit.invitations.mail.subject'))
        ->and($mail->greeting)->toBe('Dobrý den, Example Invitee,')
        ->and($mail->actionUrl)->toBe($url)
        ->and($mail->actionText)->toBe('Přijmout pozvánku')
        ->and(implode(' ', $mail->introLines))->toContain('Example Client')
        ->and(implode(' ', array_map(strval(...), $mail->outroLines)))->toContain('1. 1. 2030 12:00');

    foreach ((new ReflectionClass(PartnerInvitation::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        expect((string) $parameter->getType())->toBe('string');
    }
});

it('opens a valid link as a guest and shows the e-mail of the invitee', function (): void {
    [$invitation, , $url] = inviteWithLink();
    asGuest();

    $this->get($url)
        ->assertOk()
        ->assertSee($invitation->email)
        ->assertSee(__('kokpit.invitations.accept.heading'))
        ->assertDontSee(__('kokpit.invitations.accept.invalid_message'));
});

it('answers every bad link with one identical neutral message and the same status (D-02)', function (): void {
    [$invitation, $token, $url] = inviteWithLink();
    [$other, $otherToken] = inviteWithLink();
    $message = __('kokpit.invitations.accept.invalid_message');

    $links = [
        'wrong token' => signedLink($invitation->id, str_repeat('0', 64)),
        'token of another invitation' => signedLink($invitation->id, $otherToken),
        'unknown id' => signedLink((string) Str::uuid7(), $token),
        'not a uuid' => signedLink('not-a-uuid', $token),
        'sql-looking id' => signedLink("1' OR '1'='1", $token),
        'no parameters' => signedLink(null, null),
        'no token' => signedLink($invitation->id, null),
        'no id' => signedLink(null, $token),
    ];

    $revoked = invite(exampleEmail());
    $revokedToken = linkQuery(sentLinks($revoked->email)[0])['token'];
    app(PartnerContext::class)->runAsSystem(static fn () => $revoked->forceFill(['revoked_at' => now()])->save());
    $links['revoked'] = signedLink($revoked->id, $revokedToken);

    $accepted = invite(exampleEmail());
    $acceptedToken = linkQuery(sentLinks($accepted->email)[0])['token'];
    $user = Canary::partnerFor($this->client->id);
    app(PartnerContext::class)->runAsSystem(static fn () => $accepted->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save());
    $links['accepted'] = signedLink($accepted->id, $acceptedToken);

    $expired = invite(exampleEmail());
    $expiredToken = linkQuery(sentLinks($expired->email)[0])['token'];
    app(PartnerContext::class)->runAsSystem(static fn () => $expired->forceFill(['expires_at' => now()->subSecond()])->save());
    $links['expired'] = signedLink($expired->id, $expiredToken);

    asGuest();
    $texts = [];

    foreach ($links as $case => $link) {
        // More than ten requests from one IP are throttled (plan 04-18); this test is about the answers.
        Cache::flush();
        $response = $this->get($link);
        $response->assertOk()->assertSee($message);

        foreach ([$invitation, $other, $revoked, $accepted, $expired] as $known) {
            $response->assertDontSee($known->email);
        }

        $texts[$case] = visibleText((string) $response->getContent());
    }

    expect(array_unique($texts))->toHaveCount(1, 'the neutral page differs between '.implode(', ', array_keys($links)));
});

it('shows the neutral page for an invitation at exactly its expiry time and later', function (): void {
    [$invitation, $token] = inviteWithLink();
    asGuest();

    // A link signed afresh at each moment, so only the invitation decides, not the signature.
    $this->get(signedLink($invitation->id, $token))->assertOk()->assertSee($invitation->email);

    $this->travelTo($invitation->expires_at->copy()->subSecond());
    $this->get(signedLink($invitation->id, $token))->assertOk()->assertSee($invitation->email);

    $this->travelTo($invitation->expires_at);
    $this->get(signedLink($invitation->id, $token))->assertOk()->assertSee(__('kokpit.invitations.accept.invalid_message'))->assertDontSee($invitation->email);

    $this->travelTo($invitation->expires_at->copy()->addMinute());
    $this->get(signedLink($invitation->id, $token))->assertOk()->assertSee(__('kokpit.invitations.accept.invalid_message'));
});

it('refuses the mailed link itself once its signature has expired', function (): void {
    [$invitation, , $url] = inviteWithLink();
    asGuest();

    $this->travelTo($invitation->expires_at->copy()->addSecond());

    $this->get($url)->assertForbidden();
});

it('refuses a link without a signature or with a changed query with 403', function (): void {
    [$invitation, $token, $url] = inviteWithLink();
    asGuest();

    $this->get(route(InvitationMail::ACCEPT_ROUTE, ['invitation' => $invitation->id, 'token' => $token]))->assertForbidden();
    $this->get(str_replace($token, str_repeat('a', 64), $url))->assertForbidden();
    $this->get(str_replace($invitation->id, (string) Str::uuid7(), $url))->assertForbidden();
});

it('reads the invitation only through findAcceptable, which hides every unacceptable case as null', function (): void {
    [$invitation, $token] = inviteWithLink();
    asGuest();

    expect(ClientInvitation::findAcceptable($invitation->id, $token)?->id)->toBe($invitation->id)
        ->and(ClientInvitation::findAcceptable($invitation->id, 'wrong'))->toBeNull()
        ->and(ClientInvitation::findAcceptable($invitation->id, ''))->toBeNull()
        ->and(ClientInvitation::findAcceptable((string) Str::uuid7(), $token))->toBeNull()
        ->and(ClientInvitation::findAcceptable('not-a-uuid', $token))->toBeNull()
        ->and(ClientInvitation::findAcceptable('', $token))->toBeNull();
});

/**
 * Changes columns of an invitation the way a lifecycle step would, as a system run.
 *
 * @param  array<string, mixed>  $attributes
 */
function forceInvitation(ClientInvitation $invitation, array $attributes): void
{
    app(PartnerContext::class)->runAsSystem(static fn () => $invitation->forceFill($attributes)->save());
}

/**
 * The stored lifecycle columns of an invitation, read raw.
 *
 * @return array<string, mixed>
 */
function storedInvitation(ClientInvitation $invitation): array
{
    return (array) DB::table('client_invitations')->where('id', $invitation->id)->first();
}

it('derives the state from the timestamps and the current time, never from a stored column', function (): void {
    $invitation = invite(exampleEmail());

    expect($invitation->state())->toBe(InvitationState::Pending)
        ->and(array_key_exists('state', storedInvitation($invitation)))->toBeFalse();

    $this->travelTo($invitation->expires_at->copy()->subSecond());
    expect($invitation->state())->toBe(InvitationState::Pending);

    $this->travelTo($invitation->expires_at);
    expect($invitation->state())->toBe(InvitationState::Expired);

    $this->travelTo($invitation->expires_at->copy()->addDay());
    expect($invitation->state())->toBe(InvitationState::Expired);

    $this->travelBack();
    $revoked = invite(exampleEmail());
    forceInvitation($revoked, ['revoked_at' => now()]);
    $accepted = invite(exampleEmail());
    forceInvitation($accepted, ['accepted_at' => now(), 'accepted_user_id' => Canary::partnerFor($this->client->id)->id]);

    // A revoked or accepted invitation keeps its state after its expiry date has passed.
    $this->travelTo(now()->addDays(30));
    expect($revoked->state())->toBe(InvitationState::Revoked)
        ->and($accepted->state())->toBe(InvitationState::Accepted);
});

it('labels the four states in Czech', function (): void {
    expect(InvitationState::Pending->getLabel())->toBe('Čeká')
        ->and(InvitationState::Accepted->getLabel())->toBe('Přijata')
        ->and(InvitationState::Revoked->getLabel())->toBe('Zrušena')
        ->and(InvitationState::Expired->getLabel())->toBe('Vypršela')
        ->and(InvitationState::cases())->toHaveCount(4);
});

it('resends a pending invitation with a new token, a new expiry and a new mail', function (): void {
    [$invitation, $oldToken] = inviteWithLink();
    $oldHash = $invitation->token_hash;

    $this->travelTo(now()->addDays(3));
    app(ResendInvitation::class)->handle($invitation);

    $row = storedInvitation($invitation);
    $urls = sentLinks($invitation->email);
    $newToken = linkQuery($urls[1])['token'];

    expect($urls)->toHaveCount(2)
        ->and($newToken)->not->toBe($oldToken)
        ->and($row['token_hash'])->toBe(hash('sha256', $newToken))
        ->and($row['token_hash'])->not->toBe($oldHash)
        ->and($row['send_count'])->toBe(2)
        ->and(Carbon::parse($row['expires_at'])->getTimestamp())->toBe(now()->addDays(7)->getTimestamp())
        ->and(Carbon::parse($row['last_sent_at'])->getTimestamp())->toBe(now()->getTimestamp())
        ->and($invitation->send_count)->toBe(2)
        ->and($invitation->state())->toBe(InvitationState::Pending)
        ->and((int) linkQuery($urls[1])['expires'])->toBe(now()->addDays(7)->getTimestamp());

    asGuest();
    expect(ClientInvitation::findAcceptable($invitation->id, $oldToken))->toBeNull()
        ->and(ClientInvitation::findAcceptable($invitation->id, $newToken)?->id)->toBe($invitation->id);
});

it('shows the neutral message for the old link after a resend although its signature is still valid', function (): void {
    [$invitation, , $oldUrl] = inviteWithLink();

    app(ResendInvitation::class)->handle($invitation);
    $newUrl = sentLinks($invitation->email)[1];
    asGuest();

    $this->get($oldUrl)->assertOk()->assertSee(__('kokpit.invitations.accept.invalid_message'))->assertDontSee($invitation->email);
    $this->get($newUrl)->assertOk()->assertSee($invitation->email);
});

it('makes an expired invitation pending again when it is resent', function (): void {
    [$invitation] = inviteWithLink();
    forceInvitation($invitation, ['expires_at' => now()->subHour()]);
    expect($invitation->state())->toBe(InvitationState::Expired);

    app(ResendInvitation::class)->handle($invitation);

    $newToken = linkQuery(sentLinks($invitation->email)[1])['token'];
    asGuest();

    expect($invitation->state())->toBe(InvitationState::Pending)
        ->and($invitation->send_count)->toBe(2)
        ->and(ClientInvitation::findAcceptable($invitation->id, $newToken)?->id)->toBe($invitation->id);
});

it('revokes a pending or expired invitation and its link becomes invalid', function (): void {
    [$pending, $pendingToken] = inviteWithLink();
    [$expired, $expiredToken] = inviteWithLink();
    forceInvitation($expired, ['expires_at' => now()->subHour()]);

    app(RevokeInvitation::class)->handle($pending);
    app(RevokeInvitation::class)->handle($expired);

    expect($pending->state())->toBe(InvitationState::Revoked)
        ->and($expired->state())->toBe(InvitationState::Revoked)
        ->and(storedInvitation($pending)['revoked_at'])->not->toBeNull();

    asGuest();
    expect(ClientInvitation::findAcceptable($pending->id, $pendingToken))->toBeNull()
        ->and(ClientInvitation::findAcceptable($expired->id, $expiredToken))->toBeNull();

    $this->get(signedLink($pending->id, $pendingToken))->assertOk()->assertSee(__('kokpit.invitations.accept.invalid_message'));
});

it('refuses to resend or revoke an accepted or a revoked invitation and changes nothing', function (): void {
    $accepted = invite(exampleEmail());
    forceInvitation($accepted, ['accepted_at' => now(), 'accepted_user_id' => Canary::partnerFor($this->client->id)->id]);
    $revoked = invite(exampleEmail());
    forceInvitation($revoked, ['revoked_at' => now()]);

    Notification::fake();

    foreach ([$accepted, $revoked] as $invitation) {
        $before = storedInvitation($invitation);

        expect(fn () => app(ResendInvitation::class)->handle($invitation))->toThrow(DomainException::class, __('kokpit.invitations.errors.not_resendable'))
            ->and(fn () => app(RevokeInvitation::class)->handle($invitation))->toThrow(DomainException::class, __('kokpit.invitations.errors.not_revocable'))
            ->and(storedInvitation($invitation))->toBe($before);
    }

    Notification::assertNothingSent();
});

it('decides on the locked row, so a stale instance cannot resend or revoke what was accepted meanwhile', function (): void {
    $invitation = invite(exampleEmail());
    $stale = ClientInvitation::query()->whereKey($invitation->id)->firstOrFail();
    forceInvitation($invitation, ['revoked_at' => now()]);

    expect($stale->state())->toBe(InvitationState::Pending)
        ->and(fn () => app(ResendInvitation::class)->handle($stale))->toThrow(DomainException::class)
        ->and(fn () => app(RevokeInvitation::class)->handle($stale))->toThrow(DomainException::class);
});

it('locks the invitation row inside a transaction for both lifecycle steps', function (): void {
    foreach ([ResendInvitation::class, RevokeInvitation::class] as $action) {
        $source = (string) file_get_contents((string) (new ReflectionClass($action))->getFileName());

        expect($source)->toContain('lockForUpdate')->and($source)->toContain('DB::transaction');
    }
});
