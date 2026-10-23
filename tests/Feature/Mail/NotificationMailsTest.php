<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Models\Client;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The four other e-mails on the shared layout (Phase 13, D-04, D-07, P13-06 to P13-09): the partner invitation, the
 * password reset, the task notifications and the operational alerts. Whatever an Admin, a Partner or a failed job typed
 * into them reaches the reader as visible text only (T-13-15, T-13-16). Every host, word and address is fictional and
 * assembled at runtime from fragments; the rendered HTML is parsed as a DOM and the element attributes are inspected,
 * because a substring search would also match inert text.
 */

beforeEach(function (): void {
    InvoiceFixtures::configureSupplier();
    config(['app.url' => 'https://kokpit.example.com']);
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    Mail::getSymfonyTransport()->flush();
});

/**
 * The fictional canary host of the run, under example.com.
 */
function notifMailHost(): string
{
    static $host = null;

    return $host ??= implode('.', ['phish-'.bin2hex(random_bytes(4)), 'example', 'com']);
}

/**
 * The runtime word put between emphasis delimiters.
 */
function notifMailWord(): string
{
    static $word = null;

    return $word ??= 'wd'.bin2hex(random_bytes(4));
}

/**
 * A name holding a Markdown link, an image tag, strong and plain emphasis and an underscore pair, built from fragments.
 */
function notifMailHostile(string $lead): string
{
    $host = notifMailHost();
    $word = notifMailWord();

    return $lead.' ['.'link]('.'https://'.$host.'/m) <img src="https://'.$host.'/i.png"> **'.$word.'** *'.$word.'* _'.$word.'_';
}

function notifMailDom(Email $email): HTMLDocument
{
    return HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
}

/**
 * The text a reader sees: the document without style and script elements, white space collapsed.
 */
function notifMailVisible(HTMLDocument $dom): string
{
    foreach ($dom->querySelectorAll('style, script') as $element) {
        $element->remove();
    }

    return trim((string) preg_replace('/\s+/u', ' ', (string) $dom->body?->textContent));
}

/**
 * The elements built from a vector: an attribute that holds the canary host, and a strong, em, code, b or i element
 * that holds the word.
 *
 * @return list<string>
 */
function notifMailFindings(HTMLDocument $dom): array
{
    $findings = [];

    foreach ($dom->querySelectorAll('*') as $element) {
        foreach ($element->attributes as $attribute) {
            if (str_contains($attribute->value, notifMailHost())) {
                $findings[] = strtolower($element->localName).'['.$attribute->name.']';
            }
        }

        if (in_array(strtolower($element->localName), ['strong', 'em', 'code', 'b', 'i'], true)
            && str_contains((string) $element->textContent, notifMailWord())) {
            $findings[] = strtolower($element->localName).' holds the word';
        }
    }

    return $findings;
}

describe('the invitation', function (): void {
    it('arrives in the supplier layout with the reviewed Czech text, the names as typed and the supplier sign-off', function (): void {
        $inviteeName = notifMailHostile('Jane');
        $clientName = notifMailHostile('Client');
        $client = Client::factory()->create(['name' => $clientName]);

        $invitation = app(InvitePartner::class)->handle($client, $inviteeName, 'invitee@example.com', $this->admin);

        $messages = MailFixtures::to('invitee@example.com');

        expect($messages)->toHaveCount(1);

        $email = $messages[0];
        $dom = notifMailDom($email);
        $visible = notifMailVisible($dom);
        $text = (string) $email->getTextBody();
        $buttons = $dom->querySelectorAll('a.button');

        expect(notifMailFindings($dom))->toBe([])
            ->and($email->getSubject())->toBe('Pozvánka od Example Supplier s.r.o.')
            ->and($visible)->toContain($inviteeName)
            ->and($visible)->toContain($clientName)
            ->and($visible)->toContain('zveme vás k vytvoření účtu')
            ->and($visible)->toContain('Odkaz platí do ')
            ->and($visible)->toContain('Pokud jste pozvánku nečekali, můžete tento e-mail ignorovat.')
            ->and($buttons)->toHaveCount(1)
            ->and(trim((string) $buttons[0]?->textContent))->toBe('Přijmout pozvánku')
            ->and($buttons[0]?->getAttribute('href'))->toContain('/invitation')
            ->and($buttons[0]?->getAttribute('href'))->toContain($invitation->id);

        // The sign-off: "S pozdravem" and the supplier name on the next line, above the supplier footer (D-07).
        expect($dom->querySelectorAll('td.content-cell br')->length)->toBeGreaterThanOrEqual(1)
            ->and($visible)->toContain('S pozdravem')
            ->and($visible)->toContain('Example Supplier s.r.o.')
            ->and($dom->querySelector('td.header')?->textContent)->toContain('Example Supplier s.r.o.')
            ->and($dom->querySelector('td.content-cell')?->textContent)->not->toContain('Pozdravujeme')
            ->and($dom->querySelector('table.footer')?->textContent ?? $visible)->toContain('Sample Street 10');

        // The text part reads as typed: no escape backslash, the two sign-off lines apart.
        expect($text)->toContain($inviteeName)
            ->and($text)->toContain($clientName)
            ->and($text)->not->toMatch('/\\\\[\[\]*_]/')
            ->and($text)->toContain("S pozdravem\nExample Supplier s.r.o.");
    });
});
