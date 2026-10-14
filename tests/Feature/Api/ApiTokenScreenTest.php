<?php

declare(strict_types=1);

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Filament\Resources\ApiTokenResource\Pages\ListApiTokens;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
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
