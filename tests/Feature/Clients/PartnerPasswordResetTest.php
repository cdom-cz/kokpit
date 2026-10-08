<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\SendPartnerPasswordReset;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\PartnerAccountsRelationManager;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Password reset for Partner accounts (US-02, D-04): the Admin sends a reset link from
 * the "Účty" tab, and the panel's public "forgot password" page serves the Partner
 * itself. Notifications are faked, nothing is mailed; every address is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->client = Client::factory()->create();
});

/**
 * The reset notification mailed to the user, or null when none was sent.
 */
function resetNotificationFor(User $user): ?ResetPasswordNotification
{
    $sent = Notification::sent($user, ResetPasswordNotification::class);

    return $sent->first();
}

/**
 * The password reset tokens stored for an e-mail address.
 */
function resetTokenCount(string $email): int
{
    return DB::table('password_reset_tokens')->where('email', $email)->count();
}

/**
 * A password of 14 characters, assembled at runtime so no file holds the literal.
 */
function resetNewPassword(): string
{
    return implode('-', ['alpha', 'bravo', '7391']);
}

/**
 * The accounts tab of a client as the Admin sees it.
 */
function resetTab(Client $client): Testable
{
    return Livewire::test(PartnerAccountsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * The public request page as a guest sees it, with the page rate limit cleared.
 */
function resetRequestPage(): Testable
{
    auth()->forgetGuards();
    Cache::flush();

    return Livewire::test(RequestPasswordReset::class);
}

it('mails one Filament reset notification to an active Partner with a signed link to the reset page', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);

    app(SendPartnerPasswordReset::class)->handle($partner);

    Notification::assertSentToTimes($partner, ResetPasswordNotification::class, 1);
    $url = resetNotificationFor($partner)?->url ?? '';
    $request = Request::create($url);

    expect(URL::hasValidSignature($request))->toBeTrue()
        ->and(Route::getRoutes()->match($request)->getName())->toBe('filament.admin.auth.password-reset.reset')
        ->and($request->query('email'))->toBe($partner->email)
        ->and((string) $request->query('token'))->not->toBe('');
});

it('lets the Partner follow the mailed link as a guest, set a new password and log in with it', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);
    app(SendPartnerPasswordReset::class)->handle($partner);
    $url = resetNotificationFor($partner)?->url ?? '';
    $request = Request::create($url);
    $password = resetNewPassword();

    auth()->forgetGuards();
    $this->get($url)->assertOk();

    Livewire::test(ResetPassword::class, ['email' => $partner->email, 'token' => (string) $request->query('token')])
        ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
        ->call('resetPassword')
        ->assertHasNoFormErrors();

    auth()->forgetGuards();
    Livewire::test(Login::class)
        ->fillForm(['email' => $partner->email, 'password' => $password])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs(User::query()->findOrFail($partner->id));
});

it('requires a new password of at least 12 characters on the reset page', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);
    app(SendPartnerPasswordReset::class)->handle($partner);
    $token = (string) Request::create(resetNotificationFor($partner)?->url ?? '')->query('token');

    auth()->forgetGuards();
    Livewire::test(ResetPassword::class, ['email' => $partner->email, 'token' => $token])
        ->fillForm(['password' => 'short-pw-11', 'passwordConfirmation' => 'short-pw-11'])
        ->call('resetPassword')
        ->assertHasFormErrors(['password']);
});

it('sends nothing for a deactivated Partner and stores no reset token', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);
    $partner->forceFill(['deactivated_at' => now()])->save();

    app(SendPartnerPasswordReset::class)->handle($partner);

    Notification::assertNothingSent();
    expect(resetTokenCount($partner->email))->toBe(0);
});

it('sends nothing for a Partner of an archived client', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);
    $this->client->delete();

    app(SendPartnerPasswordReset::class)->handle($partner);

    Notification::assertNothingSent();
    expect(resetTokenCount($partner->email))->toBe(0);
});

it('refuses the Admin account with a DomainException and sends nothing', function (): void {
    Notification::fake();
    $admin = Canary::admin();

    expect(fn () => app(SendPartnerPasswordReset::class)->handle($admin))->toThrow(DomainException::class);
    Notification::assertNothingSent();
});

it('refuses a second reset within the broker throttle with a DomainException and mails once', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);
    $send = app(SendPartnerPasswordReset::class);

    $send->handle($partner);

    expect(fn () => $send->handle($partner))->toThrow(DomainException::class);
    Notification::assertSentToTimes($partner, ResetPasswordNotification::class, 1);
});

it('sends the reset from the accounts tab and hides the action for a deactivated account', function (): void {
    Notification::fake();
    $this->actingAs(Canary::admin());
    $active = Canary::partnerFor($this->client->id);
    $off = Canary::partnerFor($this->client->id);
    $off->forceFill(['deactivated_at' => now()])->save();

    resetTab($this->client)
        ->assertTableActionVisible('sendPasswordReset', $active)
        ->assertTableActionHidden('sendPasswordReset', $off)
        ->callTableAction('sendPasswordReset', $active)
        ->assertNotified(__('kokpit.partner_accounts.notifications.reset_sent'));

    Notification::assertSentToTimes($active, ResetPasswordNotification::class, 1);
    Notification::assertNotSentTo($off, ResetPasswordNotification::class);
});

it('keeps create, edit and delete out of the tab: its actions are deactivate, reactivate and the reset', function (): void {
    $this->actingAs(Canary::admin());
    Canary::partnerFor($this->client->id);
    $table = resetTab($this->client)->instance()->getTable();

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(array_keys($table->getFlatRecordActions()))->toBe(['deactivate', 'reactivate', 'sendPasswordReset']);
});

it('answers the public request page the same for an unknown e-mail, a deactivated Partner and an active Partner (T-04-47)', function (): void {
    Notification::fake();
    $active = Canary::partnerFor($this->client->id);
    $off = Canary::partnerFor($this->client->id);
    $off->forceFill(['deactivated_at' => now()])->save();
    $archivedClient = Client::factory()->create();
    $archived = Canary::partnerFor($archivedClient->id);
    $archivedClient->delete();

    foreach ([exampleEmail(), $off->email, $archived->email, $active->email] as $email) {
        resetRequestPage()
            ->fillForm(['email' => $email])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertNotified(__('kokpit.password_reset.sent'))
            ->assertSchemaStateSet(['email' => null]);
    }

    Notification::assertNotSentTo($off, ResetPasswordNotification::class);
    Notification::assertNotSentTo($archived, ResetPasswordNotification::class);
    Notification::assertSentToTimes($active, ResetPasswordNotification::class, 1);
});

it('keeps the generic answer when a reset was requested a moment ago and mails only once', function (): void {
    Notification::fake();
    $partner = Canary::partnerFor($this->client->id);

    foreach ([1, 2] as $attempt) {
        resetRequestPage()
            ->fillForm(['email' => $partner->email])
            ->call('request')
            ->assertNotified(__('kokpit.password_reset.sent'));
    }

    Notification::assertSentToTimes($partner, ResetPasswordNotification::class, 1);
});

it('registers the public request page and the signed reset page of the panel', function (): void {
    expect(Route::has('filament.admin.auth.password-reset.request'))->toBeTrue()
        ->and(Route::has('filament.admin.auth.password-reset.reset'))->toBeTrue();

    auth()->forgetGuards();
    $this->get(route('filament.admin.auth.password-reset.request'))->assertOk();
});
