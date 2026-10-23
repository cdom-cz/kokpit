<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password reset e-mail with Kokpit's own texts (D-07, R-13-07-3).
 *
 * Filament's reset notification phrases the mail with the framework sentences of the laravel-lang file. This subclass
 * overrides only `buildMailMessage()`, so the e-mail carries Kokpit's reviewed Czech copy on the shared layout. What it
 * deliberately keeps from Filament: it stays queued (`ShouldQueue`), it keeps the public `$url` that the two call sites
 * set from `Filament::getResetPasswordUrl()`, and `resetUrl()` still returns it. The broker, its throttle and timebox,
 * and the rule that a deactivated account receives nothing live in the call sites and are untouched.
 *
 * The expiry is read from the config of the default password broker (the one the panel uses, because it names none),
 * never from the current panel: a queued worker has no panel. No greeting is set, so the layout's default applies, and
 * no salutation, because the supplier footer closes the e-mail.
 */
final class ResetPasswordNotification extends ResetPassword
{
    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('kokpit.password_reset.mail.subject'))
            ->line(__('kokpit.password_reset.mail.intro'))
            ->action(__('kokpit.password_reset.mail.action'), $url)
            ->line(trans_choice('kokpit.password_reset.mail.expiry', $minutes, ['count' => $minutes]))
            ->line(__('kokpit.password_reset.mail.outro'));
    }
}
