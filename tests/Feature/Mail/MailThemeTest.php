<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Notifications\Mail\MailBrand;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use Dom\HTMLDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The shared e-mail layout (Phase 13, plan 01): the published kokpit theme, the supplier's header and footer read
 * through MailBrand, the neutral greeting of the notification view and the layout strings in two languages.
 * Every name, address and number is fictional.
 */

beforeEach(function (): void {
    InvoiceFixtures::configureSupplier();
});

/**
 * Sends a partner invitation to a fictional on-demand address and returns the one message that left.
 */
function mailThemeInvitation(?string $locale = null): Email
{
    $address = exampleEmail();
    $notification = new PartnerInvitation('Example Invitee', 'Example Client', 'https://app.example.com/invitation/abc', '1. 1. 2030 12:00');

    if ($locale !== null) {
        $notification->locale($locale);
    }

    Notification::route('mail', $address)->notify($notification);

    $sent = MailFixtures::to($address);

    expect($sent)->toHaveCount(1);

    return $sent[0];
}

function mailThemeDocument(Email $email): HTMLDocument
{
    return HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
}

/**
 * The collapsed text of the first element matching the selector.
 */
function mailThemeText(HTMLDocument $document, string $selector): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $document->querySelector($selector)?->textContent));
}

/**
 * The text of every footer line, in order.
 *
 * @return list<string>
 */
function mailThemeFooterLines(HTMLDocument $document): array
{
    $lines = [];

    foreach ($document->querySelectorAll('table.footer p') as $paragraph) {
        $lines[] = trim((string) preg_replace('/\s+/u', ' ', (string) $paragraph->textContent));
    }

    return $lines;
}

/**
 * A value of the saved supplier settings, read back as a system run.
 */
function mailThemeSupplier(string $field): ?string
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?string => app(SupplierSettings::class)->{$field});
}

describe('the supplier layout', function (): void {
    it('puts the supplier name in the header and title and the details in the footer, from a worker with no user', function (): void {
        $document = mailThemeDocument(mailThemeInvitation());
        $footer = implode(' | ', mailThemeFooterLines($document));

        expect(mailThemeText($document, 'td.header'))->toBe('Example Supplier s.r.o.')
            ->and(mailThemeText($document, 'title'))->toBe('Example Supplier s.r.o.')
            ->and($footer)->toContain('Sample Street 10')
            ->and($footer)->toContain('100 00 Sampletown')
            ->and($footer)->toContain('IČO '.mailThemeSupplier('company_id'))
            ->and($footer)->toContain((string) mailThemeSupplier('email'))
            ->and($footer)->toContain('Registered in the example register.');
    });

    it('links the header to the website of the supplier only when there is one', function (): void {
        $document = mailThemeDocument(mailThemeInvitation());

        expect($document->querySelector('td.header a')?->getAttribute('href'))->toBe('https://www.example.com');
    });

    it('renders the same header and footer while a Partner is signed in', function (): void {
        $without = mailThemeDocument(mailThemeInvitation());
        MailFixtures::sent();

        $client = Client::factory()->create();
        $this->actingAs(Canary::partnerFor($client->id));
        app()->forgetScopedInstances();

        $with = mailThemeDocument(mailThemeInvitation());

        expect(mailThemeText($with, 'td.header'))->toBe('Example Supplier s.r.o.')
            ->and(mailThemeFooterLines($with))->toBe(mailThemeFooterLines($without))
            ->and(mailThemeFooterLines($with))->not->toBe([]);
    });

    it('falls back to the application name and prints no empty footer line when the supplier fields are blank', function (): void {
        DB::table('settings')->where('group', 'supplier')->whereIn('name', ['company_name', 'street', 'city', 'postal_code', 'company_id', 'email', 'website', 'registration_note'])
            ->update(['payload' => json_encode('')]);
        app()->forgetScopedInstances();

        $document = mailThemeDocument(mailThemeInvitation());

        expect(mailThemeText($document, 'td.header'))->toBe((string) config('app.name'))
            ->and($document->querySelector('td.header a'))->toBeNull()
            ->and(mailThemeFooterLines($document))->toBe([(string) config('app.name')]);
    });

    it('keeps the characters of a company name literal in the footer', function (): void {
        DB::table('settings')->where('group', 'supplier')->where('name', 'company_name')
            ->update(['payload' => json_encode('Example *Star* & <b>Bold</b> s.r.o.')]);
        app()->forgetScopedInstances();

        $document = mailThemeDocument(mailThemeInvitation());

        expect(mailThemeFooterLines($document)[0])->toBe('Example *Star* & <b>Bold</b> s.r.o.')
            ->and(mailThemeText($document, 'td.header'))->toBe('Example *Star* & <b>Bold</b> s.r.o.')
            ->and($document->querySelectorAll('table.footer b, table.footer em, td.header b'))->toHaveCount(0);
    });
});

describe('the kokpit theme', function (): void {
    it('inlines the amber primary button and carries no stock Laravel branding', function (): void {
        $email = mailThemeInvitation();
        $html = (string) $email->getHtmlBody();
        $button = mailThemeDocument($email)->querySelector('a.button');

        expect((string) $button?->getAttribute('style'))->toContain('#f59e0b')
            ->and(mb_strtolower($html))->not->toContain('laravel')
            ->and($html)->not->toContain('©')
            ->and(mb_strtolower($html))->not->toContain('all rights reserved');
    });

    it('uses no external address in the theme', function (): void {
        $html = (string) mailThemeInvitation()->getHtmlBody();

        expect($html)->not->toContain('url(')
            ->and($html)->not->toContain('@import');
    });
});

describe('the layout strings', function (): void {
    it('follow the locale of the e-mail', function (): void {
        $english = implode(' | ', mailThemeFooterLines(mailThemeDocument(mailThemeInvitation('en'))));
        $czech = implode(' | ', mailThemeFooterLines(mailThemeDocument(mailThemeInvitation())));

        expect($english)->toContain('Company ID '.mailThemeSupplier('company_id'))
            ->and($english)->not->toContain('IČO')
            ->and($czech)->toContain('IČO '.mailThemeSupplier('company_id'))
            ->and($czech)->not->toContain('Company ID');
    });

    it('has the same keys in Czech and English', function (): void {
        $keys = static fn (string $locale): array => array_keys(Arr::dot(require lang_path($locale.'/mail_layout.php')));

        expect($keys('en'))->toBe($keys('cs'));
    });
});

describe('the brand reader', function (): void {
    it('is scoped: one read per request, console run or queue job', function (): void {
        $first = MailBrand::current();

        expect(MailBrand::current())->toBe($first);

        app()->forgetScopedInstances();

        expect(MailBrand::current())->not->toBe($first)
            ->and(MailBrand::current()->name)->toBe('Example Supplier s.r.o.');
    });
});
