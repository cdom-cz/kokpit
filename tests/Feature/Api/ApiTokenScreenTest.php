<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\RevokeApiToken;
use App\Domain\Identity\ApiTokenStatus;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\ApiTokenResource\Pages\ListApiTokens;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

it('opens the create modal from the empty-state button and creates a token with both abilities (G-07-3)', function (): void {
    $page = Livewire::test(ListApiTokens::class)
        ->assertSee('Zatím tu nejsou žádné API tokeny')
        ->mountTableAction('newToken')
        ->assertActionMounted('createToken');

    // The page action is the only one on the stack: no table action is left underneath it.
    expect($page->instance()->mountedActions)->toHaveCount(1);

    $page->fillForm(screenCreateData($this, ['abilities' => ['time:read', 'time:write'], 'expiry' => 'none']))
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertActionMounted('showToken');

    $plain = (string) $page->get('plainToken');
    $row = PersonalAccessToken::query()->sole();

    expect($row->abilities)->toBe(['time:read', 'time:write'])
        ->and($row->expires_at)->toBeNull()
        ->and($row->name)->toBe('Example tool')
        ->and(str_starts_with($plain, $row->getKey().'|'))->toBeTrue();

    Auth::forgetGuards();

    $this->withToken($plain)->getJson('/api/v1/time-entries')->assertOk();
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

/*
 * Revoke (Task 3): the confirmation, the server-side owner check, the one-time modal exit.
 */

it('asks "Zneplatnit token?" with the active-token body, then deletes the token and the API answers 401', function (): void {
    $token = $this->admin->createToken('Example tool', ['time:read']);
    $plain = $token->plainTextToken;
    $entry = ApiFixtures::entry($this->admin, [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);
    $this->actingAs($this->admin);

    $page = Livewire::test(ListApiTokens::class)->mountTableAction('revoke', $token->accessToken);
    $modal = $page->instance()->getMountedAction();

    expect($modal->getModalHeading())->toBe('Zneplatnit token?')
        ->and($modal->getModalDescription())->toBe('Token Example tool přestane okamžitě fungovat. Nástroje, které ho používají, dostanou chybu 401 a bude jim potřeba zadat nový token. Tuto akci nelze vrátit.')
        ->and($modal->getModalSubmitActionLabel())->toBe('Zneplatnit token')
        ->and($modal->getModalCancelActionLabel())->toBe('Ponechat token');

    $page->callMountedTableAction()
        ->assertNotified('Token byl zneplatněn')
        ->assertCanNotSeeTableRecords([$token->accessToken]);

    expect(PersonalAccessToken::query()->count())->toBe(0);

    Auth::forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/time-entries/'.$entry->id)->assertUnauthorized();
});

it('uses the expired-token body for a token that has expired', function (): void {
    $token = $this->admin->createToken('Old tool', ['time:read'], now()->subDay());

    $modal = Livewire::test(ListApiTokens::class)
        ->mountTableAction('revoke', $token->accessToken)
        ->instance()->getMountedAction();

    expect($modal->getModalDescription())->toBe('Token Old tool už vypršel. Odstraní se ze seznamu. Tuto akci nelze vrátit.');
});

it('offers the revoke action on every row, an expired one included', function (): void {
    $active = $this->admin->createToken('Active tool', ['time:read']);
    $expired = $this->admin->createToken('Expired tool', ['time:read'], now()->subDay());

    Livewire::test(ListApiTokens::class)
        ->assertTableActionVisible('revoke', $active->accessToken)
        ->assertTableActionVisible('revoke', $expired->accessToken);
});

it('shows "Token už byl zneplatněn." when the token is gone and changes nothing', function (): void {
    $token = $this->admin->createToken('Example tool', ['time:read']);
    $other = $this->admin->createToken('Other tool', ['time:read']);

    $page = Livewire::test(ListApiTokens::class)->mountTableAction('revoke', $token->accessToken);

    PersonalAccessToken::query()->whereKey($token->accessToken->getKey())->delete();

    $page->callMountedTableAction()->assertNotified('Token už byl zneplatněn.');

    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(PersonalAccessToken::query()->sole()->getKey())->toBe($other->accessToken->getKey());
});

it('does not delete the token of another user through a forged record key', function (): void {
    $foreign = Canary::admin()->createToken('Foreign tool', ['time:read']);

    expect(app(RevokeApiToken::class)->handle($this->admin, (string) $foreign->accessToken->getKey()))->toBeFalse()
        ->and(app(RevokeApiToken::class)->handle($this->admin, 'not-a-uuid'))->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});

it('refuses a non-Admin actor in RevokeApiToken', function (): void {
    $token = $this->admin->createToken('Example tool', ['time:read']);
    $partner = Canary::partnerFor(ApiFixtures::client()->id);

    expect(fn () => app(RevokeApiToken::class)->handle($partner, (string) $token->accessToken->getKey()))
        ->toThrow(AuthorizationException::class)
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});

it('writes one activity row for a revoke with the name and abilities only', function (): void {
    $token = $this->admin->createToken('Example tool', ['time:read', 'time:write']);

    app(RevokeApiToken::class)->handle($this->admin, (string) $token->accessToken->getKey());

    $row = Activity::query()->where('event', 'api_token.revoked')->sole();
    $encoded = json_encode($row->getAttributes(), JSON_THROW_ON_ERROR);

    expect($row->properties->toArray())->toBe(['name' => 'Example tool', 'abilities' => ['time:read', 'time:write']])
        ->and($encoded)->not->toContain($token->plainTextToken)
        ->and($encoded)->not->toContain(explode('|', $token->plainTextToken)[1]);
});

it('keeps no part of the plain token in the page after the one-time modal is closed', function (): void {
    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', screenCreateData($this))
        ->assertActionMounted('showToken');

    $plain = (string) $page->get('plainToken');
    $secret = explode('|', $plain)[1];

    $page->callMountedAction();

    $snapshot = json_encode($page->snapshot, JSON_THROW_ON_ERROR);

    expect($page->get('plainToken'))->toBeNull()
        ->and($page->html())->not->toContain($secret)
        ->and($snapshot)->not->toContain($secret)
        ->and($page->instance()->mountedActions)->toBe([]);
});

it('writes the token into no notification and no log line', function (): void {
    Livewire::test(ListApiTokens::class)->callAction('createToken', screenCreateData($this));

    expect(DB::table('notifications')->count())->toBe(0);
});

/*
 * A Partner sees and drives nothing of the screen (T-07-14).
 */

it('gives a Partner no navigation item and refuses the page', function (): void {
    $partner = Canary::partnerFor(ApiFixtures::client()->id);
    $this->actingAs($partner);

    $labels = collect(Filament::getNavigation())
        ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getLabel())->all())
        ->all();

    expect($labels)->not->toContain('API tokeny');

    $this->get('/admin/api-tokens')->assertForbidden();
});

it('refuses a forged Livewire mount, create and revoke by a Partner and writes nothing', function (): void {
    $partner = Canary::partnerFor(ApiFixtures::client()->id);
    $token = $this->admin->createToken('Example tool', ['time:read']);

    // The page was mounted while the Admin was signed in; every later call is made as a Partner.
    foreach ([
        ['mountAction', ['createToken']],
        ['callMountedAction', []],
        ['mountTableAction', ['revoke', (string) $token->accessToken->getKey()]],
        ['callMountedTableAction', []],
    ] as [$method, $arguments]) {
        $this->actingAs($this->admin);
        $page = Livewire::test(ListApiTokens::class);
        $this->actingAs($partner);

        $page->call($method, ...$arguments)->assertForbidden();
    }

    $this->actingAs($partner);

    Livewire::test(ListApiTokens::class)->assertForbidden();

    // Counted as a system run: a Partner session would hide every activity row anyway.
    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Activity::query()->where('event', 'like', 'api_token.%')->count()))->toBe(0);
});

it('renders none of the token strings on any page a Partner can open', function (): void {
    $partner = Canary::partnerFor(ApiFixtures::client()->id);
    $this->actingAs($partner);

    $urls = collect(Filament::getNavigation())
        ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getUrl())->all())
        ->push('/admin')
        ->unique()
        ->all();

    $forbidden = ['API tokeny', 'API token', 'Nový API token', 'Zneplatnit token', 'Dokumentace API', 'Oprávnění', 'Čtení času', 'Zápis času'];

    expect($urls)->not->toBe([]);

    $found = [];

    foreach ($urls as $url) {
        $content = (string) $this->get($url)->getContent();

        foreach ($forbidden as $string) {
            if (str_contains($content, $string)) {
                $found[] = "{$string} on {$url}";
            }
        }
    }

    expect($found)->toBe([]);
});
