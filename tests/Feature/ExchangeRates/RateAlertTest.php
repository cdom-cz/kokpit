<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Canary;
use Tests\Support\CnbFixtures;

/*
 * The rhythm of the CNB download (EX-01, EX-02; D-03, D-04): scheduled attempts only on a Czech working day, hourly
 * failures that stay quiet, and one Admin alert by e-mail and bell from the final run of the day, sent without the
 * queue. Every rate, date and e-mail address is invented.
 */

const RATE_ALERT_ROUTE = 'filament.admin.resources.exchange-rates.index';

beforeEach(function (): void {
    Sleep::fake();
    Cache::flush();
});

/**
 * Freezes the clock at the given Prague local time.
 */
function rateAlertAt(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'Europe/Prague'));
}

/**
 * Stores one invented rate row dated `$day`, in the system context like the download writes it.
 */
function rateAlertStored(string $day, string $currency = 'EUR', string $rate = '25.125'): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($day, $currency, $rate): void {
        ExchangeRate::query()->insert([
            'rate_date' => $day,
            'currency' => $currency,
            'unit_amount' => 1,
            'rate' => $rate,
            'fetched_at' => $day.' 14:45:00+00',
            'created_at' => $day.' 14:45:00+00',
            'updated_at' => $day.' 14:45:00+00',
        ]);
    });
}

/**
 * The CNB host answers every request with the given status and a canary body.
 */
function rateAlertDown(int $status, string $body = 'CANARY_BODY_UNSET'): void
{
    Http::fake(['*' => Http::response($body, $status)]);
}

/**
 * The CNB host answers with an invented list valid for `$validFor`.
 */
function rateAlertServes(string $validFor): void
{
    Http::fake(['*' => Http::response(CnbFixtures::dailyJson($validFor), 200, ['Content-Type' => 'application/json'])]);
}

/**
 * The URL of the rates overview (plan 08-13), which the alert's action opens.
 */
function rateAlertRoute(): string
{
    return route(RATE_ALERT_ROUTE);
}

/**
 * Makes the insert into the rates table fail with a database error whose text holds a canary, and returns the canary.
 */
function rateAlertBreakInsert(): string
{
    $canary = Canary::canary('database');

    DB::beforeExecuting(static function (string $query) use ($canary): void {
        if (str_contains($query, 'insert into "exchange_rates"')) {
            throw new QueryException('pgsql', $query, [], new Exception('SQLSTATE[08006] host=db.internal '.$canary));
        }
    });

    return $canary;
}

/**
 * @return Collection<int, object>
 */
function rateAlertBell(object $admin): Collection
{
    return DB::table('notifications')->where('notifiable_id', $admin->getKey())->orderBy('created_at')->get();
}

/**
 * @return array<string, mixed>
 */
function rateAlertBellData(object $row): array
{
    return json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return Collection<int, mixed>
 */
function rateAlertMails(): Collection
{
    return Mail::getSymfonyTransport()->messages();
}

function rateAlertMailText(int $position = 0): string
{
    $mail = rateAlertMails()->get($position)->getOriginalMessage();

    return (string) $mail->getTextBody().(string) $mail->getHtmlBody();
}

describe('the final run that could not download today\'s list', function (): void {
    it('alerts the Admin by bell and e-mail without touching the queue', function (): void {
        $admin = Canary::admin();
        // 2026-10-14 is a Wednesday; the list of Tuesday is the last one stored.
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertStored('2026-10-13');
        rateAlertDown(503);
        Queue::fake();
        $url = rateAlertRoute();

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        Queue::assertNothingPushed();
        $rows = rateAlertBell($admin);
        $data = rateAlertBellData($rows->first());

        expect($exit)->toBe(1)
            ->and($rows)->toHaveCount(1)
            ->and(rateAlertMails())->toHaveCount(1)
            ->and($data['title'])->toBe('Kurzy ČNB se nepodařilo stáhnout')
            ->and($data['body'])->toContain('14. 10. 2026')->toContain('13. 10. 2026')
            ->and($data['actions'][0]['label'])->toBe('Otevřít kurzy ČNB')
            ->and($data['actions'][0]['url'])->toBe($url)
            ->and(rateAlertMailText())->toContain('Otevřít kurzy ČNB')->toContain($url)->toContain('13. 10. 2026');
    });

    it('sends no second alert from a second final run inside the throttle window', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertStored('2026-10-13');
        rateAlertDown(503);

        Artisan::call('kokpit:rates:download', ['--final' => true]);
        Artisan::call('kokpit:rates:download', ['--final' => true]);

        expect(rateAlertBell($admin))->toHaveCount(1)
            ->and(rateAlertMails())->toHaveCount(1);
    });
});

describe('a day without a list to expect', function (): void {
    it('makes no request and sends no alert on a non-working day', function (string $local, bool $final): void {
        $admin = Canary::admin();
        rateAlertAt($local);
        Http::fake();

        $exit = Artisan::call('kokpit:rates:download', $final ? ['--final' => true] : []);

        Http::assertNothingSent();
        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('Dnes není pracovní den, kurzy se nestahují.')
            ->and(rateAlertBell($admin))->toHaveCount(0)
            ->and(rateAlertMails())->toHaveCount(0);
    })->with([
        'a Saturday afternoon attempt' => ['2026-10-10 15:00:00', false],
        'a Sunday final run' => ['2026-10-11 18:00:00', true],
        'an Easter Monday final run' => ['2026-04-06 18:00:00', true],
        'a Good Friday final run' => ['2026-04-03 18:00:00', true],
        'a Christmas Eve final run' => ['2026-12-24 18:00:00', true],
    ]);
});

