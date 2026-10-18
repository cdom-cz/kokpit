<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeGatewayFailure;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\InvoiceResource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeTestConfig;

/*
 * The queued job that creates the single-use Stripe payment link of an issued document (PA-01; D-05 to D-09, research
 * Pattern 2). The gateway is the in-memory fake; every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * The newest link row of a document, read as a system run.
 */
function linkRowOf(Invoice $invoice): PaymentLink
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail());
}

/**
 * Runs the job in this process through its middleware, as a worker does for one attempt. The sync queue driver is not
 * used: it would report a failure to failed() at once, which a real queue does only after the last attempt.
 */
function runLinkJob(string $linkId): void
{
    app(Dispatcher::class)->dispatchNow(new CreatePaymentLink($linkId));
}

/**
 * The number of link rows in the whole database.
 */
function linkRowCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => PaymentLink::query()->count());
}

it('creates the single-use Stripe link of an issued invoice through the queued job, for its total', function (): void {
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = linkRowOf($issued);

    expect($link->state)->toBe(PaymentLinkState::Active)
        ->and($link->amount_minor)->toBe($issued->total_minor)
        ->and($link->currency)->toBe('CZK')
        ->and($link->activated_at)->not->toBeNull()
        ->and($link->deactivated_at)->toBeNull()
        ->and($link->failure_class)->toBeNull();

    $created = $this->stripe->created();

    expect($created)->toHaveCount(1);

    $request = $created[0];

    expect($request->idempotencyKey)->toBe($link->id)
        ->and($request->amountMinor)->toBe($issued->total_minor)
        ->and($request->currency)->toBe('CZK')
        ->and($request->productName)->toBe('Faktura '.$issued->number)
        ->and($request->invoiceId)->toBe($issued->id)
        ->and($request->linkRowId)->toBe($link->id)
        ->and($request->confirmationMessage)->toBe('Děkujeme, platba byla přijata. Doklad '.$issued->number.' se označí jako zaplacený během několika minut.')
        ->and($request->inactiveMessage)->toBe('Tento odkaz na platbu už není aktivní. Použijte odkaz z dokladu nebo kontaktujte dodavatele.')
        ->and($link->stripe_link_id)->not->toBeNull()
        ->and($link->stripe_url)->toStartWith('https://');
});

it('writes one pending row for the total in the issue transaction and pushes the job only after the commit', function (): void {
    Queue::fake();

    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = linkRowOf($issued);

    expect($link->state)->toBe(PaymentLinkState::Pending)
        ->and($link->amount_minor)->toBe($issued->total_minor)
        ->and($link->created_by)->not->toBeNull();

    Queue::assertPushed(CreatePaymentLink::class, 1);
    Queue::assertPushed(CreatePaymentLink::class, static fn (CreatePaymentLink $job): bool => $job->linkId === $link->id);
    expect($this->stripe->createCalls())->toBe(0);
});

it('runs no job and leaves no row when the issue transaction is rolled back after the link was opened', function (): void {
    $client = CardPaymentSetup::client();
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $admin = Canary::admin();

    $attempt = static fn () => DB::transaction(static function () use ($draft, $admin): void {
        $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();
        app(DocumentIssuer::class)->issue($locked, IssueContext::now($admin));

        throw new RuntimeException('Forced failure after the issue.');
    });

    expect($attempt)->toThrow(RuntimeException::class);

    expect(linkRowCount())->toBe(0)
        ->and($this->stripe->createCalls())->toBe(0);
});

it('gives a document issued with the switch off neither a row nor a job', function (): void {
    Queue::fake();

    $client = CardPaymentSetup::client(['online_payment_enabled' => false]);
    $issued = CardPaymentSetup::issued($client);

    Queue::assertNothingPushed();
    expect(linkRowCount())->toBe(0)
        ->and($issued->admin?->card_payment_enabled)->toBeFalse()
        ->and($this->stripe->createCalls())->toBe(0);
});

it('gives a credit note neither a row nor a job', function (): void {
    Queue::fake();

    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    Queue::assertPushed(CreatePaymentLink::class, 1);

    InvoiceFixtures::stornoed($issued);

    Queue::assertPushed(CreatePaymentLink::class, 1);
    expect(linkRowCount())->toBe(1);
});

