<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FAQRCode\Google2FA;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * An Admin who has set up two-factor authentication.
 */
function adminWithTwoFactor(): User
{
    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $admin->forceFill(['app_authentication_secret' => app(Google2FA::class)->generateSecretKey()])->save();

    $provider = AppAuthentication::make()->recoverable();
    $provider->saveRecoveryCodes($admin, $provider->generateRecoveryCodes());

    return $admin->fresh();
}

/**
 * The raw column values, bypassing the encrypted casts.
 *
 * @return array{secret: string|null, codes: string|null}
 */
function rawTwoFactorColumns(User $user): array
{
    $row = DB::table('users')->where('id', $user->id)->first();

    return ['secret' => $row->app_authentication_secret, 'codes' => $row->app_authentication_recovery_codes];
}

it('clears the secret and the recovery codes with --force', function (): void {
    $admin = adminWithTwoFactor();

    expect(rawTwoFactorColumns($admin)['secret'])->not->toBeNull()
        ->and(rawTwoFactorColumns($admin)['codes'])->not->toBeNull();

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email, '--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain(__('kokpit.reset_2fa.done'))
        ->assertExitCode(0);

    expect(rawTwoFactorColumns($admin))->toBe(['secret' => null, 'codes' => null]);
});

it('clears the columns after an interactive yes', function (): void {
    $admin = adminWithTwoFactor();

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email])
        ->expectsConfirmation(__('kokpit.reset_2fa.confirm', ['email' => $admin->email]), 'yes')
        ->expectsOutputToContain(__('kokpit.reset_2fa.done'))
        ->assertExitCode(0);

    expect(rawTwoFactorColumns($admin))->toBe(['secret' => null, 'codes' => null]);
});

it('changes nothing after an interactive no', function (): void {
    $admin = adminWithTwoFactor();
    $before = rawTwoFactorColumns($admin);

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email])
        ->expectsConfirmation(__('kokpit.reset_2fa.confirm', ['email' => $admin->email]), 'no')
        ->expectsOutputToContain(__('kokpit.reset_2fa.aborted'))
        ->assertExitCode(1);

    expect(rawTwoFactorColumns($admin))->toBe($before);
});

it('refuses non-interactive use without --force and changes nothing', function (): void {
    $admin = adminWithTwoFactor();
    $before = rawTwoFactorColumns($admin);

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email, '--no-interaction' => true])
        ->expectsOutputToContain(__('kokpit.reset_2fa.force_required'))
        ->assertExitCode(1);

    expect(rawTwoFactorColumns($admin))->toBe($before);
});

it('exits 1 for an unknown e-mail address and changes nothing', function (): void {
    $admin = adminWithTwoFactor();
    $before = rawTwoFactorColumns($admin);

    $this->artisan('kokpit:admin:reset-2fa', ['email' => exampleEmail(), '--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain(__('kokpit.reset_2fa.not_found'))
        ->assertExitCode(1);

    expect(rawTwoFactorColumns($admin))->toBe($before);
});

it('finds the user regardless of the case of the typed address', function (): void {
    $admin = adminWithTwoFactor();

    $this->artisan('kokpit:admin:reset-2fa', ['email' => strtoupper($admin->email), '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    expect(rawTwoFactorColumns($admin))->toBe(['secret' => null, 'codes' => null]);
});

it('only touches the named user', function (): void {
    $admin = adminWithTwoFactor();
    $other = adminWithTwoFactor();
    $otherBefore = rawTwoFactorColumns($other);

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email, '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    expect(rawTwoFactorColumns($other))->toBe($otherBefore);
});

it('logs the user id and never the e-mail address', function (): void {
    $admin = adminWithTwoFactor();
    Log::spy();

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email, '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []) use ($admin): bool {
        $logged = $message.json_encode($context);

        return ($context['user_id'] ?? null) === $admin->id && ! str_contains($logged, $admin->email);
    });
});

it('does not log anything when the reset is refused', function (): void {
    $admin = adminWithTwoFactor();
    Log::spy();

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email, '--no-interaction' => true])
        ->assertExitCode(1);

    Log::shouldNotHaveReceived('warning');
});

it('sends the Admin to the set-up page again after the reset', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);
    $admin = adminWithTwoFactor();

    $this->actingAs($admin)->get('/admin')->assertOk();

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $admin->email, '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    $this->actingAs($admin->fresh())->get('/admin')->assertRedirectContains('multi-factor-authentication/set-up');
});
