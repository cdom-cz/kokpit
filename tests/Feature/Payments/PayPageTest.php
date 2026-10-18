<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
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
