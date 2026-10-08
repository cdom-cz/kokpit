<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\DeactivatePartnerAccount;
use App\Domain\Clients\Actions\ReactivatePartnerAccount;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\UserPolicy;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\PartnerAccountsRelationManager;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Partner account lifecycle in the client detail (US-02, D-04): the "Účty" tab lists
 * the Partner accounts of one client, and the Admin switches them off and on. A
 * deactivated account loses its login, its session and its API tokens; nothing is
 * ever deleted. Livewire tests as the Admin; every name and e-mail is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->client = Client::factory()->create();
});

/**
 * The accounts tab of a client as the Admin sees it on the detail page.
 */
function accountsTab(Client $client): Testable
{
    return Livewire::test(PartnerAccountsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * The stored account again, so a stale instance never hides what the Action wrote.
 */
function accountFresh(User $user): User
{
    return User::query()->findOrFail($user->id);
}

/**
 * The first error shown on the e-mail field after a login attempt.
 */
function accountLoginMessage(string $email, string $password): string
{
    // The login page redirects a signed-in user, so the attempt starts as a guest.
    auth()->forgetGuards();

    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate');

    return (string) collect($component->errors()->get('data.email'))->first();
}

it('lists the Partner accounts of this client only: not another client, not the Admin', function (): void {
    $own = Canary::partnerFor($this->client->id);
    $otherClient = Client::factory()->create();
    $foreign = Canary::partnerFor($otherClient->id);

    accountsTab($this->client)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign, $this->admin]);
});

it('shows name, e-mail and the Czech state of an account', function (): void {
    $active = Canary::partnerFor($this->client->id);
    $off = Canary::partnerFor($this->client->id);
    $off->forceFill(['deactivated_at' => now()])->save();

    accountsTab($this->client)
        ->assertTableColumnStateSet('name', $active->name, $active)
        ->assertTableColumnStateSet('email', $active->email, $active)
        ->assertTableColumnStateSet('state', __('kokpit.partner_accounts.states.active'), $active)
        ->assertTableColumnStateSet('state', __('kokpit.partner_accounts.states.deactivated'), $off);

    expect(__('kokpit.partner_accounts.states.deactivated'))->toBe('Deaktivován');
});

it('deactivates a Partner: deactivated_at is set, both API tokens are gone, the badge changes (D-04)', function (): void {
    $partner = Canary::partnerFor($this->client->id);
    $partner->createToken('first');
    $partner->createToken('second');
    expect($partner->tokens()->count())->toBe(2);

    accountsTab($this->client)
        ->callTableAction('deactivate', $partner)
        ->assertNotified(__('kokpit.partner_accounts.notifications.deactivated'))
        ->assertTableColumnStateSet('state', __('kokpit.partner_accounts.states.deactivated'), $partner);

    expect(accountFresh($partner)->deactivated_at)->not->toBeNull()
        ->and($partner->tokens()->count())->toBe(0);
});

it('turns the next request of an existing session of a deactivated Partner into a 403 and blocks the login with the generic message', function (): void {
    $partner = Canary::partnerFor($this->client->id);

    $this->actingAs($partner)->get('/admin')->assertOk();

    $this->actingAs($this->admin);
    accountsTab($this->client)->callTableAction('deactivate', $partner);

    // Every request loads the user afresh; the instance of the test would still carry the old state.
    $this->actingAs(accountFresh($partner))->get('/admin')->assertForbidden();

    $wrongPassword = accountLoginMessage($partner->email, 'definitely-not-the-password');
    $deactivated = accountLoginMessage($partner->email, 'password');

    expect($wrongPassword)->not->toBe('')
        ->and($deactivated)->toBe($wrongPassword);
    $this->assertGuest();
});

