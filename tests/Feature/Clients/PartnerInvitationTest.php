<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
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
