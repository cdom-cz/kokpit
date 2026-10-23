<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Mail;

use Illuminate\Mail\Events\MessageSending;

/**
 * Puts the supplier logo into an outgoing message as one inline part (R-13-04-1, research Pattern 2).
 *
 * The layout header references the logo as `cid:kokpit-logo.png` only when `MailBrand::hasLogo()` is true. This
 * listener runs just before the transport, once the HTML body is built, and embeds the same memoised bytes under that
 * name, so Symfony wraps the message in `multipart/related`. It embeds only when the HTML body really holds the
 * reference, so a text-only message, or one built without the shared layout, gets no stray attachment, and an
 * e-mail never carries a cid nobody points to or a cid with no part (research Pitfalls 2 and 3).
 *
 * It returns nothing: a `false` returned from a MessageSending listener cancels the send.
 */
final class EmbedMailLogo
{
    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        $html = $message->getHtmlBody();
        $brand = MailBrand::current();

        if (! is_string($html) || ! $brand->hasLogo() || $brand->logoPng === null
            || ! str_contains($html, 'cid:'.MailBrand::LOGO_NAME)) {
            return;
        }

        $message->embed($brand->logoPng, MailBrand::LOGO_NAME, 'image/png');
    }
}
