<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ExchangeRateResource;
use App\Filament\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use App\Filament\Resources\ExchangeRateResource\Widgets\ExchangeRateStats;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;
use Tests\Support\CnbFixtures;

/*
 * The Admin-only "Kurzy ČNB" overview (EX-01, EX-02; D-02, D-05; UI-SPEC Surface A): the stored lists as one row per
 * currency and day, and the synchronous "Stáhnout dnešní kurzy" action. Every rate, date and name is invented; the
 * CNB answers come from CnbFixtures through Http::fake (stray requests are blocked by the TestCase).
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Stores one invented rate row dated `$day`, in the system context like the download writes it.
 */
function rateResourceStore(string $day, string $currency = 'EUR', string $rate = '25.1250000000', int $unit = 1, string $fetchedAt = '14:45:00'): ExchangeRate
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($day, $currency, $rate, $unit, $fetchedAt): ExchangeRate {
        ExchangeRate::query()->insert([
            'rate_date' => $day,
            'currency' => $currency,
            'unit_amount' => $unit,
            'rate' => $rate,
            'fetched_at' => $day.' '.$fetchedAt.'+00',
            'created_at' => $day.' '.$fetchedAt.'+00',
            'updated_at' => $day.' '.$fetchedAt.'+00',
        ]);

        return ExchangeRate::query()->where('rate_date', $day)->where('currency', $currency)->firstOrFail();
    });
}

/**
 * The CNB host answers every request with an invented list valid for `$validFor`.
 *
 * @param  list<array<string, mixed>>|null  $rates
 */
function rateResourceServes(string $validFor, ?array $rates = null): void
{
    Http::fake(['*' => Http::response(CnbFixtures::dailyJson($validFor, $rates), 200, ['Content-Type' => 'application/json'])]);
}

