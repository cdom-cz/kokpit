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
use App\Domain\Tasks\Notifications\TaskChangedNotification;
use App\Domain\Tasks\Notifications\TaskCommentedNotification;
use App\Domain\Tasks\Notifications\TaskCreatedNotification;
use App\Domain\Tasks\Notifications\TaskEscalatedNotification;
use App\Domain\Tasks\Notifications\TaskNotification;
use App\Domain\Tasks\Notifications\TaskNotifier;
use Closure;
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
 * A bell row rendered the way the bell view renders it: Filament's inline
 * notification, whose title and body go through its own permissive sanitiser.
 */
function notifMarkupBellHtml(DatabaseNotification $row): string
{
    return FilamentNotification::fromDatabase($row)->inline()->toHtml();
}

/**
 * The stored bell rows of the user, rendered as the bell renders them.
 *
 * @return list<string>
 */
function notifMarkupBells(User $user): array
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->getKey())
        ->get()
        ->map(static fn (DatabaseNotification $row): string => notifMarkupBellHtml($row))
        ->all();
}

/**
 * The payload of toDatabase() as a bell row that is not stored, rendered as the bell renders it.
 *
 * @param  array<string, mixed>  $payload
 */
function notifMarkupPayloadHtml(array $payload): string
{
    $row = new DatabaseNotification;
    $row->forceFill(['id' => (string) Str::uuid(), 'data' => $payload]);

    return notifMarkupBellHtml($row);
}

/**
 * The matrix of the task notification classes: the text group of each, the
 * audiences it is sent to (TaskNotifier), a builder that puts every vector into
 * every value the class interpolates, and the values a reader must see as typed.
 * A class that is not in this table fails the completeness case.
 *
 * @return array<class-string<TaskNotification>, array{group: string, audiences: list<string>, build: Closure(string, bool): TaskNotification, mail: list<string>, bell: list<string>}>
 */
function notifMarkupMatrix(): array
{
    $full = notifMarkupFull();
    $person = 'Assignee: '.$full.' → Jane Example';

    return [
        TaskCreatedNotification::class => [
            'group' => 'task_created',
            'audiences' => ['admin'],
            'build' => static fn (string $url, bool $partner): TaskNotification => new TaskCreatedNotification('ABC-1', $full, 'ABC', $full, $url),
            'mail' => [$full, 'ABC'],
            'bell' => [$full, 'ABC'],
        ],
        TaskCommentedNotification::class => [
            'group' => 'comment',
            'audiences' => ['admin', 'partner'],
            'build' => static fn (string $url, bool $partner): TaskNotification => new TaskCommentedNotification('ABC-1', $full, 'ABC', $full, $full, $url, $partner),
            'mail' => [$full],
            'bell' => [$full, mb_substr($full, 0, 100)],
        ],
        TaskEscalatedNotification::class => [
            'group' => 'escalated',
            'audiences' => ['admin', 'partner'],
            'build' => static fn (string $url, bool $partner): TaskNotification => new TaskEscalatedNotification('ABC-1', $full, 'ABC', $full, $full, $url, $partner),
            'mail' => [$full],
            'bell' => [$full, mb_substr($full, 0, 100)],
        ],
        TaskChangedNotification::class => [
            'group' => 'changed',
            'audiences' => ['admin', 'partner'],
            'build' => static fn (string $url, bool $partner): TaskNotification => new TaskChangedNotification('ABC-1', $full, 'ABC', $full, $url, [$person, 'Status: A < B & C → D'], recipientIsPartner: $partner),
            'mail' => [$full, $person],
            'bell' => [$person],
        ],
    ];
}

/**
 * Every (class, audience) pair of the matrix, as a Pest dataset.
 *
 * @return array<string, array{0: class-string<TaskNotification>, 1: string}>
 */
