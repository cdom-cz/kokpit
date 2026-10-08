<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Enums\InvitationState;
use App\Domain\Clients\Models\ClientInvitation;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an invitation (US-02, D-02): its link becomes invalid at once.
 *
 * Only a pending or expired invitation can be revoked; an accepted or an already
 * revoked one is refused. The invitation row is locked and re-read, so a stale
 * instance cannot revoke what was accepted meanwhile.
 */
final class RevokeInvitation
{
    /**
     * @throws DomainException when the invitation is accepted or already revoked
     */
    public function handle(ClientInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $current = ClientInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($current->state(), [InvitationState::Pending, InvitationState::Expired], true)) {
                throw new DomainException(__('kokpit.invitations.errors.not_revocable'));
            }

            $current->forceFill(['revoked_at' => now()])->save();
        });

        $invitation->refresh();
    }
}
