<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Actions\DownloadCnbRates;
use App\Domain\ExchangeRates\Cnb\CnbClient;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\Cnb\CnbFailure;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\CnbFixtures;

/*
 * The CNB daily download (EX-01, D-02): the whole list stored once, under the day the payload names, with the
 * unit amount as published and the rate as an exact decimal string. Every payload is invented and assembled at
 * runtime; Http::preventStrayRequests() (TestCase) blocks the live service.
 */

/**
 * Freezes the clock at 15:30 Prague time on the given day.
 */
function cnbAt(string $day): void
{
    test()->travelTo(CarbonImmutable::parse($day.' 15:30:00', 'Europe/Prague'));
}

/**
 * Answers every request with an invented daily list valid for `$validFor`.
 *
 * @param  list<array<string, mixed>>|null  $rates
 */
function cnbServes(string $validFor, ?array $rates = null): void
{
    Http::fake(['*' => Http::response(CnbFixtures::dailyJson($validFor, $rates), 200, ['Content-Type' => 'application/json'])]);
}

/**
 * The stored rows, read in the system context like the command writes them.
 *
 * @return list<array{date: string, currency: string, unit: int, rate: string}>
 */
function cnbStored(): array
{
    /** @var list<array{date: string, currency: string, unit: int, rate: string}> $rows */
    $rows = app(PartnerContext::class)->runAsSystem(
        static fn (): array => ExchangeRate::query()
            ->orderBy('rate_date')
            ->orderBy('currency')
            ->get()
            ->map(static fn (ExchangeRate $rate): array => [
                'date' => $rate->rate_date->toDateString(),
                'currency' => $rate->currency,
                'unit' => $rate->unit_amount,
                'rate' => $rate->rate,
            ])
            ->all(),
    );

    return $rows;
}

/**
 * The failure a download of `$day` ends in.
 */
function cnbFailureOf(string $day): CnbDownloadFailed
{
    try {
        app(CnbClient::class)->daily($day);
    } catch (CnbDownloadFailed $failure) {
        return $failure;
    }

    throw new RuntimeException('The download did not fail.');
}

beforeEach(function (): void {
    Sleep::fake();
});

describe('storing the whole list', function (): void {
    it('stores every currency of the payload once with its unit amount', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');

        expect(Artisan::call('kokpit:rates:download'))->toBe(0);

        $stored = cnbStored();
        $byCurrency = array_column($stored, null, 'currency');

        expect($stored)->toHaveCount(6)
            ->and(array_unique(array_column($stored, 'date')))->toBe(['2026-10-14'])
            ->and($byCurrency['HUF']['unit'])->toBe(100)
            ->and($byCurrency['IDR']['unit'])->toBe(1000)
            ->and($byCurrency['EUR']['unit'])->toBe(1)
            ->and(Artisan::output())->toContain(__('kokpit.rates.console.stored', ['date' => '14. 10. 2026', 'count' => 6]));
    });

    it('asks the daily endpoint for the day in English', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');

        Artisan::call('kokpit:rates:download');

        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://api.cnb.cz/cnbapi/exrates/daily?')
            && $request->data()['date'] === '2026-10-14'
            && $request->data()['lang'] === 'EN');
    });

    it('reads a stored rate back as the exact decimal string of the JSON number', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');

        Artisan::call('kokpit:rates:download');

        $eur = array_column(cnbStored(), null, 'currency')['EUR'];

        expect($eur['rate'])->toBe('25.1250000000');
    });

    it('keeps a long fraction exact where a float would not', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14', [['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '25.1234567891']]);

        Artisan::call('kokpit:rates:download');

        expect(array_column(cnbStored(), null, 'currency')['EUR']['rate'])->toBe('25.1234567891');
    });
});

describe('idempotence', function (): void {
    it('makes no request and stores nothing on a second run the same day', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');

        Artisan::call('kokpit:rates:download');
        $before = cnbStored();

        expect(Artisan::call('kokpit:rates:download'))->toBe(0)
            ->and(Artisan::output())->toContain(__('kokpit.rates.console.already_stored', ['date' => '14. 10. 2026']))
            ->and(cnbStored())->toBe($before);

        Http::assertSentCount(1);
    });

    it('inserts zero rows when the answer is a list that is already stored', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');
        Artisan::call('kokpit:rates:download');

        // A different run day whose answer is the same, already stored list.
        cnbAt('2026-10-15');
        cnbServes('2026-10-14');

        $result = app(PartnerContext::class)->runAsSystem(static fn (): array => app(DownloadCnbRates::class)->handle('2026-10-15'));

        expect($result)->toBe(['valid_for' => '2026-10-14', 'inserted' => 0, 'skipped' => false])
            ->and(cnbStored())->toHaveCount(6);
    });

    it('does not rewrite a stored rate when the answer carries a different value', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');
        Artisan::call('kokpit:rates:download');

        cnbAt('2026-10-15');
        cnbServes('2026-10-14', [['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '99.5']]);

        app(PartnerContext::class)->runAsSystem(static fn (): array => app(DownloadCnbRates::class)->handle('2026-10-15'));

        expect(array_column(cnbStored(), null, 'currency')['EUR']['rate'])->toBe('25.1250000000');
    });
});

