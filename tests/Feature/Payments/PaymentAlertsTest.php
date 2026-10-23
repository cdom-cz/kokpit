<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RecordCardPayment;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\CardPaymentResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeEvents;
use Tests\Support\StripeWebhookSigner;

/*
 * The Admin notices of a duplicate and of an unmatched payment (PA-07, PA-08; D-11, D-12; UI-SPEC Surface I; research
 * Open Question 2): bell and mail for every Admin, one notice per record, sent after the commit, free of any Stripe
 * reference or payer data. Every name, amount and reference is fictional or assembled at runtime.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->admin = Canary::admin();
    Canary::admin();
});

/**
 * Every Admin the application has, read as system.
 *
 * @return list<User>
 */
function paAdmins(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => User::query()->role(RoleName::Admin->value)->get()->all());
}

/**
 * An issued card-on invoice of 12 500,00 Kč and its link.
 *
 * @return array{0: Invoice, 1: PaymentLink}
 */
function paInvoice(): array
{
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->firstOrFail());

    return [$invoice, $link];
}

/**
 * Every card payment record, oldest first, read as system.
 *
 * @return list<CardPayment>
 */
function paRecords(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => CardPayment::query()->orderBy('created_at')->orderBy('id')->get()->all());
}

/**
 * The bell rows of one user as decoded Filament messages.
 *
 * @return list<array<string, mixed>>
 */
function paBell(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->getKey())->orderBy('created_at')->orderBy('id')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR))
        ->all();
}

/**
 * The bell rows of one user with the given title.
 *
 * @return list<array<string, mixed>>
 */
function paBellTitled(User $user, string $title): array
{
    return array_values(array_filter(paBell($user), static fn (array $row): bool => ($row['title'] ?? null) === $title));
}

/**
 * Subject, recipient and the whole text of every mail the array transport holds.
 *
 * @return list<array{subject: string, to: string, text: string}>
 */
function paMails(): array
{
    $mails = [];

    foreach (Mail::getSymfonyTransport()->messages() as $sent) {
        $original = $sent->getOriginalMessage();
        assert($original instanceof Email);
        $mails[] = [
            'subject' => (string) $original->getSubject(),
            'to' => $original->getTo()[0]->getAddress(),
            'text' => (string) $original->getTextBody().(string) $original->getHtmlBody(),
        ];
    }

    return $mails;
}

/**
 * The mails addressed to an Admin: the alerts. An e-mail to anyone else (a client) is not among them.
 *
 * @return list<array{subject: string, to: string, text: string}>
 */
function paAdminMails(): array
{
    $addresses = array_map(static fn (User $admin): string => $admin->email, paAdmins());

    return array_values(array_filter(paMails(), static fn (array $mail): bool => in_array($mail['to'], $addresses, true)));
}

/**
 * The mails with the given subject.
 *
 * @return list<array{subject: string, to: string, text: string}>
 */
function paMailsTitled(string $subject): array
{
    return array_values(array_filter(paMails(), static fn (array $mail): bool => $mail['subject'] === $subject));
}

// --- Task 1: a duplicate ------------------------------------------------------------------------------------------

it('alerts every Admin in the bell and by mail about a second payment of a paid invoice, with a link to the duplicate record', function (): void {
    [$invoice, $link] = paInvoice();
    $partner = Canary::partnerFor($invoice->client_id);

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $second = StripeEvents::checkoutFor($link);
    StripeWebhookSigner::post($this, $second)->assertOk();

    $records = paRecords();
    $duplicate = $records[1];
    $url = CardPaymentResource::getUrl('view', ['record' => $duplicate]);
    $amount = Money::ofMinor(1_250_000, 'CZK')->format('cs');
    $body = 'Doklad '.$invoice->number.' už byl zaplacený a přišla další platba '.$amount.'. Příjem nevznikl. Vyřešte ji v přehledu plateb.';

    expect(preg_replace('/\s+/u', ' ', $amount))->toBe('12 500,00 Kč');

    expect(count(paAdmins()))->toBeGreaterThanOrEqual(2);

    foreach (paAdmins() as $admin) {
        $rows = paBellTitled($admin, 'Duplicitní platba kartou');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['body'])->toBe($body)
            ->and($rows[0]['status'])->toBe('danger')
            ->and($rows[0]['actions'][0]['label'])->toBe('Otevřít platbu')
            ->and($rows[0]['actions'][0]['url'])->toBe($url);
    }

    $mails = paMailsTitled('Kokpit: Duplicitní platba kartou');

    expect($mails)->toHaveCount(count(paAdmins()))
        ->and(array_column($mails, 'to'))->toEqualCanonicalizing(array_map(static fn (User $admin): string => $admin->email, paAdmins()))
        ->and($mails[0]['text'])->toContain($body)
        ->and($mails[0]['text'])->toContain($url);

    $everything = json_encode([paBell(paAdmins()[0]), $mails], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($everything)
        ->not->toContain($second['data']['object']['id'])
        ->not->toContain($second['data']['object']['payment_intent'])
        ->not->toContain($second['id'])
        ->not->toContain('jane@example.com')
        ->not->toContain('Jane Example')
        ->not->toContain((string) $invoice->client->name)
        ->and(paBell($partner))->toBe([])
        ->and(DB::table('notifications')->where('notifiable_id', $partner->getKey())->count())->toBe(0);
});

