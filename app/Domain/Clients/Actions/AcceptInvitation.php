<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\InvitationNotAcceptable;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Turns an invitation into a Partner account, exactly once (US-02, D-01).
 *
 * This is the one sanctioned guest system run that creates a user. A guest has no
 * user and every scope is fail-closed, so the whole step is an explicit system run
 * inside one transaction. The Action never trusts the page: the invitation row is
 * locked and re-read, and the token, the expiry, the open state and the existence
 * of the client are checked again on that locked row. The `client_id` of the new
 * user comes from the locked row, never from input.
 *
 * The password follows the install command: at least 12 characters and at most 72
 * bytes (bcrypt ignores the rest). Name and password problems are field errors
 * (ValidationException) and leave the invitation open. Every problem of the
 * invitation side, including an e-mail address that got an account meanwhile,
 * ends in the one neutral InvitationNotAcceptable and creates no user.
 */
final class AcceptInvitation
{
    /** bcrypt silently ignores every byte beyond this length. */
    public const int PASSWORD_MAX_BYTES = 72;

    /**
     * @throws ValidationException when the name or the password is not acceptable
     * @throws InvitationNotAcceptable when the invitation can no longer be used
     */
    public function handle(string $invitationId, string $plainToken, string $name, string $password): User
    {
        $name = trim($name);

        Validator::make(
            ['name' => $name, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'password' => [
                    'required',
                    'string',
                    Password::min(12),
                    static function (string $attribute, mixed $value, Closure $fail): void {
                        if (strlen((string) $value) > self::PASSWORD_MAX_BYTES) {
                            $fail(__('kokpit.invitations.accept.password_too_long'));
                        }
                    },
                ],
            ],
            [],
            (array) __('kokpit.invitations.accept.attributes'),
        )->validate();

        // A malformed id never reaches PostgreSQL (a uuid comparison would throw).
        if (! Str::isUuid($invitationId)) {
            throw new InvitationNotAcceptable;
        }

        try {
            return app(PartnerContext::class)->runAsSystem(
                fn (): User => DB::transaction(fn (): User => $this->accept($invitationId, $plainToken, $name, $password)),
            );
        } catch (UniqueConstraintViolationException) {
            // An account with this e-mail appeared between the check and the insert; the
            // transaction is rolled back and the answer is the neutral one.
            throw new InvitationNotAcceptable;
        }
    }

    private function accept(string $invitationId, string $plainToken, string $name, string $password): User
    {
        $invitation = ClientInvitation::query()->whereKey($invitationId)->lockForUpdate()->first();

        if ($invitation === null || ! $invitation->isAcceptableWith($plainToken)) {
            throw new InvitationNotAcceptable;
        }

        // The client must exist and not be archived (the soft-delete scope hides an archived one).
        // The share lock keeps an archive from committing between this check and the insert.
        $client = Client::query()->whereKey($invitation->client_id)->sharedLock()->first();

        if ($client === null) {
            throw new InvitationNotAcceptable;
        }

        if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->exists()) {
            throw new InvitationNotAcceptable;
        }

        $user = new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
        $user->forceFill([
            'client_id' => $client->getKey(),
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole(RoleName::Partner->value);

        $invitation->forceFill([
            'accepted_at' => now(),
            'accepted_user_id' => $user->getKey(),
        ])->save();

        return $user;
    }
}
