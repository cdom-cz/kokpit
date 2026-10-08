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
