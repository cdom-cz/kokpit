<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use DomainException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Mails a Partner a password reset link on behalf of the Admin (US-02, D-04).
 *
 * It goes through the panel's own password broker, with the same notification and
 * the same signed URL that Filament's "forgot password" page produces, so the
 * link opens the panel's reset page. An account that cannot sign in (deactivated,
 * or a Partner of an archived client) receives nothing and no token is stored: the
 * check runs before the broker and again inside its callback.
 */
final class SendPartnerPasswordReset
{
    /**
     * @throws DomainException when the user is not a Partner, or a reset was already sent a moment ago
     */
    public function handle(User $user): void
    {
        if (! $user->hasRole(RoleName::Partner->value)) {
            throw new DomainException(__('kokpit.partner_accounts.errors.not_a_partner'));
        }

        $panel = Filament::getPanel('admin');

        if (! $user->canAccessPanel($panel)) {
            return;
        }

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            ['email' => $user->email],
            function (CanResetPassword $recipient, #[SensitiveParameter] string $token) use ($panel): void {
                if (! $recipient instanceof User || ! $recipient->canAccessPanel($panel)) {
                    return;
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $recipient);

                $recipient->notify($notification);

                event(new PasswordResetLinkSent($recipient));
            },
        );

        if ($status === Password::RESET_THROTTLED) {
            throw new DomainException(__('kokpit.partner_accounts.errors.reset_throttled'));
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw new DomainException(__('kokpit.partner_accounts.errors.reset_failed'));
        }
    }
}
