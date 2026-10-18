<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeGatewayFailure;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Payments\Support\StripeUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Http\Middleware\SetNoReferrerPolicy;
use Filament\Facades\Filament;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * The public pay page /pay/{token} (PA-02; D-01, D-02, D-03, UI-SPEC Surface A, U-2, U-3). Every name, number and
 * amount is fictional; Stripe URLs come from the in-memory gateway or are assembled at runtime.
 */

beforeEach(function (): void {
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    config(['app.url' => 'https://kokpit.example.com']);
});

/**
 * The path (without host) of the pay URL of the document, which is what a browser requests.
 */
function payPagePath(Invoice $invoice): string
{
    $url = PayUrl::forInvoice($invoice);
    assert($url !== null);

    return (string) parse_url($url, PHP_URL_PATH);
}

/**
 * A request by a visitor who holds no session: whatever the fixtures signed in is forgotten first.
 */
function payPageVisit(string $path): TestResponse
{
    app('auth')->forgetGuards();

    return test()->get($path);
}

/**
 * The Stripe URL of the live link of the document, as the link job (sync queue, in-memory gateway) stored it.
 */
function payPageLinkUrl(Invoice $invoice): string
{
    return (string) app(PartnerContext::class)->runAsSystem(static fn () => DB::table('payment_links')->where('invoice_id', $invoice->id)->value('stripe_url'));
}

/**
 * Moves the live link to deactivated (manual), as an Admin would.
 */
function payPageDeactivateLink(Invoice $invoice): void
{
    DB::table('payment_links')->where('invoice_id', $invoice->id)->update([
        'state' => 'deactivated',
        'deactivation_reason' => 'manual',
        'deactivated_at' => now(),
    ]);
}

describe('the tracer', function (): void {
    it('sends the client straight to the active Stripe link and renders nothing', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $target = payPageLinkUrl($invoice);

        expect($target)->toStartWith('https://');

        $response = payPageVisit(payPagePath($invoice));

        $response->assertStatus(302)
            ->assertRedirect($target)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Set-Cookie');
        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('says the link is being prepared when there is no active link, with a retry that reloads the same URL', function (): void {
        $client = CardPaymentSetup::client(['name' => 'Example client '.Str::lower(Str::random(6))]);
        $invoice = CardPaymentSetup::issued($client);
        $target = payPageLinkUrl($invoice);
        payPageDeactivateLink($invoice);

        $path = payPagePath($invoice);
        $response = payPageVisit($path);

        $response->assertOk()
            ->assertSee('Odkaz na platbu se připravuje')
            ->assertSee('Doklad č. '.$invoice->number, false)
            ->assertSee('Zkusit znovu')
            ->assertSee('href="'.$path.'"', false)
            ->assertSee(filament()->getBrandName())
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Set-Cookie');
        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

        $body = $response->getContent();
        expect($body)->not->toContain('stripe.com')
            ->and($body)->not->toContain($target)
            ->and($body)->not->toContain($client->name)
            ->and($body)->not->toContain($invoice->total()->format('cs'))
            ->and($body)->not->toContain('<script')
            ->and($body)->not->toContain('wire:')
            ->and($body)->not->toContain('csrf-token');
    });

    it('treats a pending link like a missing one', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        DB::table('payment_links')->where('invoice_id', $invoice->id)->update(['state' => 'deactivated', 'deactivation_reason' => 'replaced', 'deactivated_at' => now()]);
        app(PartnerContext::class)->runAsSystem(static fn () => DB::transaction(static function () use ($invoice): void {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            PaymentLink::open($locked, $locked->total_minor, Canary::admin());
        }));

        payPageVisit(payPagePath($invoice))->assertOk()->assertSee('Odkaz na platbu se připravuje');
    });
});

/**
 * A full or partial payment of the document as the Admin records it.
 */
function payPagePay(Invoice $invoice, string $amount): void
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    app(RecordPayment::class)->handle($admin, $invoice, ['amount' => $amount, 'paid_on' => now()->toDateString()]);
}

/**
 * The storno of the document as the Admin issues it (the visitor of an earlier request is signed out again).
 */
function payPageCancel(Invoice $invoice): void
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    app(IssueCreditNote::class)->storno($admin, $invoice);
}

