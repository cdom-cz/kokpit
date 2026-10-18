<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Payments\Enums\PayPageOutcome;
use App\Domain\Payments\Queries\PayPageState;
use App\Domain\Payments\Support\StripeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The public stable pay URL printed on the PDF and sent in the e-mail (PA-02; D-01, D-02, D-03). It runs outside the
 * `web` group, so it starts no session and sets no cookie. An active Stripe link is answered with a 302 to it and
 * nothing else; every other outcome is one login-free card that shows the document number and no other document datum.
 * The invalid card is built from constants only, so every invalid cause answers with the same bytes (U-2).
 *
 * Every answer is marked `no-store` and `noindex`; `Referrer-Policy: no-referrer` comes from the route middleware, so
 * a throttled answer carries it too.
 */
final class PayPageController
{
    public function __invoke(PayPageState $state, string $token): RedirectResponse|Response
    {
        $result = $state->resolve($token);
        $outcome = $result['outcome'];

        // The target is checked again here, on top of the reader and the database CHECK (T-11-28).
        if ($outcome === PayPageOutcome::Redirect && StripeUrl::isAllowed($result['url'])) {
            return $this->withHeaders(redirect()->away((string) $result['url'], 302));
        }

        if ($outcome === PayPageOutcome::Redirect) {
            $outcome = PayPageOutcome::Preparing;
        }

        $language = $result['language'] ?? InvoiceLanguage::Czech;

        return $this->withHeaders(response()->view('pay.show', $this->viewData($outcome, $result['number'], $language, $token)));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(PayPageOutcome $outcome, ?string $number, InvoiceLanguage $language, string $token): array
    {
        if ($outcome === PayPageOutcome::Invalid) {
            return [
                'outcome' => $outcome,
                'lang' => 'cs',
                'cards' => [self::card('invalid', 'cs', null), self::card('invalid', 'en', null)],
                'retryHref' => null,
            ];
        }

        $locale = $language->value;

        return [
            'outcome' => $outcome,
            'lang' => $locale,
            'cards' => [self::card($outcome->value, $locale, $number)],
            'retryHref' => $outcome === PayPageOutcome::Preparing ? route('pay.show', ['token' => $token], absolute: false) : null,
        ];
    }

    /**
     * @return array{heading: string, body: string, retry: string|null, lang: string}
     */
    private static function card(string $state, string $locale, ?string $number): array
    {
        return [
            'heading' => self::line('pay_page.'.$state.'.heading', [], $locale),
            'body' => self::line('pay_page.'.$state.'.body', ['number' => (string) $number], $locale),
            'retry' => $state === 'preparing' ? self::line('pay_page.preparing.retry', [], $locale) : null,
            'lang' => $locale,
        ];
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, array $replace, string $locale): string
    {
        $line = __($key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }

    /**
     * @template T of \Symfony\Component\HttpFoundation\Response
     *
     * @param  T  $response
     * @return T
     */
    private function withHeaders($response)
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
