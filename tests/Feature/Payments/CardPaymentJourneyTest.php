<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Indicators\StripeConfigIndicator;
use App\Domain\Operations\Health\Indicators\UnprocessedWebhooksIndicator;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use App\Filament\Resources\CardPaymentResource\Pages\ListCardPayments;
use App\Filament\Resources\CardPaymentResource\Pages\ViewCardPayment;
use App\Filament\Widgets\UnresolvedPayments;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;
use Tests\Support\StripeEvents;
use Tests\Support\StripeWebhookSigner;

/*
 * The whole card-payment flow of Phase 11 in one readable test (ROADMAP Phase 11 goal and success criteria 1 to 5):
 * an invoice issued with card payment on gets its Stripe link in the background, its PDF and e-mail carry the stable
 * URL, the URL leads to Stripe, the signed payment event books the payment and its income and switches the link off,
 * the pay page then says "paid", the Partner button is gone, a second payment is a duplicate that books nothing and
 * alerts the Admin, shows in the overview and on the dashboard and is resolved by the Admin. The gateway is the
 * in-memory fake and the signature is computed by hand; every name, amount and Stripe reference is fictional or
 * assembled at runtime.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    config(['app.url' => 'https://kokpit.example.com']);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    Mail::getSymfonyTransport()->flush();
});

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function journeySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The link row of the document.
 */
function journeyLink(Invoice $invoice): PaymentLink
{
    return journeySystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->firstOrFail());
}

/**
 * The bytes of the PDF stored at issue.
 */
function journeyPdf(Invoice $invoice): string
{
    return journeySystem(static function () use ($invoice): string {
        $documentId = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');
        $media = Document::query()->whereKey($documentId)->firstOrFail()->getFirstMedia(Document::MEDIA_COLLECTION);

        return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
    });
}

/**
 * The messages the array transport holds, as Symfony e-mails.
 *
 * @return list<Email>
 */
function journeyMails(): array
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
 * The titles in the bell of the user, oldest first.
 *
 * @return list<string>
 */
function journeyBellTitles(object $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->getKey())->orderBy('created_at')->orderBy('id')->get()
        ->map(static fn (object $row): string => (string) (json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR)['title'] ?? ''))
        ->all();
}

/**
 * The visible text of rendered markup: icons, comments and scripts removed, tags stripped, white space collapsed.
 */
function journeyText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

/**
 * The card payment records, oldest first.
 *
 * @return list<CardPayment>
 */
function journeyRecords(): array
{
    return journeySystem(static fn (): array => CardPayment::query()->orderBy('created_at')->orderBy('id')->get()->all());
}

