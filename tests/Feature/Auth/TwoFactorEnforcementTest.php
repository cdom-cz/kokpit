<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Providers\AppServiceProvider;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * A user with the given role; the password and the TOTP secret are generated.
 *
 * @return array{0: User, 1: string, 2: string|null} user, plain password, plain TOTP secret
 */
function panelUser(RoleName $role, bool $withSecret = false): array
{
    $password = Str::password(20);
    $secret = $withSecret ? app(Google2FA::class)->generateSecretKey() : null;

    $user = User::factory()->create(['email' => exampleEmail(), 'password' => $password]);
    $user->assignRole($role->value);

    if ($secret !== null) {
        $user->forceFill(['app_authentication_secret' => $secret])->save();
    }

    return [$user, $password, $secret];
}

function currentTotpCode(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

it('sends an Admin without a TOTP secret to the set-up page when enforcement is on', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    [$admin] = panelUser(RoleName::Admin);

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertRedirectContains('multi-factor-authentication/set-up');
});

it('renders the set-up page without another redirect', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    [$admin] = panelUser(RoleName::Admin);

    $location = $this->actingAs($admin)->get('/admin')->headers->get('Location');

    expect($location)->toBeString();

    $this->actingAs($admin)->get($location)->assertOk();
});

it('keeps an Admin without a TOTP secret away from every panel page, not only the dashboard', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    [$admin] = panelUser(RoleName::Admin);

    $this->actingAs($admin)->get('/admin/profile')->assertRedirectContains('multi-factor-authentication/set-up');
});

it('lets an Admin in without a secret when enforcement is off', function (): void {
    config(['kokpit.require_admin_two_factor' => false]);
    [$admin] = panelUser(RoleName::Admin);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('never forces a Partner to set up two-factor authentication', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    [$partner] = panelUser(RoleName::Partner);

    $this->actingAs($partner)->get('/admin')->assertOk();
});

it('lets an Admin with a stored secret into the panel when enforcement is on', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    [$admin] = panelUser(RoleName::Admin, withSecret: true);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('sends the Admin back to set-up after the secret is cleared', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    [$admin] = panelUser(RoleName::Admin, withSecret: true);

    $this->actingAs($admin)->get('/admin')->assertOk();

    $admin->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();

    $this->actingAs($admin->fresh())->get('/admin')->assertRedirectContains('multi-factor-authentication/set-up');
});

it('shows the profile page with the authenticator app to a signed-in Admin', function (): void {
    config(['kokpit.require_admin_two_factor' => false]);
    [$admin] = panelUser(RoleName::Admin);

    $this->actingAs($admin)->get('/admin/profile')->assertOk();
});

it('does not sign an Admin with a secret in until a valid code is given', function (): void {
    [$admin, $password, $secret] = panelUser(RoleName::Admin, withSecret: true);

    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => $password])
        ->call('authenticate');

    $this->assertGuest();
    $component->assertNoRedirect();

    $component
        ->set('data.multiFactor.app.code', currentTotpCode($secret) === '000000' ? '111111' : '000000')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.code']);

    $this->assertGuest();

    $component
        ->set('data.multiFactor.app.code', currentTotpCode($secret))
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($admin);
});

it('signs an Admin in with a recovery code and refuses to accept it twice', function (): void {
    [$admin, $password] = panelUser(RoleName::Admin, withSecret: true);

    $provider = AppAuthentication::make()->recoverable();
    $codes = $provider->generateRecoveryCodes();
    $provider->saveRecoveryCodes($admin, $codes);

    $signIn = function (string $recoveryCode) use ($admin, $password) {
        $component = Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => $password])
            ->call('authenticate');

        return $component
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $recoveryCode)
            ->call('authenticate');
    };

    $signIn($codes[0])->assertHasNoErrors()->assertRedirect();
    $this->assertAuthenticatedAs($admin);

    auth()->logout();

    $signIn($codes[0])->assertHasErrors(['data.multiFactor.app.recoveryCode']);
    $this->assertGuest();
});

it('stores the secret and the recovery codes encrypted and hides both', function (): void {
    [$admin, , $secret] = panelUser(RoleName::Admin, withSecret: true);
    $codes = AppAuthentication::make()->recoverable()->generateRecoveryCodes();
    AppAuthentication::make()->recoverable()->saveRecoveryCodes($admin, $codes);

    $raw = DB::table('users')->where('id', $admin->id)->first();

    expect($raw->app_authentication_secret)->not->toBe($secret)
        ->and($raw->app_authentication_secret)->not->toContain($secret)
        ->and($raw->app_authentication_recovery_codes)->not->toContain($codes[0])
        ->and($admin->fresh()->app_authentication_secret)->toBe($secret)
        ->and($admin->fresh()->toArray())->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes', 'password']);
});

it('refuses to boot the application provider in production with enforcement off', function (): void {
    config(['kokpit.require_admin_two_factor' => false]);
    $this->app['env'] = 'production';

    (new AppServiceProvider($this->app))->boot();
})->throws(RuntimeException::class);

it('boots the application provider in production with enforcement on', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    $this->app['env'] = 'production';

    (new AppServiceProvider($this->app))->boot();

    expect(true)->toBeTrue();
});

it('refuses to start a real production process with enforcement off', function (): void {
    $process = new Process(
        [PHP_BINARY, 'artisan', '--version'],
        base_path(),
        ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'KOKPIT_REQUIRE_ADMIN_2FA' => 'false'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->toContain('RuntimeException');
});