describe('the tracer: open the page, fetch today\'s list, read it', function (): void {
    it('opens for the Admin with the heading', function (): void {
        $this->get(ExchangeRateResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Kurzy ČNB')
            ->assertSee('Stáhnout dnešní kurzy');
    });

    it('stores today\'s list with the download action and lists the rate trimmed to three decimals', function (): void {
        rateResourceServes('2026-10-14', [
            ['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '25.125'],
            ['currencyCode' => 'USD', 'amount' => 1, 'rate' => '22.5'],
        ]);

        Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()->success()->title('Kurzy byly staženy')->body('Platnost ke dni 14. 10. 2026, 2 měn.'),
            );

        $rows = app(PartnerContext::class)->runAsSystem(
            static fn (): array => ExchangeRate::query()->where('rate_date', '2026-10-14')->orderBy('currency')->pluck('rate', 'currency')->all(),
        );

        expect($rows)->toBe(['EUR' => '25.1250000000', 'USD' => '22.5000000000']);

        $page = Livewire::test(ListExchangeRates::class);

        $page->assertSee('25,125')
            ->assertSee('22,500')
            ->assertSee('14. 10. 2026');
    });
});

/**
 * The stats a widget would show, read through its protected getStats().
 *
 * @return list<Filament\Widgets\StatsOverviewWidget\Stat>
 */
function rateResourceStats(object $component): array
{
    $widget = $component->instance();
    $method = new ReflectionMethod($widget, 'getStats');

    /** @var list<Filament\Widgets\StatsOverviewWidget\Stat> $stats */
    $stats = array_values($method->invoke($widget));

    return $stats;
}

/**
 * Two stored lists: Thursday 2026-10-08 (EUR, USD) and Friday 2026-10-09 (EUR, USD, HUF).
 *
 * @return array{eur8: ExchangeRate, usd8: ExchangeRate, eur9: ExchangeRate, usd9: ExchangeRate, huf9: ExchangeRate}
 */
function rateResourceTwoLists(): array
{
    return [
        'eur8' => rateResourceStore('2026-10-08', 'EUR', '25.2000000000'),
        'usd8' => rateResourceStore('2026-10-08', 'USD', '22.4000000000'),
        'eur9' => rateResourceStore('2026-10-09', 'EUR', '25.1250000000'),
        'usd9' => rateResourceStore('2026-10-09', 'USD', '22.5000000000'),
        'huf9' => rateResourceStore('2026-10-09', 'HUF', '6.4810000000', 100),
    ];
}

describe('the currency cell', function (): void {
    it('shows the ISO code with the Czech currency name', function (): void {
        rateResourceTwoLists();

        Livewire::test(ListExchangeRates::class)
            ->assertSee('EUR')
            ->assertSee('euro')
            ->assertSee('HUF')
            ->assertSee('maďarský forint');
    });

    it('shows the code alone for a code without a name', function (): void {
        rateResourceStore('2026-10-09', 'ZZZ', '1.5000000000');

        Livewire::test(ListExchangeRates::class)->assertSee('ZZZ')->assertSee('1,500');
    });
});

describe('the header stats', function (): void {
    it('answers "are the rates current": newest list, health and currency count', function (): void {
        // Friday 2026-10-09, 20:00 in Prague: today's list is the expected one, so the slot is in order.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00:00', 'UTC'));
        rateResourceTwoLists();

        $stats = rateResourceStats(Livewire::test(ExchangeRateStats::class));

        expect($stats)->toHaveCount(3)
            ->and((string) $stats[0]->getLabel())->toBe('Poslední kurzy')
            ->and((string) $stats[0]->getValue())->toBe('9. 10. 2026')
            ->and((string) $stats[0]->getDescription())->toBe('Staženo 9. 10. 2026 16:45')
            ->and((string) $stats[1]->getLabel())->toBe('Stav')
            ->and((string) $stats[1]->getValue())->toBe('V pořádku')
            ->and((string) $stats[1]->getDescription())->toBe('Kurzy jsou aktuální.')
            ->and($stats[1]->getDescriptionColor())->toBe('success')
            ->and($stats[1]->getDescriptionIcon())->toBeNull()
            ->and((string) $stats[2]->getLabel())->toBe('Měn v seznamu')
            ->and((string) $stats[2]->getValue())->toBe('3')
            ->and((string) $stats[2]->getDescription())->toBe('Jednotka a kurz podle ČNB');
    });

    it('shows a warning with the warning triangle when one working day is missing', function (): void {
        // Monday 2026-10-12 at 20:00: the list of Monday is expected, the newest is Friday's.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'));
        rateResourceTwoLists();

        $stat = rateResourceStats(Livewire::test(ExchangeRateStats::class))[1];

        expect((string) $stat->getValue())->toBe('Varování')
            ->and((string) $stat->getDescription())->toBe('Chybí kurzy za 1 pracovní den.')
            ->and($stat->getDescriptionColor())->toBe('warning')
            ->and($stat->getDescriptionIcon())->toBe(Heroicon::OutlinedExclamationTriangle);
    });

    it('shows an error with the warning triangle when two working days are missing', function (): void {
        // Wednesday 2026-10-14 at 12:00: Monday and Tuesday are behind the newest list.
        rateResourceTwoLists();

        $stat = rateResourceStats(Livewire::test(ExchangeRateStats::class))[1];

        expect((string) $stat->getValue())->toBe('Chyba')
            ->and((string) $stat->getDescription())->toBe('Kurzy chybí už 2 pracovní dny.')
            ->and($stat->getDescriptionColor())->toBe('danger')
            ->and($stat->getDescriptionIcon())->toBe(Heroicon::OutlinedExclamationTriangle);
    });

    it('polls every 60 seconds', function (): void {
        $widget = Livewire::test(ExchangeRateStats::class)->instance();

        expect((new ReflectionMethod($widget, 'getPollingInterval'))->invoke($widget))->toBe('60s');
    });

    it('is registered as the header widget of the list page', function (): void {
        $this->get(ExchangeRateResource::getUrl('index'))->assertOk()->assertSeeLivewire(ExchangeRateStats::class);
    });

    it('shows a dash and the not-yet-downloaded text when nothing is stored', function (): void {
        $stats = rateResourceStats(Livewire::test(ExchangeRateStats::class));

        expect((string) $stats[0]->getValue())->toBe('—')
            ->and((string) $stats[0]->getDescription())->toBe('Kurzy ještě nebyly staženy')
            ->and((string) $stats[1]->getValue())->toBe('Chyba')
            ->and((string) $stats[2]->getValue())->toBe('0');
    });
});

describe('the filters', function (): void {
    it('opens on the newest stored date', function (): void {
        $rows = rateResourceTwoLists();

        Livewire::test(ListExchangeRates::class)
            ->assertCanSeeTableRecords([$rows['eur9'], $rows['usd9'], $rows['huf9']])
            ->assertCanNotSeeTableRecords([$rows['eur8'], $rows['usd8']]);
    });

    it('shows every day once the Datum is cleared, newest first and currencies in order', function (): void {
        $rows = rateResourceTwoLists();

        Livewire::test(ListExchangeRates::class)
            ->filterTable('date', ['date' => null])
            ->assertCanSeeTableRecords([$rows['eur9'], $rows['huf9'], $rows['usd9'], $rows['eur8'], $rows['usd8']], inOrder: true);
    });

    it('narrows to one stored currency with the Měna filter', function (): void {
        $rows = rateResourceTwoLists();

        Livewire::test(ListExchangeRates::class)
            ->filterTable('date', ['date' => null])
            ->filterTable('currency', 'HUF')
            ->assertCanSeeTableRecords([$rows['huf9']])
            ->assertCanNotSeeTableRecords([$rows['eur9'], $rows['usd9'], $rows['eur8'], $rows['usd8']])
            ->assertTableColumnStateSet('unit_amount', 100, $rows['huf9']);
    });

    it('offers only the stored currency codes', function (): void {
        rateResourceTwoLists();

        $options = Livewire::test(ListExchangeRates::class)->instance()->getTable()->getFilter('currency')->getOptions();

        expect($options)->toBe(['EUR' => 'EUR', 'HUF' => 'HUF', 'USD' => 'USD']);
    });

    it('shows the active Datum as an indicator', function (): void {
        rateResourceTwoLists();

        Livewire::test(ListExchangeRates::class)->assertSee('Datum: 9. 10. 2026');
    });

    it('ignores a Datum that is not a real day', function (): void {
        $rows = rateResourceTwoLists();

        Livewire::test(ListExchangeRates::class)
            ->filterTable('date', ['date' => '2026-02-31'])
            ->assertCanSeeTableRecords([$rows['eur9'], $rows['eur8']]);
    });
});

describe('the empty states', function (): void {
    it('says the CNB declared no list for a day without one and names the list that applies', function (): void {
        rateResourceTwoLists();

        // Saturday 2026-10-10.
        Livewire::test(ListExchangeRates::class)
            ->filterTable('date', ['date' => '2026-10-10'])
            ->assertSee('Pro tento den ČNB kurzy nevyhlásila')
            ->assertSee('Platí poslední vyhlášené kurzy ze dne 9. 10. 2026.')
            ->assertSee('Datum: 10. 10. 2026')
            ->assertDontSee('Zatím tu nejsou žádné kurzy');
    });

    it('shows no earlier day in the body when the date is before every stored list', function (): void {
        rateResourceTwoLists();

        Livewire::test(ListExchangeRates::class)
            ->filterTable('date', ['date' => '2026-10-01'])
            ->assertSee('Pro tento den ČNB kurzy nevyhlásila')
            ->assertDontSee('Platí poslední vyhlášené kurzy');
    });

    it('says nothing is stored yet and keeps the download reachable', function (): void {
        Livewire::test(ListExchangeRates::class)
            ->assertSee('Zatím tu nejsou žádné kurzy')
            ->assertSee('Stáhněte první seznam tlačítkem Stáhnout dnešní kurzy. Kurzy se pak stahují samy každý pracovní den odpoledne.')
            ->assertActionVisible('downloadToday');
    });
});

/**
 * The CNB host answers every request with the given status and a canary body.
 */
function rateResourceDown(int $status, string $body): void
{
    Http::fake(['*' => Http::response($body, $status)]);
}

/**
 * How many rows the stored lists hold, read in the system context.
 */
function rateResourceCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => ExchangeRate::query()->count());
}

