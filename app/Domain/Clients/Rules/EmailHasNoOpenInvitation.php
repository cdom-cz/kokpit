<?php

declare(strict_types=1);

namespace App\Domain\Clients\Rules;

use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Shared\Auth\PartnerContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails for an e-mail address that has an open invitation: one that is neither
 * accepted nor revoked, whether it is still pending or already expired, for any
 * client. The person has at most one open invitation, so the Admin resends it
 * instead. The partial unique index client_invitations_open_email_unique backs
 * this rule in the database.
 *
 * Shared by the InvitePartner Action and the Admin invite form. The lookup is
 * an explicit system run, so a Partner scope can never hide an invitation.
 */
final class EmailHasNoOpenInvitation implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $email = mb_strtolower(trim($value));

        $open = app(PartnerContext::class)->runAsSystem(
            static fn (): bool => ClientInvitation::query()
                ->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->exists(),
        );

        if ($open) {
            $fail(__('kokpit.invitations.errors.email_has_open_invitation'));
        }
    }
}
