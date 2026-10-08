<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Identity\Models\User;
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
        $email = mb_strtolower(trim($email));

        Validator::make(
            ['name' => trim($name), 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
            ],
        )->validate();

        return DB::transaction(function () use ($client, $name, $email, $invitedBy): ClientInvitation {
            $token = bin2hex(random_bytes(32));
            $now = now();

            $invitation = new ClientInvitation(['name' => trim($name), 'email' => $email]);
            $invitation->forceFill([
                'client_id' => $client->getKey(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->copy()->addDays((int) config('kokpit.invitations.ttl_days')),
                'invited_by' => $invitedBy?->getKey(),
                'last_sent_at' => $now,
                'send_count' => 1,
            ])->save();

            return $invitation->refresh();
        });
    }
}