it('names the proforma in the product of its link', function (): void {
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);

    expect($this->stripe->created())->toHaveCount(1)
        ->and($this->stripe->created()[0]->productName)->toBe('Zálohová faktura '.$issued->number);
});

it('does not create a second Stripe link when the job runs again for an active row', function (): void {
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = linkRowOf($issued);
    $before = $link->updated_at;

    runLinkJob($link->id);

    $after = linkRowOf($issued);

    expect($this->stripe->createCalls())->toBe(1)
        ->and($after->state)->toBe(PaymentLinkState::Active)
        ->and($after->stripe_link_id)->toBe($link->stripe_link_id)
        ->and($after->updated_at?->equalTo($before))->toBeTrue();
});

it('ignores an id that is no UUID and an id of no row', function (): void {
    runLinkJob('not-a-uuid');
    runLinkJob((string) Str::uuid7());

    expect($this->stripe->createCalls())->toBe(0);
});

/**
 * Every Admin as a system run.
 *
 * @return list<User>
 */
function everyAdmin(): array
{
    /** @var list<User> $admins */
    $admins = app(PartnerContext::class)->runAsSystem(static fn (): array => User::query()->role(RoleName::Admin->value)->get()->all());

    return $admins;
}

/**
 * The bell rows of one user as decoded Filament messages.
 *
 * @return list<array<string, mixed>>
 */
function bellOf(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->getKey())->orderBy('created_at')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR))
        ->all();
}

/**
 * Subject, text and HTML of every mail the array transport holds.
 *
 * @return list<array{subject: string, text: string}>
 */
function mailsSent(): array
{
    $mails = [];

    foreach (Mail::getSymfonyTransport()->messages() as $sent) {
        $original = $sent->getOriginalMessage();
        assert($original instanceof Email);
        $mails[] = ['subject' => (string) $original->getSubject(), 'text' => (string) $original->getTextBody().(string) $original->getHtmlBody()];
    }

    return $mails;
}

/**
 * Issues a card-on invoice on a queue that discards every job, so the link row stays pending for the test to drive the
 * job itself.
 */
function pendingLinkDocument(array $clientOverrides = [], InvoiceKind $kind = InvoiceKind::Invoice): Invoice
{
    config(['queue.connections.discard' => ['driver' => 'null'], 'queue.default' => 'discard']);
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client($clientOverrides), $kind);
    config(['queue.default' => 'sync']);

    return $issued;
}

