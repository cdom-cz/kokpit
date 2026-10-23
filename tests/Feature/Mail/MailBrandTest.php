<?php

declare(strict_types=1);

use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Notifications\Mail\MailBrand;
use App\Domain\Notifications\Mail\MailLogo;
use App\Domain\Settings\DocumentImage;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The supplier logo in every e-mail (Phase 13, plan 04): one downscaled inline cid part, never a hosted URL, and a text
 * header with no dangling cid when there is no logo or its object is gone. Every image is drawn when the test runs.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
});

/**
 * Sends a partner invitation to a fictional on-demand address and returns the one message that left.
 */
function mailBrandInvitation(): Email
{
    $address = exampleEmail();
    Notification::route('mail', $address)->notify(new PartnerInvitation('Example Invitee', 'Example Client', 'https://app.example.com/invitation/abc', '1. 1. 2030 12:00'));

    $sent = MailFixtures::to($address);

    expect($sent)->toHaveCount(1);

    return $sent[0];
}

function mailBrandDocument(Email $email): HTMLDocument
{
    return HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
}

/**
 * The inline logo parts of a message.
 *
 * @return list<DataPart>
 */
function mailBrandInlineParts(Email $email): array
{
    return array_values(array_filter(
        $email->getAttachments(),
        static fn (DataPart $part): bool => $part->getFilename() === MailBrand::LOGO_NAME,
    ));
}

describe('the logo in the header', function (): void {
    it('embeds a large logo as one downscaled inline part shown at most 180 x 60', function (): void {
        MailFixtures::storeLogo(1200, 400);

        $email = mailBrandInvitation();
        $images = mailBrandDocument($email)->querySelectorAll('td.header img');
        $parts = mailBrandInlineParts($email);

        expect($images)->toHaveCount(1)
            ->and($images[0]->getAttribute('src'))->toBe('cid:kokpit-logo.png')
            ->and($images[0]->getAttribute('width'))->toBe('180')
            ->and($images[0]->getAttribute('height'))->toBe('60')
            ->and($images[0]->getAttribute('alt'))->toBe('Example Supplier s.r.o.')
            ->and($parts)->toHaveCount(1)
            ->and($parts[0]->getMediaType().'/'.$parts[0]->getMediaSubtype())->toBe('image/png')
            ->and($parts[0]->getPreparedHeaders()->getHeaderBody('Content-Disposition'))->toBe('inline')
            ->and($email->toString())->toContain('multipart/related');

        $size = getimagesizefromstring($parts[0]->getBody());

        expect($size)->not->toBeFalse()
            ->and($size[0])->toBeLessThanOrEqual(360)
            ->and($size[1])->toBeLessThanOrEqual(120)
            ->and($size[2])->toBe(IMAGETYPE_PNG);
    });

    it('keeps a small logo at its own size and never enlarges it', function (): void {
        MailFixtures::storeLogo(100, 40);

        $email = mailBrandInvitation();
        $image = mailBrandDocument($email)->querySelector('td.header img');
        $size = getimagesizefromstring(mailBrandInlineParts($email)[0]->getBody());

        expect($image?->getAttribute('width'))->toBe('100')
            ->and($image?->getAttribute('height'))->toBe('40')
            ->and($size[0])->toBe(100)
            ->and($size[1])->toBe(40);
    });

    it('embeds the logo once for each e-mail and reads it once for each scope', function (): void {
        MailFixtures::storeLogo(600, 200);

        $first = mailBrandInvitation();
        $second = mailBrandInvitation();

        expect(mailBrandInlineParts($first))->toHaveCount(1)
            ->and(mailBrandInlineParts($second))->toHaveCount(1)
            ->and(MailBrand::current()->hasLogo())->toBeTrue();
    });

    it('shows the name as text and holds no cid when the stored object is gone', function (): void {
        $path = MailFixtures::storeLogo(600, 200);
        Storage::disk('local')->delete($path);
        app()->forgetScopedInstances();

        $email = mailBrandInvitation();
        $document = mailBrandDocument($email);

        expect($document->querySelectorAll('td.header img'))->toHaveCount(0)
            ->and(trim((string) $document->querySelector('td.header')?->textContent))->toBe('Example Supplier s.r.o.')
            ->and((string) $email->getHtmlBody())->not->toContain('cid:')
            ->and(mailBrandInlineParts($email))->toBe([])
            ->and($email->getAttachments())->toBe([]);
    });

    it('shows the name as text and adds no part when there is no logo at all', function (): void {
        $email = mailBrandInvitation();
        $document = mailBrandDocument($email);

        expect(MailBrand::current()->hasLogo())->toBeFalse()
            ->and($document->querySelectorAll('td.header img'))->toHaveCount(0)
            ->and(trim((string) $document->querySelector('td.header')?->textContent))->toBe('Example Supplier s.r.o.')
            ->and((string) $email->getHtmlBody())->not->toContain('cid:')
            ->and($email->getAttachments())->toBe([]);
    });

    it('adds no route and no hosted address for the logo', function (): void {
        MailFixtures::storeLogo(300, 100);

        $html = (string) mailBrandInvitation()->getHtmlBody();

        expect($html)->not->toContain('data:image')
            ->and($html)->not->toContain('/storage/')
            ->and($html)->not->toContain('settings/document-logo');
    });
});

describe('the logo reader', function (): void {
    it('returns null for bytes that are not an image', function (): void {
        expect(MailLogo::fromPng('this is not an image'))->toBeNull()
            ->and(MailLogo::fromPng(''))->toBeNull();
    });

    it('fits a logo into 360 x 120 and shows it at 180 x 60 at most, keeping the proportions', function (): void {
        $image = imagecreatetruecolor(2000, 500);
        ob_start();
        imagepng($image);
        $logo = MailLogo::fromPng((string) ob_get_clean());

        expect($logo)->not->toBeNull()
            ->and($logo['width'])->toBe(180)
            ->and($logo['height'])->toBe(45);

        $size = getimagesizefromstring($logo['png']);

        expect($size[0])->toBe(360)
            ->and($size[1])->toBe(90);
    });

    it('reads the bytes of a stored document image only through the path check', function (): void {
        $path = MailFixtures::storeLogo(120, 40);
        $images = app(DocumentImage::class);

        expect($images->contents($path))->toStartWith("\x89PNG")
            ->and($images->contents('settings/other-file.png'))->toBeNull()
            ->and($images->contents('../'.$path))->toBeNull()
            ->and($images->contents('settings/document-logo-'.str_repeat('a', 64).'.png'))->toBeNull();
    });
});
