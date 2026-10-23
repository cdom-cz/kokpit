<?php

declare(strict_types=1);

namespace App\Domain\Clients\Notifications;

use App\Domain\Notifications\Mail\MailBrand;
use App\Domain\Notifications\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail that invites a person to become the Partner account of a client
 * (US-02, D-01, D-02).
 *
 * Queued, and queued only after the surrounding transaction commits, so a rolled
 * back invitation never mails a link. The constructor takes scalars only: a
 * queued notification does not run as system, and a serialised model would be
 * re-fetched under a fail-closed scope. It is sent to an on-demand address,
 * because no user exists yet.
 *
 * It renders on the shared e-mail layout (D-07). The invitee name and the client name are typed by the Admin, so both
 * pass through MailText::escapeMarkdown() before they enter a Markdown line or the greeting: they show exactly as typed
 * and can not build a link, image or emphasis (R-13-07-1). The subject is plain text and names the supplier. The
 * sign-off is "S pozdravem" and the supplier name on a second line, set here because the layout adds none of its own.
 */
final class PartnerInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $inviteeName,
        public readonly string $clientName,
        public readonly string $acceptUrl,
        public readonly string $expiresOn,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $supplier = MailBrand::current()->name;

        return (new MailMessage)
            ->subject(__('kokpit.invitations.mail.subject', ['supplier' => $supplier]))
            ->greeting(__('kokpit.invitations.mail.greeting', ['name' => MailText::escapeMarkdown($this->inviteeName)]))
            ->line(__('kokpit.invitations.mail.intro', ['client' => MailText::escapeMarkdown($this->clientName)]))
            ->action(__('kokpit.invitations.mail.action'), $this->acceptUrl)
            ->line(__('kokpit.invitations.mail.expiry', ['date' => $this->expiresOn]))
            ->line(__('kokpit.invitations.mail.outro'))
            ->salutation(__('mail_layout.regards')."\n".MailText::escapeMarkdown($supplier));
    }
}
