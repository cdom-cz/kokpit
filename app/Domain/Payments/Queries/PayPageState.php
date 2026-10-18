<?php

declare(strict_types=1);

namespace App\Domain\Payments\Queries;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Enums\PayPageOutcome;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Payments\Support\StripeUrl;
use App\Domain\Shared\Auth\PartnerContext;

/**
 * Turns the token of the public pay page into what the page does (D-02, R11-08-2). The visitor is a guest, so every
 * read runs as system, and only the outcome, the document number, the language and the redirect target leave this
 * class; no amount, client, supplier or Stripe reference ever does.
 *
 * The order is fixed: a token of the wrong shape is invalid before any query; an unknown token, a document that is not
 * an issued invoice or proforma and a row without a document are the same invalid outcome; then a cancelled document,
 * a paid document, an active link whose URL passes {@see StripeUrl::isAllowed()} and offers exactly what is still
 * owed, and otherwise the preparing state (pending, failed or deactivated link, partial payment, no link).
 */
final class PayPageState
{
    public function __construct(private readonly PartnerContext $context, private readonly InvoiceBalances $balances) {}

    /**
     * @return array{outcome: PayPageOutcome, number: string|null, language: InvoiceLanguage|null, url: string|null}
     */
    public function resolve(string $token): array
    {
        if (! PayToken::isWellFormed($token)) {
            return self::invalid();
        }

        return $this->context->runAsSystem(fn (): array => $this->lookup($token));
    }

    /**
     * @return array{outcome: PayPageOutcome, number: string|null, language: InvoiceLanguage|null, url: string|null}
     */
    private function lookup(string $token): array
    {
        $invoiceId = InvoiceAdmin::query()->where('pay_token', $token)->value('invoice_id');

        if (! is_string($invoiceId)) {
            return self::invalid();
        }

        $invoice = Invoice::query()->whereKey($invoiceId)->first();

        if ($invoice === null || ! $invoice->isIssued() || ! $invoice->kind->carriesPayment() || $invoice->number === null) {
            return self::invalid();
        }

        $number = $invoice->number;
        $language = $invoice->language;

        if ($invoice->isCancelled()) {
            return self::answer(PayPageOutcome::Cancelled, $number, $language);
        }

        $remaining = $this->balances->for([$invoice->getKey()])[$invoice->getKey()]['remaining'] ?? null;

        if ($remaining === null || $remaining <= 0) {
            return self::answer(PayPageOutcome::Paid, $number, $language);
        }

        $link = PaymentLink::latestFor($invoice->getKey());

        // A link that offers anything but what is still owed is never followed (D-08); the Admin regenerates it.
        if ($link !== null
            && $link->state === PaymentLinkState::Active
            && $link->amount_minor === $remaining
            && StripeUrl::isAllowed($link->stripe_url)) {
            return self::answer(PayPageOutcome::Redirect, $number, $language, $link->stripe_url);
        }

        return self::answer(PayPageOutcome::Preparing, $number, $language);
    }

    /**
     * @return array{outcome: PayPageOutcome, number: string|null, language: InvoiceLanguage|null, url: string|null}
     */
    private static function answer(PayPageOutcome $outcome, string $number, InvoiceLanguage $language, ?string $url = null): array
    {
        return ['outcome' => $outcome, 'number' => $number, 'language' => $language, 'url' => $url];
    }

    /**
     * @return array{outcome: PayPageOutcome, number: string|null, language: InvoiceLanguage|null, url: string|null}
     */
    private static function invalid(): array
    {
        return ['outcome' => PayPageOutcome::Invalid, 'number' => null, 'language' => null, 'url' => null];
    }
}
