<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Readers for the messages the array mail transport collected in a test (the transport the whole suite sends through).
 */
final class MailFixtures
{
    /**
     * The original messages of the array transport, in sending order.
     *
     * @return list<Email>
     */
    public static function sent(): array
    {
        $messages = [];

        foreach (Mail::getSymfonyTransport()->messages() as $message) {
            assert($message instanceof SentMessage);
            $original = $message->getOriginalMessage();
            assert($original instanceof Email);
            $messages[] = $original;
        }

        return $messages;
    }

    /**
     * The messages whose envelope names the given recipient.
     *
     * @return list<Email>
     */
    public static function to(string $address): array
    {
        $matching = [];

        foreach (Mail::getSymfonyTransport()->messages() as $message) {
            assert($message instanceof SentMessage);
            $original = $message->getOriginalMessage();

            $addressed = collect($message->getEnvelope()->getRecipients())
                ->contains(static fn (Address $recipient): bool => strcasecmp($recipient->getAddress(), $address) === 0);

            if ($addressed && $original instanceof Email) {
                $matching[] = $original;
            }
        }

        return $matching;
    }
}