it('reactivates a Partner and the login works again', function (): void {
    $partner = Canary::partnerFor($this->client->id);
    $partner->forceFill(['deactivated_at' => now()])->save();

    accountsTab($this->client)
        ->assertTableActionHidden('deactivate', $partner)
        ->assertTableActionVisible('reactivate', $partner)
        ->callTableAction('reactivate', $partner)
        ->assertNotified(__('kokpit.partner_accounts.notifications.reactivated'))
        ->assertTableActionVisible('deactivate', $partner)
        ->assertTableActionHidden('reactivate', $partner);

    expect(accountFresh($partner)->deactivated_at)->toBeNull();

    auth()->forgetGuards();
    Livewire::test(Login::class)
        ->fillForm(['email' => $partner->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs(accountFresh($partner));
});

it('asks for a confirmation before it deactivates', function (): void {
    $partner = Canary::partnerFor($this->client->id);

    $action = accountsTab($this->client)->instance()->getTable()->getFlatRecordActions()['deactivate'];

    expect($action->isConfirmationRequired())->toBeTrue()
        ->and(accountFresh($partner)->deactivated_at)->toBeNull();
});

it('keeps the first deactivated_at when the account is deactivated twice', function (): void {
    $partner = Canary::partnerFor($this->client->id);
    $deactivate = app(DeactivatePartnerAccount::class);

    $this->travelTo(now()->startOfSecond());
    $deactivate->handle($partner);
    $first = accountFresh($partner)->deactivated_at;

    $this->travel(3)->hours();
    $deactivate->handle(accountFresh($partner));

    expect($first)->not->toBeNull()
        ->and(accountFresh($partner)->deactivated_at?->equalTo($first))->toBeTrue();
});

it('refuses the Actions for the Admin account with a DomainException and changes nothing', function (): void {
    $partner = Canary::partnerFor($this->client->id);
    $partner->createToken('keep');
    $admin = $this->admin;
    $admin->createToken('keep');

    expect(fn () => app(DeactivatePartnerAccount::class)->handle($admin))->toThrow(DomainException::class)
        ->and(fn () => app(ReactivatePartnerAccount::class)->handle($admin))->toThrow(DomainException::class)
        ->and(accountFresh($admin)->deactivated_at)->toBeNull()
        ->and($admin->tokens()->count())->toBe(1);
});

it('refuses the Actions for a user without any role', function (): void {
    $nobody = Canary::userWithoutRole($this->client->id);

    expect(fn () => app(DeactivatePartnerAccount::class)->handle($nobody))->toThrow(DomainException::class)
        ->and(accountFresh($nobody)->deactivated_at)->toBeNull();
});

it('offers deactivate and reactivate in the tab and no create, edit, delete, attach or detach (D-04)', function (): void {
    Canary::partnerFor($this->client->id);
    $table = accountsTab($this->client)->instance()->getTable();

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getToolbarActions())->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(array_keys($table->getFlatRecordActions()))->toBe(['deactivate', 'reactivate']);
});

it('never deletes an account: the policy path stays closed for a Partner and the tab has no delete action', function (): void {
    $partner = Canary::partnerFor($this->client->id);
    $other = Canary::partnerFor($this->client->id);

    foreach (['delete', 'forceDelete', 'update', 'view', 'restore'] as $ability) {
        expect(Gate::forUser($partner)->allows($ability, $other))->toBeFalse($ability);
    }

    accountsTab($this->client)->assertTableActionDoesNotExist('delete');

    expect(User::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('refuses a Partner the accounts tab at boot and lists the tab in the client resource', function (): void {
    $other = Canary::partnerFor($this->client->id);
    $this->actingAs(Canary::partnerFor($this->client->id));

    expect(PartnerAccountsRelationManager::canViewForRecord($this->client, ViewClient::class))->toBeFalse()
        ->and(ClientResource::getRelations())->toContain(PartnerAccountsRelationManager::class);

    accountsTab($this->client)->assertForbidden();
    expect(accountFresh($other)->deactivated_at)->toBeNull();
});

it('registers a UserPolicy that denies a Partner every ability and admits the Admin', function (): void {
    $partner = Canary::partnerFor($this->client->id);
    $other = Canary::partnerFor($this->client->id);

    expect(Gate::getPolicyFor(User::class))->toBeInstanceOf(UserPolicy::class);

    foreach (['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'] as $ability) {
        expect(Gate::forUser($partner)->allows($ability, User::class))->toBeFalse($ability);
    }

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
        expect(Gate::forUser($partner)->allows($ability, $partner))->toBeFalse($ability)
            ->and(Gate::forUser($partner)->allows($ability, $other))->toBeFalse($ability);
    }

    expect(Gate::forUser($this->admin)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $other))->toBeTrue();
});
