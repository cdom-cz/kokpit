<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
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

    /**
     * Stores a fictional logo as the supplier's logo and returns the stored path (R-13-04-4).
     *
     * The PNG is drawn with GD when the test runs, so no binary is in git. It goes through the real upload path
     * (DocumentImage::store as the system) onto the media disk, which the test has faked with
     * DocumentFixtures::fakeDisk(), and into SupplierSettings::logo_path; the scoped instances are dropped so the
     * next e-mail reads the new brand.
     */
    public static function storeLogo(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 29, 78, 216));
        imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv($width * 3, 4), intdiv($height * 3, 4), (int) imagecolorallocate($image, 245, 158, 11));

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        unset($image);

        $path = app(PartnerContext::class)->runAsSystem(static function () use ($png): string {
            $path = app(DocumentImage::class)->store(DocumentFixtures::upload('brand-logo.png', $png), 'logo');

            $supplier = app(SupplierSettings::class);
            $supplier->logo_path = $path;
            $supplier->save();

            return $path;
        });

        app()->forgetScopedInstances();

        return $path;
    }
}
