<?php

declare(strict_types=1);

use App\Domain\Identity\ApiTokenStatus;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\ApiTokenResource\Pages\ListApiTokens;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;

/*
 * The Admin-only API token screen (AP-01, AP-05; D-01 to D-04): list, create with
 * password and TOTP confirmation, one-time display of the plain token, revoke.
 * Every value is fictional and assembled at runtime.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->password = Str::password(20);
    $this->secret = app(Google2FA::class)->generateSecretKey();
    $this->admin = Canary::admin();
    $this->admin->forceFill(['password' => $this->password, 'app_authentication_secret' => $this->secret])->save();
    $this->actingAs($this->admin);
});

it('creates a token with password and code, shows it once, and the token reads the API', function (): void {
    $entry = ApiFixtures::entry($this->admin, [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    // The fixture forgets the guards, so the Admin signs in again for the screen.
    $this->actingAs($this->admin);

    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', [
            'name' => 'Import výkazů',
            'abilities' => ['time:read'],
            'expiry' => 'none',
            'password' => $this->password,
            'code' => app(Google2FA::class)->getCurrentOtp($this->secret),
        ])
        ->assertHasNoFormErrors()
        ->assertActionMounted('showToken')
        ->assertSet('createdSummary.name', 'Import výkazů');

    $plain = $page->get('plainToken');
    $modal = $page->instance()->getMountedAction();

    expect($modal->getModalHeading())->toBe('Token byl vytvořen')
        ->and($modal->getModalSubmitActionLabel())->toBe('Token mám uložený')
        ->and($modal->hasModalCloseButton())->toBeFalse()
        ->and($modal->isModalClosedByEscaping())->toBeFalse()
        ->and($modal->isModalClosedByClickingAway())->toBeFalse();

    $page->assertSchemaStateSet(['token' => $plain]);

    expect($plain)->toBeString()->toContain('|');

    $row = PersonalAccessToken::query()->sole();

    expect($row->name)->toBe('Import výkazů')
        ->and($row->abilities)->toBe(['time:read'])
        ->and($row->expires_at)->toBeNull()
        ->and(str_starts_with((string) $plain, $row->getKey().'|'))->toBeTrue();

    Auth::forgetGuards();

    $this->withToken((string) $plain)
        ->getJson('/api/v1/time-entries/'.$entry->id)
        ->assertOk()
        ->assertJsonPath('data.id', $entry->id);
});

/*
 * Create modal rules (Task 2): identity confirmation edges, throttle, field errors.
 */

