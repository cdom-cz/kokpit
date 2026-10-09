<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Enums\InvitationState;
use App\Domain\Clients\InvitationMail;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Shared\Auth\PartnerContext;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Sends an invitation again with a new link (US-02, D-02).
 *
 * Only a pending or expired invitation can be resent. A new 256-bit token
 * replaces the stored hash, so the old link fails the hash check although its
 * signature is still valid; the expiry moves to now plus the configured TTL, and
 * the send count and time are updated. The invitation row is locked and re-read,
 * so a stale instance cannot resend what was accepted or revoked meanwhile. An
 * archived client gets no new link (the accept step refuses it anyway). The mail
 * is queued after the transaction commits.
 */
final class ResendInvitation
{
    /**
     * @throws DomainException when the invitation is accepted or revoked, or its client is archived
     */
    public function handle(ClientInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $current = ClientInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($current->state(), [InvitationState::Pending, InvitationState::Expired], true)) {
                throw new DomainException(__('kokpit.invitations.errors.not_resendable'));
            }

            $archived = app(PartnerContext::class)->runAsSystem(
                static fn (): bool => Client::query()->onlyTrashed()->whereKey($current->client_id)->exists(),
            );

            if ($archived) {
                throw new DomainException(__('kokpit.invitations.errors.resend_client_archived'));
            }

            $token = bin2hex(random_bytes(32));
            $now = now();

            $current->forceFill([
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->copy()->addDays((int) config('kokpit.invitations.ttl_days')),
                'last_sent_at' => $now,
                'send_count' => $current->send_count + 1,
            ])->save();

            app(InvitationMail::class)->send($current, $token);
        });

        $invitation->refresh();
    }
}
