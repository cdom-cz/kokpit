<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use App\Domain\Clients\Queries\ContactBirthdays;
use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ClientResource;
use App\Filament\Widgets\BirthdaysToday;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\TodayPlan;
use App\Filament\Widgets\UnbilledPerClient;
use App\Filament\Widgets\UnpaidInvoices;
use App\Filament\Widgets\UnresolvedPayments;
use App\Filament\Widgets\UpcomingDeadlines;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The dashboard card "Narozeniny dnes" (CL-02, RE-04): the contacts whose birthday is today in Prague, the age they turn
 * when the year is known, the contact modal, and the Admin-only access. Time is frozen at Wednesday 14 October 2026,
 * 10:00 in Prague; every name and address is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

/**
 * A contact of the client with the given birthday.
 */
function birthdaysTodayContact(Client $client, string $name, int $day, int $month, ?int $year = null): Contact
{
    return app(CreateContact::class)->handle($client, [
        'name' => $name,
        'email' => exampleEmail(),
        'birth_day' => $day,
        'birth_month' => $month,
        'birth_year' => $year,
    ]);
}

/**
 * The visible text of rendered HTML, with the whitespace collapsed.
 */
function birthdaysTodayText(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
}

it('lists the contacts with a birthday today and the age they turn, and no one else', function (): void {
    $client = Client::factory()->create(['name' => 'Example Client Birthdays']);
    $withYear = birthdaysTodayContact($client, 'Example born with year', 14, 10, 1985);
    $withoutYear = birthdaysTodayContact($client, 'Example born without year', 14, 10);
    $tomorrow = birthdaysTodayContact($client, 'Example born tomorrow', 15, 10, 1990);
    $archived = Client::factory()->create(['name' => 'Example Client Archived']);
    $archivedContact = birthdaysTodayContact($archived, 'Example archived birthday', 14, 10, 1980);
    $archived->delete();

    $component = Livewire::actingAs($this->admin)->test(BirthdaysToday::class)
        ->assertCanSeeTableRecords([$withYear, $withoutYear])
        ->assertCanNotSeeTableRecords([$tomorrow, $archivedContact]);

    $text = birthdaysTodayText($component->html());

    expect($text)->toContain('Example born with year')
        ->toContain('Example Client Birthdays')
        ->toContain('41. narozeniny')
        ->not->toContain('Example archived birthday')
        ->not->toContain('Example born tomorrow');

    // The contact without a year shows no age: exactly one age is printed.
    expect(substr_count($text, '. narozeniny'))->toBe(1);
});

it('uses the Prague day, so a contact turns up after 22:00 UTC on the day before', function (): void {
    $client = Client::factory()->create();
    $today = birthdaysTodayContact($client, 'Example born on the 14th', 14, 10, 1985);
    $next = birthdaysTodayContact($client, 'Example born on the 15th', 15, 10, 1985);

    // 22:30 UTC is 00:30 on 15 October in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC'));

    Livewire::actingAs($this->admin)->test(BirthdaysToday::class)
        ->assertCanSeeTableRecords([$next])
        ->assertCanNotSeeTableRecords([$today]);
});

it('lists a 29 February birthday on 28 February in a non-leap year and on 29 February in a leap year', function (): void {
    $leapling = birthdaysTodayContact(Client::factory()->create(), 'Example leap day contact', 29, 2, 2000);

    $this->travelTo(CarbonImmutable::parse('2027-02-28 12:00:00', 'UTC'));

    $nonLeap = Livewire::actingAs($this->admin)->test(BirthdaysToday::class)->assertCanSeeTableRecords([$leapling]);

    expect(birthdaysTodayText($nonLeap->html()))->toContain('27. narozeniny');

    $this->travelTo(CarbonImmutable::parse('2028-02-28 12:00:00', 'UTC'));

    Livewire::actingAs($this->admin)->test(BirthdaysToday::class)->assertCanNotSeeTableRecords([$leapling]);

    $this->travelTo(CarbonImmutable::parse('2028-02-29 12:00:00', 'UTC'));

    Livewire::actingAs($this->admin)->test(BirthdaysToday::class)->assertCanSeeTableRecords([$leapling]);
});

