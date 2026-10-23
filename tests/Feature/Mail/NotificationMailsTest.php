<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Actions\SendPartnerPasswordReset;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use App\Domain\Operations\Alerts\AdminAlerter;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Tasks\Notifications\TaskCreatedNotification;
use Dom\HTMLDocument;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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

describe('the password reset', function (): void {
    it('goes out as Kokpit\'s e-mail on the shared layout with the reset link and the reviewed Czech text', function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = Client::factory()->create();
        $partner = Canary::partnerFor($client->id);

        app(SendPartnerPasswordReset::class)->handle($partner);

        $messages = MailFixtures::to($partner->email);

        expect($messages)->toHaveCount(1);

        $email = $messages[0];
        $dom = notifMailDom($email);
        $visible = notifMailVisible($dom);
        $buttons = $dom->querySelectorAll('a.button');
        $text = (string) $email->getTextBody();
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        expect($email->getSubject())->toBe('Obnovení hesla')
            ->and($visible)->toContain('Dobrý den,')
            ->and($visible)->toContain('obdrželi jsme žádost o obnovení hesla k vašemu účtu.')
            ->and($visible)->toContain('Odkaz platí '.$minutes.' minut.')
            ->and($visible)->toContain('Pokud jste o obnovení nežádali, nic nedělejte: vaše heslo zůstává beze změny.')
            ->and($visible)->not->toContain('Tato zpráva vám byla doručena')
            ->and($visible)->not->toContain('zprávu smažte')
            ->and($visible)->not->toContain('S pozdravem')
            ->and($dom->querySelector('td.header')?->textContent)->toContain('Example Supplier s.r.o.')
            ->and($buttons)->toHaveCount(1)
            ->and(trim((string) $buttons[0]?->textContent))->toBe('Nastavit nové heslo');

        $request = Request::create((string) $buttons[0]?->getAttribute('href'));

        expect(URL::hasValidSignature($request))->toBeTrue()
            ->and(Route::getRoutes()->match($request)->getName())->toBe('filament.admin.auth.password-reset.reset')
            ->and($request->query('email'))->toBe($partner->email)
            ->and($text)->toContain('Nastavit nové heslo: ')
            ->and($text)->toContain('Odkaz platí '.$minutes.' minut.')
            ->and($text)->not->toContain('Tato zpráva vám byla doručena');
    });

    it('is Kokpit\'s subclass of the Filament notification and stays queued', function (): void {
        $notification = app(ResetPasswordNotification::class, ['token' => 'example-token']);

        expect($notification)->toBeInstanceOf(FilamentResetPassword::class)
            ->and($notification)->toBeInstanceOf(ShouldQueue::class);
    });
});

describe('the alerts and task notifications', function (): void {
    it('shows an alert\'s title and lines exactly as written, with the error button and no sign-off', function (): void {
        $line = 'Chyba: App\\Jobs\\Fictional_Report_Job: '.notifMailHostile('failed');
        $title = notifMailHostile('Alert');
        $url = route('filament.admin.pages.system');

        app(AdminAlerter::class)->alert('notif-mail-alert', new OperationalAlert($title, "Úloha: [queue_name]\n".$line, $url));

        $messages = MailFixtures::to($this->admin->email);

        expect($messages)->toHaveCount(1);

        $email = $messages[0];
        $dom = notifMailDom($email);
        $visible = notifMailVisible($dom);
        $text = (string) $email->getTextBody();
        $buttons = $dom->querySelectorAll('a.button');

        expect(notifMailFindings($dom))->toBe([])
            ->and($visible)->toContain($title)
            ->and($visible)->toContain($line)
            ->and($visible)->toContain('Úloha: [queue_name]')
            ->and($visible)->not->toContain('S pozdravem')
            ->and($buttons)->toHaveCount(1)
            ->and($buttons[0]?->getAttribute('href'))->toBe($url)
            ->and($buttons[0]?->getAttribute('class'))->toContain('button-error')
            ->and($text)->toContain($title)
            ->and($text)->toContain($line)
            ->and($text)->not->toMatch('/\\\\[\[\]*_]/')
            ->and($text)->not->toContain('S pozdravem');
    });

    it('prints the same header and footer markup on an alert as on a client-facing invitation', function (): void {
        app(AdminAlerter::class)->alert('notif-mail-brand', new OperationalAlert('Alert title', 'Alert line', route('filament.admin.pages.system')));

        $client = Client::factory()->create();
        app(InvitePartner::class)->handle($client, 'Jane Example', 'invitee@example.com', $this->admin);

        $alert = notifMailDom(MailFixtures::to($this->admin->email)[0]);
        $invitation = notifMailDom(MailFixtures::to('invitee@example.com')[0]);

        foreach (['td.header', 'table.footer'] as $selector) {
            expect($alert->querySelector($selector)?->innerHTML)->not->toBeNull()
                ->and($alert->querySelector($selector)?->innerHTML)->toBe($invitation->querySelector($selector)?->innerHTML);
        }
    });

    it('mails a task notification to the Admin on the supplier layout with the reviewed Czech text', function (): void {
        $notification = new TaskCreatedNotification(
            taskReference: 'EX-1',
            taskTitle: notifMailHostile('Task'),
            projectKey: 'EX',
            actorName: notifMailHostile('Pat'),
            url: route('filament.admin.resources.tasks.view', ['record' => 'EX-1']),
        );

        $this->admin->notify($notification);

        $messages = MailFixtures::to($this->admin->email);

        expect($messages)->toHaveCount(1);

        $dom = notifMailDom($messages[0]);
        $visible = notifMailVisible($dom);

        expect(notifMailFindings($dom))->toBe([])
            ->and($messages[0]->getSubject())->toContain('Nový úkol EX-1')
            ->and($dom->querySelector('td.header')?->textContent)->toContain('Example Supplier s.r.o.')
            ->and($visible)->toContain('Dobrý den,')
            ->and($visible)->toContain('V projektu EX přibyl nový úkol od uživatele '.notifMailHostile('Pat').'.')
            ->and($visible)->toContain('Způsob doručení upozornění můžete změnit ve svém profilu.')
            ->and($visible)->not->toContain('S pozdravem')
            ->and($dom->querySelectorAll('a.button'))->toHaveCount(1);
    });
});
