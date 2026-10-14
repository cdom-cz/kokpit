<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Cnb;

use App\Domain\TimeTracking\Support\PragueDay;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * The CNB exchange rate API (EX-01): one GET of the daily list on the public REST endpoint, no key, no account.
 *
 * The CNB answers any date with the newest list not after it, so a Saturday returns Friday's list and a future
 * day returns today's. The client therefore returns the list together with the day the payload names
 * (`validFor`), and every caller stores under that day, never under the requested one.
 *
 * Rates must not pass through a float. Before decoding, every `"rate": <number>` token of the raw body is quoted,
 * so `25.125` arrives as the string `"25.125"`; each row is then validated field by field with the same shape
 * the money conversion accepts, and anything else ends in a typed CnbDownloadFailed. The exception carries the
 * failure value and the HTTP status only, never response text (T-08-11, T-08-13).
 *
 * Only connection errors and 5xx responses are retried; a 4xx is asked exactly once. The base URL comes from
 * config only, so no input chooses the outbound host (T-08-15).
 */
final class CnbClient
{
    /**
     * A rate as the money conversion accepts it (digits, at most ten fraction digits, no sign, no exponent),
     * and at most ten integer digits so it also fits numeric(20,10) without a database error.
     */
    private const RATE_PATTERN = '/^\d{1,10}(\.\d{1,10})?$/D';

    /**
     * The largest unit amount an integer column holds.
     */
    private const MAX_UNIT_AMOUNT = 2147483647;

    /**
     * The list the CNB publishes for `$day`, or the newest one before it.
     *
     * @param  string  $day  `Y-m-d`
     *
     * @throws CnbDownloadFailed
     * @throws InvalidArgumentException when `$day` is not a calendar day
     */
    public function daily(string $day): CnbList
    {
        if (PragueDay::day($day) !== $day) {
            throw new InvalidArgumentException('Not a calendar day: '.$day);
        }

        return $this->parse($this->fetch($day), $day);
    }

    /**
     * @throws CnbDownloadFailed
     */
    private function fetch(string $day): string
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(15)
                // Without `when`, Laravel retries every failed response, a 4xx included.
                ->retry(
                    3,
                    300,
                    when: static fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->get(rtrim((string) config('services.cnb.base_url'), '/').'/exrates/daily', ['date' => $day, 'lang' => 'EN']);
        } catch (ConnectionException) {
            throw new CnbDownloadFailed(CnbFailure::Unavailable);
        }

        return $this->body($response);
    }

    /**
     * @throws CnbDownloadFailed
     */
    private function body(Response $response): string
    {
        if ($response->successful()) {
            return $response->body();
        }

        throw new CnbDownloadFailed(
            $response->serverError() ? CnbFailure::ServerError : CnbFailure::Rejected,
            $response->status(),
        );
    }

    /**
     * Quotes the rates, decodes the body and validates every row.
     *
     * @throws CnbDownloadFailed
     */
    private function parse(string $body, string $requestedDay): CnbList
    {
        // JSON numbers would become floats: quote every "rate" value (an exponent form included, so the row
        // check below refuses it, not the decoder) so the digits reach the validation unchanged.
        $quoted = preg_replace('/"rate"\s*:\s*(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/', '"rate":"$1"', $body);

        if (! is_string($quoted)) {
            throw new CnbDownloadFailed(CnbFailure::Malformed);
        }

        try {
            $data = json_decode($quoted, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CnbDownloadFailed(CnbFailure::Malformed);
        }

        $rows = is_array($data) ? ($data['rates'] ?? null) : null;

        if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            throw new CnbDownloadFailed(CnbFailure::Malformed);
        }

        $validFor = null;
        $seen = [];
        $rates = [];

        foreach ($rows as $row) {
            $rate = $this->row($row);
            $day = PragueDay::day($row['validFor'] ?? null);

            // One list, one day: a mixed payload cannot be stored under a single date.
            if ($day === null || ($validFor !== null && $day !== $validFor) || $day > $requestedDay || isset($seen[$rate['currency']])) {
                throw new CnbDownloadFailed(CnbFailure::Malformed);
            }

            $validFor = $day;
            $seen[$rate['currency']] = true;
            $rates[] = $rate;
        }

        return new CnbList((string) $validFor, $rates);
    }

    /**
     * One validated entry; the rate stays a string.
     *
     * @return array{currency: string, unit_amount: int, rate: string}
     *
     * @throws CnbDownloadFailed
     */
    private function row(mixed $row): array
    {
        if (! is_array($row)) {
            throw new CnbDownloadFailed(CnbFailure::Malformed);
        }

        $currency = $row['currencyCode'] ?? null;
        $amount = $row['amount'] ?? null;
        $rate = $row['rate'] ?? null;

        if (
            ! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || ! is_int($amount) || $amount < 1 || $amount > self::MAX_UNIT_AMOUNT
            || ! is_string($rate) || preg_match(self::RATE_PATTERN, $rate) !== 1 || preg_match('/^0+(\.0+)?$/D', $rate) === 1
        ) {
            throw new CnbDownloadFailed(CnbFailure::Malformed);
        }

        return ['currency' => $currency, 'unit_amount' => $amount, 'rate' => $rate];
    }
}