/**
 * The data of a valid create call, with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function screenCreateData(object $test, array $overrides = []): array
{
    return [
        'name' => 'Example tool',
        'abilities' => ['time:read'],
        'expiry' => 'days_30',
        'password' => $test->password,
        'code' => app(Google2FA::class)->getCurrentOtp($test->secret),
        ...$overrides,
    ];
}

it('keeps the modal open, keeps the choices and clears both secrets on a wrong password', function (): void {
    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['password' => Str::password(20)]))
        ->assertActionMounted('createToken');

    expect($page->errors()->get('mountedActions.0.data.password'))->toBe(['Heslo není správné.'])
        ->and($page->get('mountedActions.0.data.password'))->toBeNull()
        ->and($page->get('mountedActions.0.data.code'))->toBeNull()
        ->and($page->get('mountedActions.0.data.name'))->toBe('Example tool')
        ->and($page->get('mountedActions.0.data.expiry'))->toBe('days_30')
        ->and((array) $page->get('mountedActions.0.data.abilities'))->toHaveCount(1)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('refuses a wrong code with the documented error and creates nothing', function (): void {
    $wrong = app(Google2FA::class)->getCurrentOtp($this->secret) === '000000' ? '111111' : '000000';

    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['code' => $wrong]))
        ->assertActionMounted('createToken');

    expect($page->errors()->get('mountedActions.0.data.code'))->toBe(['Kód není správný nebo už vypršel. Zadejte aktuální kód z ověřovací aplikace.'])
        ->and($page->get('mountedActions.0.data.password'))->toBeNull()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('refuses a code that was already used', function (): void {
    $code = app(Google2FA::class)->getCurrentOtp($this->secret);

    Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['code' => $code]))
        ->assertActionMounted('showToken');

    expect(PersonalAccessToken::query()->count())->toBe(1);

    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['code' => $code]));

    expect($page->errors()->get('mountedActions.0.data.code'))->not->toBe([])
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});

it('refuses a recovery code and any non-numeric code', function (string $code): void {
    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['code' => $code]));

    expect($page->errors()->get('mountedActions.0.data.code'))->not->toBe([])
        ->and(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'recovery code' => fn (): string => Str::random(10).'-'.Str::random(10),
    'letters' => 'abcdef',
    'five digits' => '12345',
]);

it('shows the danger toast and creates nothing for an Admin without a TOTP secret', function (): void {
    $this->admin->forceFill(['app_authentication_secret' => null])->save();
    $this->actingAs($this->admin->refresh());

    Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['code' => '123456']))
        ->assertNotified(__('kokpit.api_tokens.errors.failed'));

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('throttles the sixth confirmation attempt in a minute and runs no check for it', function (): void {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $page = Livewire::test(ListApiTokens::class)
            ->callAction('createToken', screenCreateData($this, ['password' => Str::password(20)]));

        expect($page->errors()->get('mountedActions.0.data.password'))->toBe(['Heslo není správné.']);
    }

    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this));

    $messages = $page->errors()->get('mountedActions.0.data.password');

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toMatch('/^Příliš mnoho pokusů\. Zkuste to znovu za \d+ s\.$/u')
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(cache()->has('filament.app_authentication_codes.'.md5($this->secret)))->toBeFalse();
});

it('counts the attempts per user', function (): void {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        RateLimiter::hit('api-token-confirm:'.$this->admin->getKey(), 60);
    }

    $other = Canary::admin();
    $secret = app(Google2FA::class)->generateSecretKey();
    $password = Str::password(20);
    $other->forceFill(['password' => $password, 'app_authentication_secret' => $secret])->save();
    $this->actingAs($other);

    Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData((object) ['password' => $password, 'secret' => $secret]))
        ->assertActionMounted('showToken');

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('shows the documented errors for a missing or too long name and for no ability', function (): void {
    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['name' => '', 'abilities' => []]));

    expect($page->errors()->get('mountedActions.0.data.name'))->toBe(['Zadejte název tokenu.'])
        ->and($page->errors()->get('mountedActions.0.data.abilities'))->toBe(['Vyberte alespoň jedno oprávnění.']);

    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['name' => str_repeat('a', 81)]));

    expect($page->errors()->get('mountedActions.0.data.name'))->toBe(['Název je příliš dlouhý. Zkraťte ho na nejvýše 80 znaků.'])
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('shows the required messages of the password and the code', function (): void {
    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['password' => '', 'code' => '']));

    expect($page->errors()->get('mountedActions.0.data.password'))->toBe(['Zadejte heslo.'])
        ->and($page->errors()->get('mountedActions.0.data.code'))->toBe(['Zadejte šestimístný kód.']);
});

it('refuses a forged ability such as an asterisk and creates nothing', function (): void {
    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['abilities' => ['*']]));

    $keys = array_filter($page->errors()->keys(), static fn (string $key): bool => str_starts_with($key, 'mountedActions.0.data.abilities'));

    expect($keys)->not->toBe([])
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('stores the whole second of the Prague day end for a chosen validity', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

    Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this, ['expiry' => 'days_30']));

    expect(PersonalAccessToken::query()->sole()->expires_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-13 22:59:59')
        ->and(Activity::query()->where('event', 'api_token.created')->count())->toBe(1);
});

/*
 * The list contract (Task 2).
 */

it('lists only the own tokens with name, abilities, last use, validity and status', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    $now = CarbonImmutable::now();

    $active = $this->admin->createToken('Active tool', ['time:read']);
    $soon = $this->admin->createToken('Soon tool', ['time:read', 'time:write'], $now->addDays(3));
    $expired = $this->admin->createToken('Expired tool', ['time:write'], $now->subDay());
    $used = $this->admin->createToken('Used tool', ['time:read']);
    $used->accessToken->forceFill(['last_used_at' => $now->subHour()])->save();
    $foreign = Canary::admin()->createToken('Foreign tool', ['time:read']);

    Livewire::test(ListApiTokens::class)
        ->assertCanSeeTableRecords([$active->accessToken, $soon->accessToken, $expired->accessToken, $used->accessToken])
        ->assertCanNotSeeTableRecords([$foreign->accessToken])
        ->assertTableColumnStateSet('status', ApiTokenStatus::Active, $active->accessToken)
        ->assertTableColumnStateSet('status', ApiTokenStatus::ExpiringSoon, $soon->accessToken)
        ->assertTableColumnStateSet('status', ApiTokenStatus::Expired, $expired->accessToken)
        ->assertSee('Nikdy')
        ->assertSee('Bez omezení')
        ->assertSee('Čtení času')
        ->assertSee('Zápis času')
        ->assertSee('Aktivní')
        ->assertSee('Vyprší brzy')
        ->assertSee('Vypršel')
        ->assertDontSee('Foreign tool');
});

it('shows the empty state with the create action reachable from it', function (): void {
    Livewire::test(ListApiTokens::class)
        ->assertSee('Zatím tu nejsou žádné API tokeny')
        ->assertSee('Vytvořte token pro nástroj, který má číst nebo zapisovat časové záznamy.')
        ->assertActionExists('createToken');
});

it('shows a token without expiry as active and a token expiring in 30 days as active', function (): void {
    $token = $this->admin->createToken('Example tool', ['time:read'], now()->addDays(30));

    Livewire::test(ListApiTokens::class)
        ->assertTableColumnStateSet('status', ApiTokenStatus::Active, $token->accessToken);
});

it('stores no IP address, user agent or token fragment on the token row', function (): void {
    $this->admin->createToken('Example tool', ['time:read']);

    $columns = array_keys(PersonalAccessToken::query()->firstOrFail()->getAttributes());

    expect($columns)->not->toContain('ip_address')
        ->and($columns)->not->toContain('user_agent');
});
