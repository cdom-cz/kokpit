<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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
 * Gives the overview route a name the alert can resolve, as plan 08-13 will.
 */
function rateAlertRoute(): string
{
    Route::get('/fictional/exchange-rates', static fn (): string => '')->name(RATE_ALERT_ROUTE);
    Route::getRoutes()->refreshNameLookups();

    return route(RATE_ALERT_ROUTE);
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

    it('keeps the action out of the alert while the overview route does not exist', function (): void {
        $admin = Canary::admin();
        rateAlertAt('2026-10-14 18:00:00');
        rateAlertDown(503);

        Artisan::call('kokpit:rates:download', ['--final' => true]);

        $data = rateAlertBellData(rateAlertBell($admin)->first());

        expect($data['actions'] ?? [])->toBe([]);
    });
});