/**
 * The navigation as `group/label` strings for the signed-in user.
 *
 * @return list<string>
 */
function rateResourceNavigation(): array
{
    return collect(Filament::getNavigation())
        ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => $group->getLabel().'/'.$item->getLabel())->all())
        ->values()
        ->all();
}

describe('every outcome of the download', function (): void {
    it('says the list is already stored and makes no request', function (): void {
        rateResourceStore('2026-10-14');
        Http::fake();

        Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()->info()->title('Dnešní kurzy už jsou stažené.')->body('Platnost ke dni 14. 10. 2026.'),
            );

        Http::assertNothingSent();
        expect(rateResourceCount())->toBe(1);
    });

    it('says the list is already stored when a concurrent run stored it during the request', function (): void {
        // The rows appear between the stored-day check and the insert, so this run inserts nothing.
        Http::fake(static function (): mixed {
            rateResourceStore('2026-10-14');

            return Http::response(CnbFixtures::dailyJson('2026-10-14', [['currencyCode' => 'EUR', 'amount' => 1, 'rate' => '25.125']]), 200, ['Content-Type' => 'application/json']);
        });

        Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()->info()->title('Dnešní kurzy už jsou stažené.')->body('Platnost ke dni 14. 10. 2026.'),
            );
    });

    it('says the CNB has no newer list yet and names the list that applies', function (): void {
        // The CNB still answers Tuesday's list at noon on Wednesday.
        rateResourceServes('2026-10-13');

        Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()
                    ->info()
                    ->title('Nové kurzy zatím nejsou k dispozici.')
                    ->body('Poslední platné kurzy jsou ze dne 13. 10. 2026. ČNB je vyhlašuje v pracovní dny po 14:30.'),
            );
    });

    it('answers a failure with the fixed danger text, stores nothing and alerts nobody', function (): void {
        $canary = Canary::canary('cnb-body');
        rateResourceDown(503, $canary);

        $page = Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Kurzy se nepodařilo stáhnout')
                    ->body('Server ČNB neodpovídá nebo vrátil neplatná data. Zkuste to později. Do té doby se používají poslední známé kurzy.'),
            );

        // The toast is the fixed copy: no status code, no host, no body of the response.
        $page->assertDontSee($canary)->assertDontSee('503')->assertDontSee('cnb.cz');

        expect(rateResourceCount())->toBe(0)
            ->and(DB::table('notifications')->where('notifiable_id', $this->admin->getKey())->count())->toBe(0)
            ->and(Mail::getSymfonyTransport()->messages())->toHaveCount(0);
    });

    it('answers an invalid payload with the same danger text', function (): void {
        Http::fake(['*' => Http::response('{"rates":[]}', 200, ['Content-Type' => 'application/json'])]);

        Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Kurzy se nepodařilo stáhnout')
                    ->body('Server ČNB neodpovídá nebo vrátil neplatná data. Zkuste to později. Do té doby se používají poslední známé kurzy.'),
            );

        expect(rateResourceCount())->toBe(0);
    });

    it('is still allowed on a Saturday and answers with the not-yet-published text', function (): void {
        // Saturday 2026-10-10: the CNB answers Friday's list, and the action is never gated by the calendar.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00:00', 'UTC'));
        rateResourceServes('2026-10-09');

        Livewire::test(ListExchangeRates::class)
            ->callAction('downloadToday')
            ->assertNotified(
                Notification::make()
                    ->info()
                    ->title('Nové kurzy zatím nejsou k dispozici.')
                    ->body('Poslední platné kurzy jsou ze dne 9. 10. 2026. ČNB je vyhlašuje v pracovní dny po 14:30.'),
            );

        Http::assertSentCount(1);
    });

    it('asks the CNB once per click when nothing is stored for today', function (): void {
        rateResourceServes('2026-10-14');

        Livewire::test(ListExchangeRates::class)->callAction('downloadToday');

        Http::assertSentCount(1);
    });
});

