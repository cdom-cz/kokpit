<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * The public "forgot password" page (US-02, D-04, T-04-47).
 *
 * Filament's own page answers an unknown e-mail with an error and an existing one
 * with a success notice, which tells a visitor which addresses have an account.
 * This page gives every submitted address the same answer: it says a link is sent
 * if the account exists and is active, and then sends one only in that case. A
 * deactivated account, a Partner of an archived client and an unknown address
 * receive nothing. The page rate limit and the broker's timebox and throttle stay
 * as they are; a throttled repeat looks like any other request. The broker runs
 * for every address, so the timing is the same too; the cost is a token row that is
 * never delivered for a deactivated account.
 */
#[AccessRule(Audience::Guest, reason: 'The public forgot-password page: a visitor without an account session asks for a link; it reveals nothing about which addresses have an account.')]
final class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        // The broker status is deliberately ignored: unknown, throttled and sent look the same.
        Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                if (! $user instanceof User || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
                    return;
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                $user->notify($notification);

                event(new PasswordResetLinkSent($user));
            },
        );

        Notification::make()
            ->title(__('kokpit.password_reset.sent'))
            ->body(__('kokpit.password_reset.sent_body'))
            ->success()
            ->send();

        $this->form->fill();
    }
}
