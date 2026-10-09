<?php

declare(strict_types=1);

namespace App\Domain\Clients\Rules;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails for an e-mail address that already belongs to a user: the Admin, a
 * Partner of any client, in any letter case (D-03). An invitation never attaches
 * an existing account to a client, so such an address cannot be invited at all.
 *
 * Shared by the InvitePartner Action and the Admin invite form. The lookup is
 * an explicit system run, so it gives the same answer for every caller.
 */
final class EmailHasNoAccount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $email = mb_strtolower(trim($value));

        $exists = app(PartnerContext::class)->runAsSystem(
            static fn (): bool => User::query()->whereRaw('lower(email) = ?', [$email])->exists(),
        );

        if ($exists) {
            $fail(__('kokpit.invitations.errors.email_has_account'));
        }
    }
}
