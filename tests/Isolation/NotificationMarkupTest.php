<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;

/*
 * A Partner controls the task title, the comment and the own display name, and
 * those values are put into the bell and the mail of the Admin and of the other
 * Partners of the client (TA-07; threat T-05-44, review finding WR-02). Whatever
 * markup such a value holds must reach every recipient as visible text only: no
 * link, image, style or emphasis may be built from it, and the text must read
 * exactly as written. Every host, style marker and word is fictional and is
 * assembled at runtime from fragments, so no line of this file looks like a real
 * value. The rendered output is parsed as a DOM and the element attributes are
 * inspected; a substring search would also match inert text.
 */

/**
 * The fictional canary host of the run, under example.com.
 */
function notifMarkupHost(): string
{
    static $host = null;

    return $host ??= implode('.', ['phish-'.bin2hex(random_bytes(4)), 'example', 'com']);
}

/**
 * The marker a style attribute built from a value would carry.
 */
function notifMarkupMarker(): string
{
    static $marker = null;

    return $marker ??= 'mk'.bin2hex(random_bytes(4));
}

/**
 * The runtime word put between emphasis and code delimiters.
 */
function notifMarkupWord(): string
{
    static $word = null;

    return $word ??= 'wd'.bin2hex(random_bytes(4));
}

/**
 * The sentence that holds the two characters a double encoding would show.
 */
function notifMarkupPlain(): string
{
    return implode(' ', ['A', '<', 'B', '&', 'C']);
}

/**
 * The HTML vectors: an anchor, an image and a styled span.
 *
 * @return list<string>
 */
function notifMarkupHtmlVectors(): array
{
    $host = notifMarkupHost();

    return [
        '<a href="https://'.$host.'/a">click</a>',
        '<img src="https://'.$host.'/i.png">',
        '<span style="position:fixed;--m:'.notifMarkupMarker().'">over</span>',
    ];
}

/**
 * The Markdown vectors: a link, an image, strong and plain emphasis, inline code.
 *
 * @return list<string>
 */
function notifMarkupMarkdownVectors(): array
{
    $host = notifMarkupHost();
    $word = notifMarkupWord();

    return [
        '['.'link]('.'https://'.$host.'/m)',
        '!['.'image]('.'https://'.$host.'/mi.png)',
        '**'.$word.'** *'.$word.'* `'.$word.'`',
    ];
}

/**
 * One value holding every vector (for values without a length limit in the test).
 */
function notifMarkupFull(): string
{
    return implode(' ', [...notifMarkupHtmlVectors(), ...notifMarkupMarkdownVectors(), notifMarkupPlain()]);
}

/**
 * A task title with an HTML anchor, a Markdown link and the plain sentence.
 */
function notifMarkupTitle(): string
{
    return implode(' ', [notifMarkupHtmlVectors()[0], notifMarkupMarkdownVectors()[0], notifMarkupPlain()]);
}

/**
 * A display name with an image, a styled span, a Markdown image and the plain sentence.
 */
function notifMarkupName(): string
{
    return implode(' ', [notifMarkupHtmlVectors()[1], notifMarkupHtmlVectors()[2], notifMarkupMarkdownVectors()[1], notifMarkupPlain()]);
}

/**
 * The elements of a rendered HTML document that were built from a vector: an
 * attribute (href, src, style or any other) that holds the canary host or the
 * style marker, and a strong, em or code element that holds the runtime word.
 *
 * @return list<string>
 */
function notifMarkupFindings(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $findings = [];

    foreach ($document->querySelectorAll('*') as $element) {
        foreach ($element->attributes as $attribute) {
            if (str_contains($attribute->value, notifMarkupHost()) || str_contains($attribute->value, notifMarkupMarker())) {
                $findings[] = strtolower($element->localName).'['.$attribute->name.']';
            }
        }

        if (in_array(strtolower($element->localName), ['strong', 'em', 'code', 'b', 'i'], true)
            && str_contains((string) $element->textContent, notifMarkupWord())) {
            $findings[] = strtolower($element->localName).' holds the word';
        }
    }

    return $findings;
}

/**
 * The text a reader sees: the document without style and script elements, with
 * the entities decoded by the parser and the white space collapsed.
 */
function notifMarkupVisible(string $html): string
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    foreach ($document->querySelectorAll('style, script') as $element) {
        $element->remove();
    }

    return trim((string) preg_replace('/\s+/u', ' ', (string) $document->body?->textContent));
}

