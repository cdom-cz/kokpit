<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Notifications\Mail\MailBrand;
use App\Domain\Notifications\Mail\MailText;
use App\Domain\Operations\Alerts\AdminAlerter;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Notifications\TaskCommentedNotification;
use Dom\HTMLDocument;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\FictionalIban;
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

/**
 * Sends a test-local notification that builds the given message to a fictional on-demand address and returns the
 * one message that left.
 */
function mailThemeNotify(MailMessage $message, ?string $locale = null): Email
{
    $address = exampleEmail();
    $notification = new class($message) extends BaseNotification
    {
        public function __construct(private readonly MailMessage $message) {}

        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return $this->message;
        }
    };

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

describe('the notification view', function (): void {
    it('opens with the neutral greeting in the language of the e-mail unless the notification sets one', function (): void {
        $message = static fn (): MailMessage => (new MailMessage)->subject('Example subject')->line('Example line.');

        expect(mailThemeText(mailThemeDocument(mailThemeNotify($message())), 'h1'))->toBe('Dobrý den,')
            ->and(mailThemeText(mailThemeDocument(mailThemeNotify($message(), 'en')), 'h1'))->toBe('Hello,')
            ->and(mailThemeText(mailThemeDocument(mailThemeNotify($message()->greeting('Example greeting'))), 'h1'))->toBe('Example greeting')
            ->and(mailThemeText(mailThemeDocument(mailThemeNotify($message()->error())), 'h1'))->toBe('Dobrý den,');
    });

    it('prints no sign-off unless the notification sets a salutation', function (): void {
        $html = (string) mailThemeNotify((new MailMessage)->subject('Example subject')->line('Example line.')->action('Open example', 'https://app.example.com/open')->line('Example outro.'))->getHtmlBody();
        $text = mailThemeText(mailThemeDocument(mailThemeNotify((new MailMessage)->line('Example line.')->line('Example outro.'))), 'body');

        expect($html)->not->toContain('S pozdravem')
            ->and($html)->not->toContain('Regards')
            ->and($text)->not->toContain('S pozdravem')
            ->and($text)->not->toContain('Regards');
    });

    it('shows a two-line salutation as two escaped lines joined by a line break', function (): void {
        $email = mailThemeNotify((new MailMessage)->line('Example line.')->salutation("Kind regards\nExample <i>Sender</i> & Co"));
        $document = mailThemeDocument($email);
        $paragraphs = array_values(array_filter(
            iterator_to_array($document->querySelectorAll('td.content-cell p')),
            static fn ($p): bool => str_contains((string) $p->textContent, 'Kind regards'),
        ));

        expect($paragraphs)->toHaveCount(1)
            ->and($paragraphs[0]->querySelectorAll('br'))->toHaveCount(1)
            ->and($paragraphs[0]->querySelectorAll('i'))->toHaveCount(0)
            ->and(trim((string) preg_replace('/\s+/u', ' ', (string) $paragraphs[0]->textContent)))->toBe('Kind regardsExample <i>Sender</i> & Co');
    });

    it('explains a button with the layout fallback sentence and the link, never the framework sentence', function (): void {
        $message = static fn (): MailMessage => (new MailMessage)->line('Example line.')->action('Open example', 'https://app.example.com/open');
        $czech = mailThemeText(mailThemeDocument(mailThemeNotify($message())), 'table.subcopy');
        $english = mailThemeText(mailThemeDocument(mailThemeNotify($message(), 'en')), 'table.subcopy');

        expect($czech)->toStartWith('Pokud tlačítko nefunguje, zkopírujte tento odkaz do prohlížeče:')
            ->and($czech)->toContain('https://app.example.com/open')
            ->and($czech)->not->toContain('Open example')
            ->and($english)->toStartWith('If the button does not work, copy this link into your browser:')
            ->and($english)->toContain('https://app.example.com/open');
    });
});

describe('the operational alert', function (): void {
    it('keeps the error button colour and shares the header and footer markup of every other e-mail', function (): void {
        Canary::admin();

        app(AdminAlerter::class)->alert('mail-theme-test', new OperationalAlert('Example alert', "First line\nSecond line", 'https://app.example.com/system'));

        $alerts = array_values(array_filter(MailFixtures::sent(), static fn (Email $email): bool => $email->getSubject() === 'Example alert'));

        expect($alerts)->toHaveCount(1);

        $alert = mailThemeDocument($alerts[0]);
        $invitation = mailThemeDocument(mailThemeInvitation());

        expect((string) $alert->querySelector('a.button')?->getAttribute('style'))->toContain('#dc2626')
            ->and($alert->querySelector('td.header')?->innerHTML)->toBe($invitation->querySelector('td.header')?->innerHTML)
            ->and($alert->querySelector('table.footer')?->innerHTML)->toBe($invitation->querySelector('table.footer')?->innerHTML);
    });
});

describe('the brand reader whitelist and fallback', function (): void {
    it('never prints a bank account, in either part of the e-mail', function (): void {
        $email = mailThemeInvitation();
        $everything = (string) $email->getHtmlBody().' '.(string) $email->getTextBody();

        foreach (['12', '34'] as $fragment) {
            $iban = FictionalIban::czech($fragment);

            expect($everything)->not->toContain($iban)
                ->and($everything)->not->toContain(str_replace(' ', '', $iban))
                ->and($everything)->not->toContain('Example CZK account');
        }
    });

    it('still sends the e-mail with the application name when the settings cannot be read', function (): void {
        app()->bind(SupplierSettings::class, static fn () => throw new RuntimeException('settings unavailable'));
        app()->forgetScopedInstances();

        $document = mailThemeDocument(mailThemeInvitation());

        expect(mailThemeText($document, 'td.header'))->toBe((string) config('app.name'))
            ->and(mailThemeFooterLines($document))->toBe([(string) config('app.name')]);
    });
});