it('opens the whole contact in a modal with a link to the client', function (): void {
    $client = Client::factory()->create(['name' => 'Example Client Modal']);
    $contact = birthdaysTodayContact($client, 'Example modal contact', 14, 10, 1985);
    $email = (string) $contact->email;

    $component = Livewire::actingAs($this->admin)->test(BirthdaysToday::class)
        ->mountAction(TestAction::make('showContact')->table($contact));

    // The action modals render as a Livewire partial, so they are not part of the component's own html().
    $html = $component->getMountedActionModalHtml();
    $text = birthdaysTodayText($html);

    expect($text)->toContain('Example modal contact')
        ->toContain('Example Client Modal')
        ->toContain($email)
        ->toContain('14. 10. 1985')
        ->toContain('41. narozeniny')
        ->toContain('Otevřít klienta')
        ->and($html)->toContain(e(ClientResource::getUrl('view', ['record' => $client->id])));
});

it('shows an empty state and a zero summary on a day without a birthday', function (): void {
    birthdaysTodayContact(Client::factory()->create(), 'Example born on another day', 3, 3, 1985);

    $text = birthdaysTodayText(Livewire::actingAs($this->admin)->test(BirthdaysToday::class)->html());

    expect($text)->toContain('Narozeniny dnes')
        ->toContain('Dnes nikdo nemá narozeniny')
        ->toContain('Dnes nikdo')
        ->not->toContain('Example born on another day');
});

it('counts the contacts of today in the collapsed summary in Czech plural forms', function (): void {
    $client = Client::factory()->create();
    birthdaysTodayContact($client, 'Example first today', 14, 10, 1985);

    $one = birthdaysTodayText(Livewire::actingAs($this->admin)->test(BirthdaysToday::class)->html());

    birthdaysTodayContact($client, 'Example second today', 14, 10);

    $two = birthdaysTodayText(Livewire::actingAs($this->admin)->test(BirthdaysToday::class)->html());

    expect($one)->toContain('1 kontakt')
        ->and($two)->toContain('2 kontakty');
});

it('is refused to a Partner, also when mounted by force, and the reader refuses a Partner', function (): void {
    $client = Client::factory()->create();
    birthdaysTodayContact($client, 'Example secret birthday', 14, 10, 1985);
    $partner = Canary::partnerFor($client->id);

    Livewire::actingAs($partner)->test(BirthdaysToday::class)->assertForbidden();

    $this->actingAs($partner);

    $reader = app(ContactBirthdays::class);

    expect(static fn () => $reader->query($reader->today()))->toThrow(AuthorizationException::class)
        // The model alone still yields zero rows to a Partner.
        ->and(Contact::query()->count())->toBe(0);

    $text = birthdaysTodayText((string) $this->get('/admin')->assertOk()->getContent());

    expect($text)->not->toContain('Narozeniny dnes')
        ->not->toContain('Example secret birthday');
});

it('lets a system run read the birthdays', function (): void {
    birthdaysTodayContact(Client::factory()->create(), 'Example system reader', 14, 10, 1985);

    $names = app(PartnerContext::class)->runAsSystem(static function (): array {
        $reader = app(ContactBirthdays::class);

        return $reader->on($reader->today())->pluck('name')->all();
    });

    expect($names)->toBe(['Example system reader']);
});

it('is the third card of the Admin dashboard, right under the plan, with its own card key', function (): void {
    birthdaysTodayContact(Client::factory()->create(), 'Example dashboard birthday', 14, 10, 1985);

    $text = birthdaysTodayText((string) $this->get('/admin')->assertOk()->getContent());

    expect((new Dashboard)->getWidgets())->toBe([HoursStats::class, TodayPlan::class, BirthdaysToday::class, UnpaidInvoices::class, UnresolvedPayments::class, UnbilledPerClient::class, UpcomingDeadlines::class])
        ->and((new BirthdaysToday)->getColumnSpan())->toBe('full')
        ->and(BirthdaysToday::dashboardWidget())->toBe(DashboardWidget::BirthdaysToday)
        ->and(DashboardWidget::BirthdaysToday->value)->toBe('birthdays_today')
        ->and($text)->toContain('Narozeniny dnes')
        ->toContain('Example dashboard birthday');
});