describe('the validity date', function (): void {
    it('stores the Friday list under Friday only when run on a Saturday', function (): void {
        cnbAt('2026-10-10');
        cnbServes('2026-10-09');

        expect(Artisan::call('kokpit:rates:download'))->toBe(0)
            ->and(Artisan::output())->toContain(__('kokpit.rates.console.not_published', ['date' => '9. 10. 2026']));

        $dates = array_unique(array_column(cnbStored(), 'date'));

        expect($dates)->toBe(['2026-10-09'])
            ->and(cnbStored())->toHaveCount(6);
    });

    it('stores no row under the requested day when the payload names another one', function (): void {
        cnbAt('2026-10-10');
        cnbServes('2026-10-09');

        Artisan::call('kokpit:rates:download');

        expect(array_column(cnbStored(), 'date'))->not->toContain('2026-10-10');
    });

    it('asks again on a day after a skipped weekend list because no row carries the requested day', function (): void {
        cnbAt('2026-10-10');
        cnbServes('2026-10-09');

        Artisan::call('kokpit:rates:download');
        Artisan::call('kokpit:rates:download');

        Http::assertSentCount(2);
        expect(cnbStored())->toHaveCount(6);
    });
});

describe('the Action', function (): void {
    it('refuses a day that is not a calendar day without any request', function (string $day): void {
        Http::fake();

        expect(static fn () => app(DownloadCnbRates::class)->handle($day))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    })->with(['text' => 'tomorrow', 'impossible' => '2026-02-30', 'slashes' => '2026/10/14', 'empty' => '']);

    it('reports the stored list and the inserted count', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');

        $result = app(PartnerContext::class)->runAsSystem(static fn (): array => app(DownloadCnbRates::class)->handle('2026-10-14'));

        expect($result)->toBe(['valid_for' => '2026-10-14', 'inserted' => 6, 'skipped' => false]);
    });

    it('runs the command in the system context so the stored-day check sees the rows without a signed-in user', function (): void {
        cnbAt('2026-10-14');
        cnbServes('2026-10-14');

        expect(app(PartnerContext::class)->isSystem())->toBeFalse();

        Artisan::call('kokpit:rates:download');
        Artisan::call('kokpit:rates:download');

        // A second run that did not see the stored rows (fail-closed scope) would have asked CNB again.
        Http::assertSentCount(1);
    });
});

describe('the client', function (): void {
    it('returns the list with the day the payload names and per-currency units', function (): void {
        cnbServes('2026-10-09');

        $list = app(CnbClient::class)->daily('2026-10-10');

        expect($list->validFor)->toBe('2026-10-09')
            ->and($list->rates)->toHaveCount(6)
            ->and($list->rates[0])->toBe(['currency' => 'EUR', 'unit_amount' => 1, 'rate' => '25.125'])
            ->and($list->rates[3])->toBe(['currency' => 'HUF', 'unit_amount' => 100, 'rate' => '6.31']);
    });

    it('builds insert rows with explicit timestamps and no id', function (): void {
        cnbServes('2026-10-14');

        $rows = app(CnbClient::class)->daily('2026-10-14')->rows(CarbonImmutable::parse('2026-10-14 13:45:12', 'UTC'));

        expect($rows)->toHaveCount(6)
            ->and($rows[0])->toBe([
                'rate_date' => '2026-10-14',
                'currency' => 'EUR',
                'unit_amount' => 1,
                'rate' => '25.125',
                'fetched_at' => '2026-10-14 13:45:12+00:00',
                'created_at' => '2026-10-14 13:45:12+00:00',
                'updated_at' => '2026-10-14 13:45:12+00:00',
            ]);
    });

    it('is a failure value, never an exception text', function (): void {
        Http::fake(['*' => Http::response('', 503)]);

        $failure = cnbFailureOf('2026-10-14');

        expect($failure->failure)->toBe(CnbFailure::ServerError)
            ->and($failure->getMessage())->toBe('server_error');
    });
});