// --- Task 2: unmatched payments, one notice per record, matches and rollbacks ---------------------------------------

/**
 * The event of each unmatched case, built after the invoice exists. The document is cancelled for `cancelled`.
 *
 * @return array{0: array<string, mixed>, 1: string, 2: CardPaymentReason}
 */
function paUnmatchedEvent(string $case, Invoice $invoice, PaymentLink $link, User $admin): array
{
    return match ($case) {
        'amount' => [StripeEvents::checkoutFor($link, ['amount_total' => 1_200_000]), Money::ofMinor(1_200_000, 'CZK')->format('cs'), CardPaymentReason::Amount],
        'currency' => [StripeEvents::checkoutFor($link, ['currency' => 'eur']), Money::ofMinor(1_250_000, 'EUR')->format('cs'), CardPaymentReason::Currency],
        'cancelled' => (function () use ($invoice, $link, $admin): array {
            test()->actingAs($admin);
            app(IssueCreditNote::class)->storno($admin, $invoice);

            return [StripeEvents::checkoutFor($link), Money::ofMinor(1_250_000, 'CZK')->format('cs'), CardPaymentReason::Cancelled];
        })(),
        'undetermined' => [StripeEvents::checkoutCompleted(['amount_total' => 121_000, 'currency' => 'czk']), Money::ofMinor(121_000, 'CZK')->format('cs'), CardPaymentReason::Undetermined],
    };
}

it('alerts every Admin about an unmatched payment with its amount in its currency and the label of its reason', function (string $case): void {
    [$invoice, $link] = paInvoice();
    [$event, $amount, $reason] = paUnmatchedEvent($case, $invoice, $link, $this->admin);

    StripeWebhookSigner::post($this, $event)->assertOk();

    $records = paRecords();
    $url = CardPaymentResource::getUrl('view', ['record' => $records[0]]);
    $body = 'Přišla platba '.$amount.', kterou nelze automaticky zapsat ('.$reason->getLabel().'). Příjem nevznikl. Vyřešte ji v přehledu plateb.';

    expect($records)->toHaveCount(1)
        ->and($records[0]->type)->toBe(CardPaymentType::Unmatched)
        ->and($records[0]->reason)->toBe($reason);

    foreach (paAdmins() as $admin) {
        $rows = paBellTitled($admin, 'Nespárovaná platba kartou');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['body'])->toBe($body)
            ->and($rows[0]['actions'][0]['label'])->toBe('Otevřít platbu')
            ->and($rows[0]['actions'][0]['url'])->toBe($url);
    }

    $mails = paMailsTitled('Kokpit: Nespárovaná platba kartou');

    expect($mails)->toHaveCount(count(paAdmins()))
        ->and($mails[0]['text'])->toContain($body)
        ->and($mails[0]['text'])->toContain($url)
        ->and(json_encode([paBell(paAdmins()[0]), $mails], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->not->toContain($event['data']['object']['id'])
        ->not->toContain($event['data']['object']['payment_intent'])
        ->not->toContain('jane@example.com')
        ->not->toContain('Jane Example');
})->with(['amount', 'currency', 'cancelled', 'undetermined']);

it('sends one notice per record, so two duplicates within a minute are two notices and nothing is merged', function (): void {
    [$invoice, $link] = paInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $urls = array_map(static fn (CardPayment $record): string => CardPaymentResource::getUrl('view', ['record' => $record]), array_slice(paRecords(), 1));

    foreach (paAdmins() as $admin) {
        $rows = paBellTitled($admin, 'Duplicitní platba kartou');

        expect($rows)->toHaveCount(2)
            ->and(array_map(static fn (array $row): string => $row['actions'][0]['url'], $rows))->toEqualCanonicalizing($urls)
            ->and($rows[0]['body'])->not->toContain('zadrženo')
            ->and($rows[1]['body'])->not->toContain('zadrženo');
    }

    expect(paMailsTitled('Kokpit: Duplicitní platba kartou'))->toHaveCount(2 * count(paAdmins()));
});

it('gives a matched payment its bell notice only and no problem notice or mail', function (): void {
    [$invoice, $link] = paInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    foreach (paAdmins() as $admin) {
        expect(array_column(paBell($admin), 'title'))->toBe(['Přišla platba kartou']);
    }

    expect(paAdminMails())->toBe([]);
});

it('notifies nobody when the transaction of a classified duplicate or unmatched payment is rolled back', function (): void {
    [$invoice, $link] = paInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    foreach ([StripeEvents::checkoutFor($link), StripeEvents::checkoutFor($link, ['amount_total' => 1_200_000]), StripeEvents::checkoutCompleted()] as $event) {
        try {
            app(PartnerContext::class)->runAsSystem(static fn () => DB::transaction(static function () use ($event): void {
                expect(app(RecordCardPayment::class)->handle(StripeEvent::fromPayload($event)))->toBeInstanceOf(CardPayment::class);

                throw new RuntimeException('forced after the record was claimed');
            }));
        } catch (RuntimeException) {
            // The failure after the claim is the point of the test.
        }
    }

    expect(paRecords())->toHaveCount(1);

    foreach (paAdmins() as $admin) {
        expect(array_column(paBell($admin), 'title'))->toBe(['Přišla platba kartou']);
    }

    expect(paAdminMails())->toBe([]);
});