describe('the plain-text part', function (): void {
    it('starts with the supplier name, carries the footer lines and no stock Laravel text', function (): void {
        $text = (string) mailThemeInvitation()->getTextBody();
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));

        expect($text)->toStartWith('Example Supplier s.r.o.')
            ->and($text)->toContain('Sample Street 10, 100 00 Sampletown')
            ->and($text)->toContain('IČO '.mailThemeSupplier('company_id'))
            ->and($text)->toContain((string) mailThemeSupplier('email'))
            ->and($text)->toContain('https://www.example.com')
            ->and($text)->toContain('Registered in the example register.')
            ->and(mb_strtolower($text))->not->toContain('laravel')
            ->and($text)->not->toContain('©')
            ->and(mb_strtolower($text))->not->toContain('all rights reserved')
            ->and(end($lines))->toBe('Registered in the example register.');
    });

    it('carries the footer labels of the locale of the e-mail', function (): void {
        $english = (string) mailThemeInvitation('en')->getTextBody();
        $czech = (string) mailThemeInvitation()->getTextBody();

        expect($english)->toContain('Company ID '.mailThemeSupplier('company_id'))
            ->and($english)->not->toContain('IČO')
            ->and($czech)->toContain('IČO '.mailThemeSupplier('company_id'))
            ->and($czech)->not->toContain('Company ID');
    });

    it('prints a footer line only when it has content and never a bank account', function (): void {
        DB::table('settings')->where('group', 'supplier')->whereIn('name', ['street', 'city', 'postal_code', 'company_id', 'email', 'website', 'registration_note'])
            ->update(['payload' => json_encode('')]);
        app()->forgetScopedInstances();

        $text = trim((string) mailThemeInvitation()->getTextBody());

        expect($text)->toContain('Example Supplier s.r.o.')
            ->and($text)->not->toContain('IČO')
            ->and($text)->not->toContain('Example CZK account')
            ->and($text)->not->toMatch('/\R{3,}/');
    });

    it('keeps the characters of a company name literal and entity-free', function (): void {
        DB::table('settings')->where('group', 'supplier')->where('name', 'company_name')
            ->update(['payload' => json_encode('Example *Star* & Co')]);
        app()->forgetScopedInstances();

        $text = (string) mailThemeInvitation()->getTextBody();

        expect($text)->toStartWith('Example *Star* & Co')
            ->and($text)->not->toContain('&amp;')
            ->and($text)->not->toContain('\\');
    });

    it('shows a Markdown-escaped value without any escape backslash, in both parts', function (): void {
        $title = 'Fix *bold* _under_ [link](x) back\\slash `code` #1 | a ~ b !';
        $notification = new TaskCommentedNotification('ABC-1', $title, 'ABC', 'Jane Example', 'Excerpt *star* here', 'https://app.example.com/tasks/ABC-1', false);
        $email = mailThemeNotify($notification->toMail(new stdClass));
        $text = (string) $email->getTextBody();
        $html = mailThemeDocument($email);

        expect($text)->toContain($title)
            ->and($text)->toContain('Excerpt *star* here')
            ->and($text)->not->toContain('\\*')
            ->and($text)->not->toContain('\\_')
            ->and($text)->not->toContain('\\[')
            ->and($html->querySelectorAll('td.content-cell em, td.content-cell strong, td.content-cell code, td.content-cell h2'))->toHaveCount(0)
            ->and((string) $html->querySelector('td.content-cell')?->textContent)->toContain('Fix *bold* _under_ [link](x) back\\slash `code` #1 | a ~ b !');
    });
});

describe('the Markdown escape', function (): void {
    it('is reversed exactly by the plain-text unescape, apart from the collapsed whitespace', function (): void {
        $original = "Every `char`: back\\slash *a* _b_ [c] (d) #e | f ~ g !h  <i> & \\*already\\*\n new line";
        $escaped = MailText::escapeMarkdown($original);

        expect($escaped)->not->toBe($original)
            ->and(MailText::unescapeMarkdown($escaped))->toBe((string) preg_replace('/\s+/u', ' ', $original))
            ->and(MailText::unescapeMarkdown('plain text'))->toBe('plain text')
            ->and(MailText::unescapeMarkdown('a \\x stays'))->toBe('a \\x stays');
    });

    it('collapses white space and line breaks but keeps the no-break spaces of a formatted amount', function (): void {
        $amount = "12\u{00A0}500,00\u{00A0}K\u{010D}";
        $narrow = "1\u{202F}000,00\u{202F}EUR";

        expect(MailText::escapeMarkdown("a  b\n\tc"))->toBe('a b c')
            ->and(MailText::escapeMarkdown($amount))->toBe($amount)
            ->and(MailText::escapeMarkdown($narrow))->toBe($narrow);
    });

    it('leaves angle brackets and ampersands alone', function (): void {
        expect(MailText::escapeMarkdown('A < B & C > D'))->toBe('A < B & C > D');
    });
});
