<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The panel gate follows the account and the client row on every request
 * (Phase 4 D-04, D-11): a deactivated user, and a Partner whose client is
 * archived or missing, is refused at login with the generic credential message
 * and loses an existing session on the next request. Restoring the client or
 * reactivating the user brings the access back without any other step.
 */

/**
 * Runs the callback as a system run, the way an Admin action or a console
 * command changes a client.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function lockoutAsSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

function lockoutArchive(string $clientId): void
{
    lockoutAsSystem(static fn () => Client::query()->findOrFail($clientId)->delete());
}

function lockoutRestore(string $clientId): void
{
    lockoutAsSystem(static fn () => Client::withTrashed()->findOrFail($clientId)->restore());
}

function lockoutDeactivate(User $user): void
{
    $user->forceFill(['deactivated_at' => now()])->save();
}

function lockoutReactivate(User $user): void
{
    $user->forceFill(['deactivated_at' => null])->save();
}

/**
 * The first error shown on the e-mail field after a login attempt.
 */
function lockoutLoginMessage(string $email, string $password): string
{
    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate');

    return (string) collect($component->errors()->get('data.email'))->first();
}

it('lets a Partner of an active client in, refuses the next request after the client is archived and admits again after the restore', function (): void {
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);

    $this->actingAs($partner)->get('/admin')->assertOk();

    lockoutArchive($clientId);
    $this->actingAs($partner)->get('/admin')->assertForbidden();

    lockoutRestore($clientId);
    $this->actingAs($partner)->get('/admin')->assertOk();
});

it('refuses the next request of a deactivated Partner and admits again after the reactivation', function (): void {
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);

    $this->actingAs($partner)->get('/admin')->assertOk();

    lockoutDeactivate($partner);
    $this->actingAs($partner)->get('/admin')->assertForbidden();

    lockoutReactivate($partner);
    $this->actingAs($partner)->get('/admin')->assertOk();
});

it('refuses a deactivated Admin', function (): void {
    $admin = Canary::admin();

    $this->actingAs($admin)->get('/admin')->assertOk();

    lockoutDeactivate($admin);
    $this->actingAs($admin)->get('/admin')->assertForbidden();

    lockoutReactivate($admin);
    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('does not tie the Admin to a client row', function (): void {
    [$clientId] = Canary::twoClients();
    lockoutArchive($clientId);

    $this->actingAs(Canary::admin())->get('/admin')->assertOk();
});

it('refuses a Partner without a client', function (): void {
    $this->actingAs(Canary::partnerFor(null))->get('/admin')->assertForbidden();
});

it('reads the client again on every canAccessPanel call: true, archive, false on the same user instance', function (): void {
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);
    $panel = Filament::getPanel('admin');

    expect($partner->canAccessPanel($panel))->toBeTrue();

    lockoutArchive($clientId);

    expect($partner->canAccessPanel($panel))->toBeFalse();

    lockoutRestore($clientId);

    expect($partner->canAccessPanel($panel))->toBeTrue();
});

it('reads the deactivation again on every canAccessPanel call on the same user instance', function (): void {
    $admin = Canary::admin();
    $panel = Filament::getPanel('admin');

    expect($admin->canAccessPanel($panel))->toBeTrue();

    lockoutDeactivate($admin);

    expect($admin->canAccessPanel($panel))->toBeFalse();

    lockoutReactivate($admin);

    expect($admin->canAccessPanel($panel))->toBeTrue();
});

it('keeps deactivated_at out of mass assignment', function (): void {
    $user = Canary::admin();

    // Tests run with silent discarding turned into an exception; production drops the key.
    expect(fn () => $user->fill(['deactivated_at' => now()]))->toThrow(MassAssignmentException::class)
        ->and($user->fresh()?->deactivated_at)->toBeNull();
});

it('shows a deactivated Partner who knows the correct password the same message as a wrong password, and signs nobody in', function (): void {
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);

    $wrongPassword = lockoutLoginMessage($partner->email, 'definitely-not-the-password');

    lockoutDeactivate($partner);
    $deactivated = lockoutLoginMessage($partner->email, 'password');

    expect($wrongPassword)->not->toBe('')
        ->and($deactivated)->toBe($wrongPassword);
    $this->assertGuest();
});

it('shows a Partner of an archived client who knows the correct password the same message as a wrong password, and signs nobody in', function (): void {
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);

    $wrongPassword = lockoutLoginMessage($partner->email, 'definitely-not-the-password');

    lockoutArchive($clientId);
    $archived = lockoutLoginMessage($partner->email, 'password');

    expect($wrongPassword)->not->toBe('')
        ->and($archived)->toBe($wrongPassword);
    $this->assertGuest();
});

it('shows a Partner without a client row the same message as a wrong password, and signs nobody in', function (): void {
    $partner = Canary::partnerFor(null);

    $wrongPassword = lockoutLoginMessage($partner->email, 'definitely-not-the-password');
    $missing = lockoutLoginMessage($partner->email, 'password');

    expect($wrongPassword)->not->toBe('')
        ->and($missing)->toBe($wrongPassword);
    $this->assertGuest();
});

it('signs a Partner in again at the login page after the client is restored', function (): void {
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);

    lockoutArchive($clientId);
    lockoutLoginMessage($partner->email, 'password');
    $this->assertGuest();

    lockoutRestore($clientId);
    Livewire::test(Login::class)
        ->fillForm(['email' => $partner->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($partner);
});