describe('an hourly attempt that failed', function (): void {
    it('only warns, exits 1 and sends no alert', function (): void {
        $admin = Canary::admin();
        // 2026-10-14 is a Wednesday.
        rateAlertAt('2026-10-14 15:00:00');
        rateAlertStored('2026-10-13');
        rateAlertDown(503);
        Queue::fake();

        $exit = Artisan::call('kokpit:rates:download');
        $output = Artisan::output();

        Queue::assertNothingPushed();
        expect($exit)->toBe(1)
            ->and($output)->toContain('Server ČNB hlásí chybu.')->toContain('Stavový kód odpovědi: 503.')
            ->and(rateAlertBell($admin))->toHaveCount(0)
            ->and(rateAlertMails())->toHaveCount(0);
    });

    it('stays quiet on a database error too and lets the exception reach the log', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 16:00:00');
        rateAlertServes('2026-10-14');
        rateAlertBreakInsert();

        expect(fn () => Artisan::call('kokpit:rates:download'))->toThrow(QueryException::class);

        expect(rateAlertBell($admin))->toHaveCount(0)
            ->and(rateAlertMails())->toHaveCount(0);
    });
});

describe('the final run of a working day', function (): void {
    it('alerts when the CNB still answers the list of the previous working day', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertStored('2026-10-13');
        rateAlertServes('2026-10-13');

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        $rows = rateAlertBell($admin);
        $data = rateAlertBellData($rows->first());

        expect($exit)->toBe(1)
            ->and($rows)->toHaveCount(1)
            ->and(rateAlertMails())->toHaveCount(1)
            ->and($data['title'])->toBe('Kurzy ČNB se nepodařilo stáhnout')
            ->and($data['body'])->toContain('14. 10. 2026')->toContain('13. 10. 2026');
    });

    it('names the previous working day as the last known rate after a long weekend', function (): void {
        $admin = Canary::admin();
        // Easter Monday 2026-04-06 had no list; Tuesday 2026-04-07 is the working day that failed.
        rateAlertAt('2026-04-07 18:00:00');
        rateAlertStored('2026-04-02');
        rateAlertServes('2026-04-02');

        Artisan::call('kokpit:rates:download', ['--final' => true]);

        expect(rateAlertBellData(rateAlertBell($admin)->first())['body'])->toContain('7. 4. 2026')->toContain('2. 4. 2026');
    });

    it('makes no request and no alert when today\'s list is already stored', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertStored('2026-10-14');
        Http::fake();

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        Http::assertNothingSent();
        expect($exit)->toBe(0)
            ->and(rateAlertBell($admin))->toHaveCount(0)
            ->and(rateAlertMails())->toHaveCount(0);
    });

    it('stores today\'s list and sends no alert when the CNB answers it', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertStored('2026-10-13');
        rateAlertServes('2026-10-14');

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        $stored = app(PartnerContext::class)->runAsSystem(
            static fn (): int => ExchangeRate::query()->where('rate_date', '2026-10-14')->count(),
        );

        expect($exit)->toBe(0)
            ->and($stored)->toBe(6)
            ->and(rateAlertBell($admin))->toHaveCount(0)
            ->and(rateAlertMails())->toHaveCount(0);
    });

    it('uses the text without a last known date when no list was ever stored', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertDown(503);

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        $body = rateAlertBellData(rateAlertBell($admin)->first())['body'];

        expect($exit)->toBe(1)
            ->and($body)->toContain('14. 10. 2026')->toContain('Zatím není uložen žádný seznam kurzů.')
            ->not->toContain('ze dne');
    });

    it('alerts and then rethrows a database error, without its text in the alert', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertStored('2026-10-13');
        rateAlertServes('2026-10-14');
        $canary = rateAlertBreakInsert();

        expect(fn () => Artisan::call('kokpit:rates:download', ['--final' => true]))->toThrow(QueryException::class);

        $data = rateAlertBell($admin)->first();

        expect(rateAlertBell($admin))->toHaveCount(1)
            ->and(rateAlertMails())->toHaveCount(1)
            ->and((string) $data->data)->not->toContain($canary)
            ->and(rateAlertMailText())->not->toContain($canary);
    });
});

describe('what the alert never carries', function (): void {
    it('keeps the response body, the host and the exception text out of the mail and the bell', function (string $mode): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertStored('2026-10-13');
        rateAlertRoute();
        $body = Canary::canary('body');
        $host = (string) parse_url((string) config('services.cnb.base_url'), PHP_URL_HOST);

        match ($mode) {
            'server error' => rateAlertDown(503, $body),
            'client error' => rateAlertDown(404, $body),
            'malformed list' => Http::fake(['*' => Http::response($body, 200)]),
            'connection error' => Http::fake(static function () use ($body): never {
                throw new ConnectionException('cURL error 6: could not resolve host '.$body);
            }),
        };

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        $texts = [(string) rateAlertBell($admin)->first()->data, rateAlertMailText()];

        expect($exit)->toBe(1)
            ->and($host)->not->toBe('');

        foreach ($texts as $text) {
            expect($text)->toContain('14. 10. 2026')
                ->not->toContain($body)
                ->not->toContain($host)
                ->not->toContain('cURL')
                ->not->toContain('https://api');
        }
    })->with(['server error', 'client error', 'malformed list', 'connection error']);
});