describe('the alert link', function (): void {
    it('leads the final-run alert to this overview', function (): void {
        Http::fake(['*' => Http::response('down', 503)]);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 16:00:00', 'UTC'));
        rateResourceStore('2026-10-13');

        $exit = Artisan::call('kokpit:rates:download', ['--final' => true]);

        $row = DB::table('notifications')->where('notifiable_id', $this->admin->getKey())->first();
        $data = json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($data['actions'][0]['label'])->toBe('Otevřít kurzy ČNB')
            ->and($data['actions'][0]['url'])->toBe(ExchangeRateResource::getUrl('index'));
    });
});

describe('who may use it', function (): void {
    it('refuses a Partner the page, its Livewire component and the stats widget', function (): void {
        $this->actingAs(Canary::partnerFor(ApiFixtures::client()->id));

        $this->get(ExchangeRateResource::getUrl('index'))->assertForbidden();

        Livewire::test(ListExchangeRates::class)->assertForbidden();
        Livewire::test(ExchangeRateStats::class)->assertForbidden();
    });

    it('refuses a forged download call by a Partner on a page mounted by the Admin', function (): void {
        rateResourceServes('2026-10-14');
        $partner = Canary::partnerFor(ApiFixtures::client()->id);

        foreach ([
            ['mountAction', ['downloadToday']],
            ['callMountedAction', []],
        ] as [$method, $arguments]) {
            $this->actingAs($this->admin);
            $page = Livewire::test(ListExchangeRates::class);
            $this->actingAs($partner);

            $page->call($method, ...$arguments)->assertForbidden();
        }

        Http::assertNothingSent();
        expect(rateResourceCount())->toBe(0);
    });

    it('sends a guest to the login', function (): void {
        auth()->logout();

        $this->get(ExchangeRateResource::getUrl('index'))->assertRedirect();
    });

    it('shows the Admin the item in the group Správa', function (): void {
        expect(rateResourceNavigation())->toContain('Správa/Kurzy ČNB')
            ->and(ExchangeRateResource::getNavigationSort())->toBe(84);
    });

    it('shows a Partner no navigation item', function (): void {
        $this->actingAs(Canary::partnerFor(ApiFixtures::client()->id));

        expect(rateResourceNavigation())->not->toContain('Správa/Kurzy ČNB');
    });
});

describe('immutable lists and no calculator', function (): void {
    it('has no create, edit, delete or bulk action and no other page', function (): void {
        rateResourceTwoLists();

        $page = Livewire::test(ListExchangeRates::class);
        $table = $page->instance()->getTable();

        expect(array_keys(ExchangeRateResource::getPages()))->toBe(['index'])
            ->and(ExchangeRateResource::canCreate())->toBeFalse()
            ->and($table->getBulkActions())->toBe([])
            ->and($table->getFlatRecordActions())->toBe([])
            ->and($table->getHeaderActions())->toBe([])
            ->and(array_map(static fn ($action) => $action->getName(), $page->instance()->getCachedHeaderActions()))->toBe(['downloadToday']);
    });

    it('is not globally searchable and has no conversion calculator', function (): void {
        rateResourceTwoLists();

        expect(ExchangeRateResource::canGloballySearch())->toBeFalse();

        $this->get(ExchangeRateResource::getUrl('index'))
            ->assertOk()
            ->assertDontSee('Přepočet')
            ->assertDontSee('Převést');
    });
});