describe('paid, cancelled and preparing answers (D-02, D-03)', function (): void {
    it('says a fully paid document is paid, with no button, even while a stale active row exists', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        payPagePay($invoice, '12500.00');
        $path = payPagePath($invoice);

        $response = payPageVisit($path);

        $response->assertOk()
            ->assertSee('Doklad je zaplacený')
            ->assertSee('Doklad č. '.$invoice->number.' je zaplacený. Děkujeme.', false)
            ->assertDontSee('Zkusit znovu')
            ->assertDontSee('<a', false);
    });

    it('says a cancelled document was cancelled, with no button', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        payPageCancel($invoice);
        $path = payPagePath($invoice);

        payPageVisit($path)->assertOk()
            ->assertSee('Doklad byl stornován')
            ->assertSee('Doklad č. '.$invoice->number.' byl stornován', false)
            ->assertDontSee('Zkusit znovu');
    });

    it('answers the preparing state after a partial payment, whatever link row is left', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        payPagePay($invoice, '100.00');
        $path = payPagePath($invoice);

        // The link still offers the full amount, which is no longer what is owed: it is never followed (D-08).
        payPageVisit($path)->assertOk()->assertSee('Odkaz na platbu se připravuje')->assertSee('Zkusit znovu');

        payPageDeactivateLink($invoice);

        payPageVisit($path)->assertOk()->assertSee('Odkaz na platbu se připravuje');
    });

    it('answers the preparing state for a failed link', function (): void {
        FakeStripeGateway::install()->failNextCreate(StripeGatewayFailure::API_ERROR);
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        expect(DB::table('payment_links')->where('invoice_id', $invoice->id)->value('state'))->toBe('failed');

        payPageVisit(payPagePath($invoice))->assertOk()
            ->assertSee('Odkaz na platbu se připravuje')
            ->assertDontSee(StripeGatewayFailure::API_ERROR)
            ->assertDontSee('Stripe');
    });

    it('speaks English on an English-language document', function (): void {
        $client = CardPaymentSetup::client(['invoice_language' => 'en']);
        $invoice = CardPaymentSetup::issued($client);
        $path = payPagePath($invoice);

        payPageDeactivateLink($invoice);
        payPageVisit($path)->assertOk()
            ->assertSee('The payment link is being prepared')
            ->assertSee('Document no. '.$invoice->number, false)
            ->assertSee('Try again')
            ->assertSee('lang="en"', false);

        payPagePay($invoice, '12500.00');
        payPageVisit($path)->assertOk()->assertSee('This document is paid')->assertSee('Document no. '.$invoice->number.' is paid. Thank you.', false);

        $other = CardPaymentSetup::issued(CardPaymentSetup::client(['invoice_language' => 'en']));
        $otherPath = payPagePath($other);
        payPageCancel($other);

        payPageVisit($otherPath)->assertOk()->assertSee('This document was cancelled');
    });

    it('answers a proforma like an invoice', function (): void {
        $proforma = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);
        $target = payPageLinkUrl($proforma);

        payPageVisit(payPagePath($proforma))->assertRedirect($target);
    });
});

describe('an invalid link tells nothing (U-2)', function (): void {
    it('answers every invalid cause with the same bytes, in Czech and English', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $real = (string) basename(payPagePath($invoice));
        $tampered = $real;
        $tampered[0] = $tampered[0] === 'a' ? 'b' : 'a';

        $answers = [
            'unknown' => payPageVisit('/pay/'.Str::random(32)),
            'tampered' => payPageVisit('/pay/'.$tampered),
            'short' => payPageVisit('/pay/'.Str::random(31)),
            'long' => payPageVisit('/pay/'.Str::random(33)),
            'symbol' => payPageVisit('/pay/'.Str::random(31).'-'),
            'encoded' => payPageVisit('/pay/'.rawurlencode(Str::random(30).'%%')),
        ];

        foreach ($answers as $answer) {
            $answer->assertOk()
                ->assertSeeInOrder(['Odkaz není platný', 'This link is not valid'])
                ->assertSee('Tento odkaz na platbu nelze použít. Požádejte dodavatele o nový.')
                ->assertSee('This payment link cannot be used. Ask the supplier for a new one.')
                ->assertDontSee('Zkusit znovu')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeaderMissing('Set-Cookie');
            expect((string) $answer->headers->get('Cache-Control'))->toContain('no-store');
        }

        $bodies = array_unique(array_map(static fn (TestResponse $r): string => (string) $r->getContent(), $answers));

        expect($bodies)->toHaveCount(1);
    });

    it('answers a document that is not payable like an unknown token, byte for byte', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $token = (string) basename(payPagePath($invoice));
        $unknown = (string) payPageVisit('/pay/'.Str::random(32))->getContent();

        // A draft that somehow holds a token (the database forbids it; the page must not depend on that).
        $draft = InvoiceFixtures::draft(CardPaymentSetup::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);
        $draftToken = Str::random(32);
        DB::statement('ALTER TABLE invoice_admin DISABLE TRIGGER USER');
        DB::statement('ALTER TABLE invoice_admin DROP CONSTRAINT invoice_admin_pay_token_check');
        DB::table('invoice_admin')->updateOrInsert(['invoice_id' => $draft->id], ['card_payment_enabled' => true, 'pay_token' => $draftToken]);

        expect((string) payPageVisit('/pay/'.$draftToken)->getContent())->toBe($unknown)
            ->and((string) payPageVisit('/pay/'.$token)->getStatusCode())->toBe('302');
    });

    it('runs no database query for a malformed token', function (): void {
        payPageVisit('/pay/short')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        payPageVisit('/pay/'.Str::random(31).'!')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toBe([]);
    });
});