it('walks an invoice from issue to a resolved duplicate: link, PDF, e-mail, pay page, payment, income, alert, overview, dashboard', function (): void {
    // --- Issue: online payments are on, the client has the flag, the Admin issues a draft -----------------------------
    $client = CardPaymentSetup::client(['invoice_email' => 'invoice@example.com']);
    $partner = Canary::partnerFor($client->id);
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '12500.00')], actor: $this->admin);

    expect(journeySystem(static fn (): int => PaymentLink::query()->count()))->toBe(0);

    $invoice = app(IssueDocument::class)->handle($this->admin, $draft);

    // --- The link was created in the background (the queue is synchronous in tests) ------------------------------------
    $link = journeyLink($invoice);

    expect($link->state)->toBe(PaymentLinkState::Active)
        ->and($link->amount_minor)->toBe(1_250_000)
        ->and($link->currency)->toBe('CZK')
        ->and($link->stripe_link_id)->not->toBeNull()
        ->and($link->stripe_url)->toStartWith('https://')
        ->and($this->stripe->created())->toHaveCount(1);

    // --- The PDF carries the lead line, the button and the stable URL, never the Stripe address -------------------------
    $url = PayUrl::forInvoice($invoice);
    $pdf = journeyPdf($invoice);

    expect($url)->toStartWith('https://kokpit.example.com/pay/')
        ->and($pdf)->toContain('/URI')
        ->and($pdf)->toContain($url)
        ->and($pdf)->not->toContain('stripe.com');

    // Ghostscript reads the printed words back; it is installed in DDEV. Without it only the structural checks above run.
    $text = PdfText::flat($pdf);

    if ($text !== null) {
        expect($text)->toContain('Nebo zaplaťte online kartou:')
            ->and($text)->toContain('Zaplatit kartou')
            ->and($text)->toContain($url)
            ->and($text)->not->toContain('stripe.com');
    }

    // --- The e-mail ends with the pay block of the same stable URL ---------------------------------------------------------
    $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);

    app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, [
        'recipient' => 'invoice@example.com',
        'subject' => $defaults['subject'],
        'body' => $defaults['body'],
        'attach_work_report' => false,
    ]);

    $toClient = array_values(array_filter(journeyMails(), static fn (Email $mail): bool => $mail->getTo()[0]->getAddress() === 'invoice@example.com' && $mail->getSubject() === $defaults['subject']));

    expect($toClient)->toHaveCount(1)
        ->and(rtrim((string) $toClient[0]->getTextBody()))->toEndWith("\n\nDoklad můžete zaplatit online kartou:\n".$url)
        ->and((string) $toClient[0]->getTextBody())->not->toContain('stripe.com');

    // --- The stable URL leads to the Stripe link of the document ----------------------------------------------------------------
    $path = (string) parse_url($url, PHP_URL_PATH);

    app('auth')->forgetGuards();
    $this->get($path)->assertStatus(302)->assertRedirect((string) $link->stripe_url);

    // The Partner of the client is offered the button while the document can be paid.
    $this->actingAs($partner);
    Livewire::test(ViewPartnerInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionVisible('payByCard')
        ->assertSee('Zaplatit kartou');

    // --- The signed payment event books the payment ------------------------------------------------------------------------------
    app('auth')->forgetGuards();

    $first = StripeEvents::checkoutFor($link);

    StripeWebhookSigner::post($this, $first)->assertOk();

    $records = journeyRecords();
    $payments = journeySystem(static fn (): array => InvoicePayment::query()->where('invoice_id', $invoice->id)->get()->all());
    $incomes = journeySystem(static fn (): array => Transaction::query()->where('invoice_id', $invoice->id)->get()->all());

    expect($records)->toHaveCount(1)
        ->and($records[0]->type)->toBe(CardPaymentType::Matched)
        ->and($payments)->toHaveCount(1)
        ->and($incomes)->toHaveCount(1)
        ->and($records[0]->invoice_payment_id)->toBe($payments[0]->id)
        ->and(journeySystem(fn (): ?int => app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining']))->toBe(0);

    $link = journeyLink($invoice);

    expect($link->state)->toBe(PaymentLinkState::Deactivated)
        ->and($link->deactivation_reason)->toBe(PaymentLinkDeactivation::Paid)
        ->and($this->stripe->deactivated())->toContain($link->stripe_link_id)
        ->and(journeyBellTitles($this->admin))->toContain('Přišla platba kartou');

    // --- The pay page now says "paid", and the Partner button is gone ------------------------------------------------------------------
    $this->get($path)->assertOk()->assertSee('Doklad je zaplacený');

    $this->actingAs($partner);
    Livewire::test(ViewPartnerInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionHidden('payByCard')
        ->assertDontSee('Zaplatit kartou');

    // --- A second payment of the paid invoice is a duplicate: no second income, the Admin is alerted -----------------------------------
    app('auth')->forgetGuards();
    Mail::getSymfonyTransport()->flush();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $records = journeyRecords();

    expect($records)->toHaveCount(2)
        ->and($records[1]->type)->toBe(CardPaymentType::Duplicate)
        ->and($records[1]->invoice_payment_id)->toBeNull()
        ->and(journeySystem(static fn (): int => InvoicePayment::query()->where('invoice_id', $invoice->id)->count()))->toBe(1)
        ->and(journeySystem(static fn (): int => Transaction::query()->where('invoice_id', $invoice->id)->count()))->toBe(1)
        ->and(journeyBellTitles($this->admin))->toContain('Duplicitní platba kartou');

    $alerts = array_values(array_filter(journeyMails(), static fn (Email $mail): bool => $mail->getSubject() === 'Kokpit: Duplicitní platba kartou'));

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->getTo()[0]->getAddress())->toBe($this->admin->email);

    // --- The overview and the dashboard show it ---------------------------------------------------------------------------------------------
    $this->actingAs($this->admin);

    $tabs = Livewire::test(ListCardPayments::class)->assertSet('activeTab', CardPaymentTab::Unresolved->value)->instance()->getTabs();

    expect((string) $tabs[CardPaymentTab::Unresolved->value]->getBadge())->toBe('1');

    $dashboard = journeyText((string) $this->get('/admin')->assertOk()->getContent());

    expect($dashboard)->toContain('Nevyřešené platby')
        ->and(UnresolvedPayments::canView())->toBeTrue();

    // --- The Admin resolves it with a note ------------------------------------------------------------------------------------------------------
    Livewire::test(ViewCardPayment::class, ['record' => $records[1]->getRouteKey()])
        ->mountAction('resolveCardPayment')
        ->fillForm(['note' => 'Returned in the Stripe dashboard (fictional).'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $resolved = journeySystem(static fn (): CardPayment => CardPayment::query()->findOrFail($records[1]->id));

    expect($resolved->resolution)->toBe(CardPaymentResolution::Noted)
        ->and($resolved->resolution_note)->toBe('Returned in the Stripe dashboard (fictional).')
        ->and(UnresolvedPayments::canView())->toBeFalse()
        ->and(journeyText((string) $this->get('/admin')->assertOk()->getContent()))->not->toContain('Nevyřešené platby');

    $tabs = Livewire::test(ListCardPayments::class)->instance()->getTabs();

    expect($tabs[CardPaymentTab::Unresolved->value]->getBadge())->toBeNull();

    // --- Nothing is left waiting, and the System page says so ----------------------------------------------------------------------------------------
    expect((new UnprocessedWebhooksIndicator)->check()->status)->toBe(HealthStatus::Ok)
        ->and((new StripeConfigIndicator)->check()->status)->toBe(HealthStatus::Ok)
        ->and(journeySystem(static fn (): int => InvoicePayment::query()->where('invoice_id', $invoice->id)->count()))->toBe(1)
        ->and(journeySystem(static fn (): int => Transaction::query()->where('invoice_id', $invoice->id)->count()))->toBe(1);
});