describe('a Stripe failure', function (): void {
    it('leaves the document issued, fails the row with a fixed class and tells every Admin by bell and mail without any provider detail', function (): void {
        $this->stripe->failNextCreate(StripeGatewayFailure::INVALID_REQUEST);
        $client = CardPaymentSetup::client();
        $issued = CardPaymentSetup::issued($client);
        $link = linkRowOf($issued);

        $fresh = Invoice::query()->with('admin')->findOrFail($issued->id);

        expect($fresh->isIssued())->toBeTrue()
            ->and($fresh->admin?->pdf_document_id)->not->toBeNull()
            ->and($link->state)->toBe(PaymentLinkState::Failed)
            ->and($link->failure_class)->toBe('invalid_request')
            ->and($link->stripe_link_id)->toBeNull()
            ->and($link->stripe_url)->toBeNull();

        $admins = everyAdmin();
        $url = InvoiceResource::getUrl('view', ['record' => $issued->id]);

        expect($admins)->not->toBeEmpty();

        foreach ($admins as $admin) {
            $rows = bellOf($admin);

            expect($rows)->toHaveCount(1)
                ->and($rows[0]['title'])->toBe('Odkaz na platbu kartou se nepodařilo vytvořit')
                ->and($rows[0]['body'])->toBe('Doklad '.$issued->number.' zatím nemá odkaz na platbu. Klient vidí informační stránku. Vytvořte odkaz znovu v detailu dokladu.')
                ->and($rows[0]['actions'][0]['label'])->toBe('Otevřít doklad')
                ->and($rows[0]['actions'][0]['url'])->toBe($url);
        }

        $mails = mailsSent();

        expect($mails)->toHaveCount(count($admins));

        $everything = json_encode([$mails, array_map(bellOf(...), $admins)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($mails as $mail) {
            expect($mail['subject'])->toBe('Kokpit: Odkaz na platbu kartou se nepodařilo vytvořit')
                ->and($mail['text'])->toContain($issued->number)
                ->and($mail['text'])->toContain($url);
        }

        expect($everything)->not->toContain('Stripe request failed')
            ->and($everything)->not->toContain('invalid_request')
            ->and($everything)->not->toContain('plink_')
            ->and($everything)->not->toContain($client->name);
    });

    it('is final at the first attempt for a 5xx class, a rejected request and a missing key', function (string $class): void {
        $this->stripe->failNextCreate($class);
        $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
        $link = linkRowOf($issued);

        expect($link->state)->toBe(PaymentLinkState::Failed)
            ->and($link->failure_class)->toBe($class)
            ->and($this->stripe->createCalls())->toBe(1);
    })->with([StripeGatewayFailure::API_ERROR, StripeGatewayFailure::AUTHENTICATION, StripeGatewayFailure::IDEMPOTENCY]);

    it('rethrows a lost connection at the first attempt and creates exactly one Stripe link when the same row runs again', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);
        $this->stripe->failNextCreate(StripeGatewayFailure::CONNECTION);

        expect(fn () => runLinkJob($link->id))->toThrow(StripeGatewayFailure::class);

        $pending = linkRowOf($issued);

        expect($pending->state)->toBe(PaymentLinkState::Pending)
            ->and($pending->failure_class)->toBeNull()
            ->and(mailsSent())->toBe([]);

        runLinkJob($link->id);

        $active = linkRowOf($issued);

        expect($active->state)->toBe(PaymentLinkState::Active)
            ->and($this->stripe->createCalls())->toBe(2)
            ->and($this->stripe->created())->toHaveCount(1)
            ->and($this->stripe->created()[0]->idempotencyKey)->toBe($link->id);
    });

    it('does not rethrow a rate limit on the last attempt but fails the row and alerts', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);
        $this->stripe->failNextCreate(StripeGatewayFailure::RATE_LIMITED);

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(3);
        $queueJob->shouldReceive('maxTries')->andReturn(3);

        $job = new CreatePaymentLink($link->id);
        $job->setJob($queueJob);
        app(PartnerContext::class)->runAsSystem(static fn () => app()->call([$job, 'handle']));

        $failed = linkRowOf($issued);

        expect($failed->state)->toBe(PaymentLinkState::Failed)
            ->and($failed->failure_class)->toBe('rate_limited')
            ->and(mailsSent())->toHaveCount(count(everyAdmin()));
    });

    it('rethrows a rate limit on an earlier attempt', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);
        $this->stripe->failNextCreate(StripeGatewayFailure::RATE_LIMITED);

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $queueJob->shouldReceive('maxTries')->andReturn(3);

        $job = new CreatePaymentLink($link->id);
        $job->setJob($queueJob);

        expect(fn () => app(PartnerContext::class)->runAsSystem(static fn () => app()->call([$job, 'handle'])))->toThrow(StripeGatewayFailure::class)
            ->and(linkRowOf($issued)->state)->toBe(PaymentLinkState::Pending);
    });

    it('fails the row with the class of the failure, or unexpected, once the queue gives up, and alerts once', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);

        (new CreatePaymentLink($link->id))->failed(new RuntimeException('Database exploded with a secret detail'));
        (new CreatePaymentLink($link->id))->failed(StripeGatewayFailure::connection());

        $failed = linkRowOf($issued);

        expect($failed->state)->toBe(PaymentLinkState::Failed)
            ->and($failed->failure_class)->toBe('unexpected')
            ->and(mailsSent())->toHaveCount(count(everyAdmin()))
            ->and(json_encode(mailsSent(), JSON_THROW_ON_ERROR))->not->toContain('secret detail');

        $other = pendingLinkDocument();
        (new CreatePaymentLink(linkRowOf($other)->id))->failed(StripeGatewayFailure::connection());

        expect(linkRowOf($other)->failure_class)->toBe('connection');
    });

    it('leaves an active row alone when the queue reports a failure', function (): void {
        $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
        $link = linkRowOf($issued);

        (new CreatePaymentLink($link->id))->failed(new RuntimeException('Late failure'));

        expect(linkRowOf($issued)->state)->toBe(PaymentLinkState::Active)
            ->and(mailsSent())->toBe([]);
    });

    it('fails the row as not configured, with an alert, when the Stripe keys are gone at job time', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);
        StripeTestConfig::clear();

        runLinkJob($link->id);

        $failed = linkRowOf($issued);

        expect($failed->state)->toBe(PaymentLinkState::Failed)
            ->and($failed->failure_class)->toBe('not_configured')
            ->and($this->stripe->createCalls())->toBe(0)
            ->and(mailsSent())->toHaveCount(count(everyAdmin()));
    });

    it('treats a returned address outside Stripe as an api_error and stores no URL', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);
        $this->stripe->answerWithUrl('https://'.implode('.', ['pay', 'example', 'net']).'/'.Str::random(8));

        runLinkJob($link->id);

        $failed = linkRowOf($issued);

        expect($failed->state)->toBe(PaymentLinkState::Failed)
            ->and($failed->failure_class)->toBe('api_error')
            ->and($failed->stripe_url)->toBeNull();
    });

    it('fails the row as unsupported_currency for a currency Stripe counts differently, without calling Stripe', function (): void {
        app(PartnerContext::class)->runAsSystem(static function (): void {
            $banks = app(BankAccountSettings::class);
            $banks->accounts = [...$banks->accounts, new BankAccount(BankAccountFormat::Europe2, 'Example ISK account', 'ISK', iban: FictionalIban::czech('56'))];
            $banks->save();
        });
        app()->forgetScopedInstances();
        InvoiceFixtures::cnbRate('ISK', '2026-10-14', '17.5000000000', 100);

        $issued = CardPaymentSetup::issued(CardPaymentSetup::client(['currency' => 'ISK']));
        $link = linkRowOf($issued);

        expect($issued->currency)->toBe('ISK')
            ->and($link->state)->toBe(PaymentLinkState::Failed)
            ->and($link->failure_class)->toBe('unsupported_currency')
            ->and($this->stripe->createCalls())->toBe(0)
            ->and(mailsSent())->toHaveCount(count(everyAdmin()));
    });

    it('sends an English document the English product name and messages', function (): void {
        $issued = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_language' => 'en']));
        $request = $this->stripe->created()[0];

        expect($request->productName)->toBe('Invoice '.$issued->number)
            ->and($request->confirmationMessage)->toBe('Thank you, your payment was received. Document '.$issued->number.' will be marked as paid within a few minutes.')
            ->and($request->inactiveMessage)->toBe('This payment link is no longer active. Use the link on your document or contact the supplier.');
    });
});