describe('a redirect goes to Stripe only (D-02, T-11-28)', function (): void {
    it('falls back to the preparing state for an active row holding a look-alike host', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $path = payPagePath($invoice);

        // The database CHECK would refuse the row; it is dropped inside this test's own transaction (DDL rolls back with
        // it), so the controller check is proved to stand on its own.
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_stripe_url_check');
        payPageDeactivateLink($invoice);
        DB::table('payment_links')->insert([
            'id' => (string) Str::uuid7(),
            'invoice_id' => $invoice->id,
            'currency' => $invoice->currency,
            'amount_minor' => $invoice->total_minor,
            'state' => 'active',
            'stripe_link_id' => implode('_', ['plink', Str::random(14)]),
            'stripe_url' => 'https://evil'.'stripe.com/'.Str::random(8),
            'activated_at' => now(),
            'created_by' => Canary::admin()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = payPageVisit($path);

        $response->assertOk()->assertSee('Odkaz na platbu se připravuje');
        expect($response->headers->has('Location'))->toBeFalse()
            ->and((string) $response->getContent())->not->toContain('evil');
    });

    it('accepts only https on stripe.com and its subdomains', function (): void {
        foreach (['https://buy.stripe.com/x', 'https://checkout.stripe.com/x', 'https://stripe.com/x', 'https://BUY.Stripe.com/x'] as $ok) {
            expect(StripeUrl::isAllowed($ok))->toBeTrue($ok);
        }

        $refused = [
            null,
            '',
            'http://buy.stripe.com/x',
            'https://stripe.com.example.com/x',
            'https://evil'.'stripe.com/x',
            'https://'.implode('@', ['someone', 'buy.stripe.com/x']),
            'https://'.implode('@', [implode(':', ['someone', 'word']), 'buy.stripe.com/x']),
            'https://buy.stripe.com:8443/x',
            'https://'.implode('@', ['buy.stripe.com', 'example.com/x']),
            'https://example.com/?u=https://buy.stripe.com/x',
            '//buy.stripe.com/x',
            'javascript:alert(1)',
            "https://buy.stripe.com/x\r\nLocation: https://example.com",
            'https://buy.stripe.com\\@example.com/x',
            '/relative',
        ];

        foreach ($refused as $url) {
            expect(StripeUrl::isAllowed($url))->toBeFalse((string) $url);
        }
    });
});

describe('the public routes are limited per visitor and hold no session (R11-08-5, R11-08-6)', function (): void {
    it('answers the 31st request of a minute from one address with 429, still without a cookie and with the no-referrer header', function (): void {
        for ($i = 0; $i < 30; $i++) {
            payPageVisit('/pay/'.Str::random(32))->assertOk();
        }

        $response = payPageVisit('/pay/'.Str::random(32));

        $response->assertStatus(429)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Set-Cookie');
    });

    it('counts the visitors separately by address', function (): void {
        for ($i = 0; $i < 30; $i++) {
            payPageVisit('/pay/'.Str::random(32))->assertOk();
        }

        payPageVisit('/pay/'.Str::random(32))->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/pay/'.Str::random(32))->assertOk();
    });

    it('gathers the limiter on both public routes and no session, cookie or CSRF middleware on either', function (): void {
        $pay = Route::getRoutes()->getByName('pay.show');
        $webhook = Route::getRoutes()->getByName('webhooks.stripe');

        expect($pay)->not->toBeNull()
            ->and($webhook)->not->toBeNull();

        $payMiddleware = $pay->gatherMiddleware();
        $webhookMiddleware = $webhook->gatherMiddleware();

        expect($payMiddleware)->toContain('throttle:pay-page')
            ->and($payMiddleware)->toContain(SetNoReferrerPolicy::class)
            ->and($payMiddleware)->toContain('Filament\\Http\\Middleware\\SetUpPanel:admin')
            ->and($webhookMiddleware)->toContain('throttle:stripe-webhook');

        $forbidden = [
            StartSession::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            'web',
        ];

        foreach ([$payMiddleware, $webhookMiddleware] as $middleware) {
            foreach ($forbidden as $name) {
                expect($middleware)->not->toContain($name);
            }
        }
    });

    it('lists the pay path among the SPA URL exceptions of the admin panel', function (): void {
        expect(Filament::getPanel('admin')->getSpaUrlExceptions())->toContain('*/pay/*')->toContain('*/admin/files/*');
    });
});
