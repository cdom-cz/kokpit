<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Rules\EmailHasNoAccount;
use App\Domain\Clients\Rules\EmailHasNoOpenInvitation;
use App\Domain\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Issues the invitation of a person to become a Partner account of a client
 * (US-02, D-01 to D-03).
 *
 * Only an invitation row is written; no users row exists until the invited
 * person sets a password through the link. The token is 256 random bits and only
 * its SHA-256 hash is stored (SHA-256 is right for a high-entropy token, a slow
 * password hash would add nothing). The plain token is not returned or logged
 * here; the delivery of the signed link is added by the invitation e-mail.
 *
 * The e-mail is trimmed and lower-cased, as the database check requires.
 */
final class InvitePartner
{
    /**
     * @throws ValidationException
     */
    public function handle(Client $client, string $name, string $email, ?User $invitedBy): ClientInvitation
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($client, $name, $email, $invitedBy): ClientInvitation {
            // The client row is locked and re-read, so an archive committed after this
            // instance was loaded still refuses the invitation.
            $current = Client::query()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            if ($current->trashed()) {
                throw ValidationException::withMessages(['client' => __('kokpit.invitations.errors.client_archived')]);
            }

            Validator::make(
                ['name' => $name, 'email' => $email],
                [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255', new EmailHasNoAccount, new EmailHasNoOpenInvitation],
                ],
            )->validate();

            $token = bin2hex(random_bytes(32));
            $now = now();

            $invitation = new ClientInvitation(['name' => $name, 'email' => $email]);
            $invitation->forceFill([
                'client_id' => $current->getKey(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->copy()->addDays((int) config('kokpit.invitations.ttl_days')),
                'invited_by' => $invitedBy?->getKey(),
                'last_sent_at' => $now,
                'send_count' => 1,
            ]);

            try {
                // A savepoint, so a lost race on the open-email index does not abort the outer transaction.
                DB::transaction(static fn (): bool => $invitation->save());
            } catch (UniqueConstraintViolationException) {
                // Two invitations for one e-mail at the same moment: the index decided.
                throw ValidationException::withMessages(['email' => __('kokpit.invitations.errors.email_has_open_invitation')]);
            }

            return $invitation->refresh();
        });
    }
}