describe('the operational alert subject', function (): void {
    it('mails the title as subject unless a mail subject is given, and keeps it when a line is added', function (): void {
        $user = new User;
        $plain = new OperationalAlert('Fictional title', 'Fictional body');
        $custom = new OperationalAlert('Fictional title', 'Fictional body', null, null, 'Kokpit: Fictional title');

        expect($plain->toMail($user)->subject)->toBe('Fictional title')
            ->and($custom->toMail($user)->subject)->toBe('Kokpit: Fictional title')
            ->and($custom->withLine('More')->toMail($user)->subject)->toBe('Kokpit: Fictional title')
            ->and($custom->withLine('More')->body)->toBe("Fictional body\nMore");
    });
});

/**
 * Records a payment of the given amount on the document as the Admin.
 */
function payOn(Invoice $invoice, string $amount): void
{
    app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);
}

describe('a change of the document around the Stripe call (D-08)', function (): void {
    it('deactivates the pending row without calling Stripe when a payment, a full payment or a storno landed before the call', function (string $case, string $reason): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);

        match ($case) {
            'partial' => payOn($issued, '5000.00'),
            'full' => payOn($issued, '12500.00'),
            'storno' => app(IssueCreditNote::class)->storno(Canary::admin(), $issued),
        };

        runLinkJob($link->id);

        $row = linkRowOf($issued);

        expect($row->state)->toBe(PaymentLinkState::Deactivated)
            ->and($row->deactivation_reason?->value)->toBe($reason)
            ->and($row->deactivated_at)->not->toBeNull()
            ->and($row->stripe_link_id)->toBeNull()
            ->and($row->stripe_url)->toBeNull()
            ->and($row->remote_deactivated_at)->toBeNull()
            ->and($this->stripe->createCalls())->toBe(0)
            ->and($this->stripe->deactivated())->toBe([])
            ->and(mailsSent())->toBe([]);
    })->with([
        'a partial payment' => ['partial', 'amount_changed'],
        'a full payment' => ['full', 'paid'],
        'a storno' => ['storno', 'cancelled'],
    ]);

    it('keeps the new Stripe link on the row, deactivates it locally and then at Stripe when a payment lands during the call', function (string $case, string $reason): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);

        $this->stripe->onCreate(static function () use ($issued, $case): void {
            match ($case) {
                'partial' => payOn($issued, '5000.00'),
                'full' => payOn($issued, '12500.00'),
                'storno' => app(IssueCreditNote::class)->storno(Canary::admin(), $issued),
            };
        });

        runLinkJob($link->id);

        $row = linkRowOf($issued);
        $created = $this->stripe->created();

        expect($created)->toHaveCount(1)
            ->and($row->state)->toBe(PaymentLinkState::Deactivated)
            ->and($row->deactivation_reason?->value)->toBe($reason)
            ->and($row->stripe_link_id)->not->toBeNull()
            ->and($row->stripe_url)->toStartWith('https://')
            ->and($this->stripe->deactivated())->toBe([$row->stripe_link_id])
            ->and($row->remote_deactivated_at)->not->toBeNull()
            ->and($row->activated_at)->toBeNull()
            ->and(mailsSent())->toBe([]);
    })->with([
        'a partial payment' => ['partial', 'amount_changed'],
        'a full payment' => ['full', 'paid'],
        'a storno' => ['storno', 'cancelled'],
    ]);

    it('leaves the remote deactivation for the sweep when Stripe refuses it, and does not fail the job', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);

        $this->stripe->onCreate(static function () use ($issued): void {
            payOn($issued, '5000.00');
        });
        $this->stripe->failNextDeactivate(StripeGatewayFailure::CONNECTION);

        runLinkJob($link->id);

        $row = linkRowOf($issued);

        expect($row->state)->toBe(PaymentLinkState::Deactivated)
            ->and($row->deactivation_reason?->value)->toBe('amount_changed')
            ->and($row->stripe_link_id)->not->toBeNull()
            ->and($row->remote_deactivated_at)->toBeNull()
            ->and($this->stripe->deactivated())->toBe([]);
    });

    it('keeps the Stripe link on a row that someone deactivated by hand during the call and switches it off at Stripe', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);

        $this->stripe->onCreate(static function () use ($link): void {
            DB::table('payment_links')->where('id', $link->id)->update(['state' => 'deactivated', 'deactivation_reason' => 'manual', 'deactivated_at' => now()]);
        });

        runLinkJob($link->id);

        $row = linkRowOf($issued);

        expect($row->deactivation_reason?->value)->toBe('manual')
            ->and($row->stripe_link_id)->not->toBeNull()
            ->and($this->stripe->deactivated())->toBe([$row->stripe_link_id])
            ->and($row->remote_deactivated_at)->not->toBeNull();
    });

    it('does nothing on a second run for a row that was deactivated before the call', function (): void {
        $issued = pendingLinkDocument();
        $link = linkRowOf($issued);
        payOn($issued, '5000.00');

        runLinkJob($link->id);
        $first = linkRowOf($issued);
        runLinkJob($link->id);
        $second = linkRowOf($issued);

        expect($second->updated_at?->equalTo($first->updated_at))->toBeTrue()
            ->and($this->stripe->createCalls())->toBe(0);
    });
});