/**
 * The strings a visible text must not hold: a visible entity, or a backslash
 * before a Markdown character.
 *
 * @return list<string>
 */
function notifMarkupVisibleDefects(string $text): array
{
    $defects = [];

    foreach (['&lt;', '&gt;', '&amp;', '&quot;'] as $entity) {
        if (str_contains($text, $entity)) {
            $defects[] = $entity;
        }
    }

    if (preg_match('/\\\\[\[\]*_]/', $text) === 1) {
        $defects[] = 'backslash';
    }

    return $defects;
}

/**
 * A client-visible project of the client, created through the domain Action.
 */
function notifMarkupProject(string $clientId): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
}

/**
 * The task as the signed-in user reads it, through the scoped query a page uses.
 */
function notifMarkupSeen(User $viewer, Task $task): Task
{
    test()->actingAs($viewer);

    return Task::query()->where('reference', $task->reference)->firstOrFail();
}

/**
 * The HTML part of the mails sent to the user, decoded from the array transport.
 *
 * @return list<array{subject: string, html: string}>
 */
function notifMarkupMails(User $user): array
{
    $mails = [];

    foreach (Mail::getSymfonyTransport()->messages() as $message) {
        /** @var SentMessage $message */
        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            continue;
        }

        $addressed = collect($message->getEnvelope()->getRecipients())
            ->contains(static fn (Address $address): bool => $address->getAddress() === $user->email);

        if ($addressed) {
            $mails[] = ['subject' => (string) $original->getSubject(), 'html' => (string) $original->getHtmlBody()];
        }
    }

    return $mails;
}

/**
 * The stored bell rows of the user, rendered the way the bell view renders each
 * of them (Filament's inline notification, with its own sanitiser).
 *
 * @return list<string>
 */
function notifMarkupBells(User $user): array
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->getKey())
        ->get()
        ->map(static fn (DatabaseNotification $row): string => FilamentNotification::fromDatabase($row)->inline()->toHtml())
        ->all();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders a markup title and a markup display name as plain text in the comment mail and bell of the Admin and of the Partner assignee', function (): void {
    $admin = Canary::admin();
    [$clientA] = Canary::twoClients();
    $project = notifMarkupProject($clientA);
    $partnerA = Canary::partnerFor($clientA);
    $partnerA2 = Canary::partnerFor($clientA);

    // The display name of Partner A2 is the vector: it is the actor of the comment.
    app(PartnerContext::class)->runAsSystem(static function () use ($partnerA2): void {
        $partnerA2->forceFill(['name' => notifMarkupName()])->save();
    });

    test()->actingAs($partnerA);
    $task = app(CreateTask::class)->handle($partnerA, $project, ['title' => notifMarkupTitle()]);

    expect($task->title)->toBe(notifMarkupTitle());

    app(UpdateTask::class)->handle($admin, notifMarkupSeen($admin, $task), ['assignee_id' => $partnerA->id]);

    // Only the comment is under test: start from an empty inbox and an empty transport.
    DB::table('notifications')->delete();
    Mail::getSymfonyTransport()->flush();

    app(AddTaskComment::class)->handle($partnerA2, notifMarkupSeen($partnerA2, $task), '<p>Example remark of a colleague</p>');

    auth()->logout();

    foreach ([$admin, $partnerA] as $recipient) {
        $bells = notifMarkupBells($recipient);
        $mails = notifMarkupMails($recipient);

        expect($bells)->toHaveCount(1)
            ->and($mails)->toHaveCount(1);

        $mailHtml = $mails[0]['html'];
        $mailText = notifMarkupVisible($mailHtml);
        $bellText = notifMarkupVisible($bells[0]);

        expect(notifMarkupFindings($mailHtml))->toBe([])
            ->and(notifMarkupFindings($bells[0]))->toBe([])
            ->and($mailText)->toContain(notifMarkupTitle())
            ->and($mailText)->toContain(notifMarkupName())
            ->and($bellText)->toContain(notifMarkupName())
            ->and(notifMarkupVisibleDefects($mailText))->toBe([])
            ->and(notifMarkupVisibleDefects($bellText))->toBe([])
            ->and($mails[0]['subject'])->toBe(__('kokpit.tasks.notifications.comment.mail_subject', ['reference' => $task->reference]));
    }
});