function notifMarkupPairs(): array
{
    $pairs = [];

    foreach (notifMarkupMatrix() as $class => $row) {
        foreach ($row['audiences'] as $audience) {
            $pairs[class_basename($class).' to the '.$audience] = [$class, $audience];
        }
    }

    return $pairs;
}

/**
 * The concrete subclasses of TaskNotification found in the directory of the notifications.
 *
 * @return list<class-string<TaskNotification>>
 */
function notifMarkupDiscovered(): array
{
    $found = [];

    foreach (glob(app_path('Domain/Tasks/Notifications/*.php')) ?: [] as $file) {
        $class = 'App\\Domain\\Tasks\\Notifications\\'.basename($file, '.php');

        if (class_exists($class) && is_subclass_of($class, TaskNotification::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $found[] = $class;
        }
    }

    sort($found);

    return $found;
}

/**
 * The recipient and the task URL of an audience.
 *
 * @return array{0: User, 1: string}
 */
function notifMarkupAudience(string $audience): array
{
    if ($audience === 'admin') {
        return [Canary::admin(), route('filament.admin.resources.tasks.view', ['record' => 'ABC-1'])];
    }

    return [Canary::partnerFor(Canary::twoClients()[0]), route('filament.admin.resources.my-tasks.view', ['record' => 'ABC-1'])];
}

/**
 * Asserts that a rendered mail and a rendered bell are inert and read as typed.
 *
 * @param  list<string>  $mailValues  values that must read exactly as written in the mail
 * @param  list<string>  $bellValues  values that must read exactly as written in the bell
 */
function notifMarkupExpectInert(string $mailHtml, string $bellHtml, array $mailValues, array $bellValues): void
{
    $mailText = notifMarkupVisible($mailHtml);
    $bellText = notifMarkupVisible($bellHtml);

    expect(notifMarkupFindings($mailHtml))->toBe([])
        ->and(notifMarkupFindings($bellHtml))->toBe([])
        ->and(notifMarkupVisibleDefects($mailText))->toBe([])
        ->and(notifMarkupVisibleDefects($bellText))->toBe([])
        ->and($mailText)->toContain(notifMarkupPlain())
        ->and($bellText)->toContain(notifMarkupPlain());

    foreach ($mailValues as $value) {
        expect($mailText)->toContain($value);
    }

    foreach ($bellValues as $value) {
        expect($bellText)->toContain($value);
    }
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

it('renders every vector inert and as typed for every notification class and audience', function (string $class, string $audience): void {
    $row = notifMarkupMatrix()[$class];
    [$recipient, $url] = notifMarkupAudience($audience);
    $notification = $row['build']($url, $audience === 'partner');

    auth()->logout();

    $mail = $notification->toMail($recipient);
    $bell = notifMarkupPayloadHtml($notification->toDatabase($recipient));

    notifMarkupExpectInert((string) $mail->render(), $bell, $row['mail'], $row['bell']);
})->with(fn (): array => notifMarkupPairs());

it('keeps the mail subject plain text with the raw reference and title', function (string $class, string $audience): void {
    $row = notifMarkupMatrix()[$class];
    [$recipient, $url] = notifMarkupAudience($audience);
    $notification = $row['build']($url, $audience === 'partner');

    expect($notification->toMail($recipient)->subject)
        ->toBe(__('kokpit.tasks.notifications.'.$row['group'].'.mail_subject', ['reference' => 'ABC-1', 'title' => notifMarkupFull()]));
})->with(fn (): array => notifMarkupPairs());

it('renders the same notification twice, and after a queue round trip, to identical output', function (string $class, string $audience): void {
    $row = notifMarkupMatrix()[$class];
    [$recipient, $url] = notifMarkupAudience($audience);
    $notification = $row['build']($url, $audience === 'partner');
    $retried = unserialize(serialize($notification));

    expect($retried)->toBeInstanceOf(TaskNotification::class);

    $first = (string) $notification->toMail($recipient)->render();
    $payload = $notification->toDatabase($recipient);

    expect((string) $notification->toMail($recipient)->render())->toBe($first)
        ->and((string) $retried->toMail($recipient)->render())->toBe($first)
        ->and($notification->toDatabase($recipient))->toBe($payload)
        ->and($retried->toDatabase($recipient))->toBe($payload);
})->with(fn (): array => notifMarkupPairs());

it('cuts the bell excerpt to 120 characters as plain text before it is escaped', function (): void {
    [$admin, $url] = notifMarkupAudience('admin');
    $excerpt = str_repeat('a', 118).'&<'.notifMarkupMarkdownVectors()[0];
    $notification = new TaskCommentedNotification('ABC-1', 'Example title', 'ABC', 'Jane Example', $excerpt, $url, false);

    $payload = $notification->toDatabase($admin);
    $bell = notifMarkupPayloadHtml($payload);
    $text = notifMarkupVisible($bell);

    // The cut lands before the escape: the visible excerpt ends in "&…", with no entity cut in half.
    expect($text)->toContain('Jane Example: '.str_repeat('a', 118).'&…')
        ->and($text)->not->toContain('&…<')
        ->and($text)->not->toContain(notifMarkupHost())
        ->and(notifMarkupFindings($bell))->toBe([])
        ->and(notifMarkupVisibleDefects($text))->toBe([])
        ->and($payload['body'])->toContain(str_repeat('a', 118).'&amp;…');
});

it('shows the excerpt of the notifier complete in the mail, cut at 300 characters before the escape', function (): void {
    [$admin, $url] = notifMarkupAudience('admin');
    $body = '<p>'.str_repeat('a', 240).' A &lt; B &amp; C '.notifMarkupMarkdownVectors()[0].' '.str_repeat('b', 60).'</p>';
    $excerpt = TaskNotifier::excerpt($body);

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(300)
        ->and($excerpt)->toContain(notifMarkupPlain())
        ->and($excerpt)->toContain(notifMarkupMarkdownVectors()[0]);

    $notification = new TaskCommentedNotification('ABC-1', 'Example title', 'ABC', 'Jane Example', $excerpt, $url, false);

    auth()->logout();

    $html = (string) $notification->toMail($admin)->render();

    expect(notifMarkupFindings($html))->toBe([])
        ->and(notifMarkupVisibleDefects(notifMarkupVisible($html)))->toBe([])
        ->and(notifMarkupVisible($html))->toContain($excerpt);
});

it('builds a matrix row for every concrete task notification class', function (): void {
    $expected = array_keys(notifMarkupMatrix());
    sort($expected);

    expect(notifMarkupDiscovered())->not->toBeEmpty()
        ->and(notifMarkupDiscovered())->toBe($expected);
});

it('keeps the render methods final and the subclasses to constructors and text hooks, with no translation call', function (): void {
    foreach (['toMail', 'toDatabase'] as $method) {
        expect((new ReflectionMethod(TaskNotification::class, $method))->isFinal())->toBeTrue();
    }

    $allowed = ['__construct', 'textGroup', 'changeLines', 'bellBodyKey'];

    foreach (notifMarkupDiscovered() as $class) {
        $reflection = new ReflectionClass($class);
        $declared = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter($reflection->getMethods(), static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class),
        );
        $source = (string) file_get_contents((string) $reflection->getFileName());

        expect($reflection->isFinal())->toBeTrue()
            ->and(array_diff($declared, $allowed))->toBe([])
            ->and(preg_match('/(?<![\w>:$])(__|trans|trans_choice)\s*\(|Lang::/', $source))->toBe(0);
    }
});

it('still refuses an internal comment for a Partner in both classes that carry a comment', function (string $class): void {
    [, $url] = notifMarkupAudience('partner');

    expect(fn () => new $class('ABC-1', 'Example title', 'ABC', 'Jane Example', null, $url, true, true))
        ->toThrow(LogicException::class);
})->with([TaskCommentedNotification::class, TaskEscalatedNotification::class]);
