<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationPreferences;
use App\Filament\Auth\EditProfile;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Per-user notification switches on the profile page and the bell for both roles
 * (TA-07, D-15, D-07). Every name is fictional.
 */

/**
 * The stored column as the database holds it, decoded.
 *
 * @return array<string, mixed>
 */
function notificationPrefsStored(User $user): array
{
    $raw = DB::table('users')->where('id', $user->id)->value('notification_preferences');

    return json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partnerA = Canary::partnerFor($this->clientA);
    $this->partnerB = Canary::partnerFor($this->clientB);
});

it('shows a Partner the three Partner rows with both switches on', function (): void {
    $this->actingAs($this->partnerA);

    Livewire::test(EditProfile::class)
        ->assertSee(__('kokpit.notifications.profile.heading'))
        ->assertSee(NotificationEvent::Comment->getLabel())
        ->assertSee(NotificationEvent::Escalation->getLabel())
        ->assertSee(NotificationEvent::AssignmentChange->getLabel())
        ->assertDontSee(NotificationEvent::TaskCreated->getLabel())
        ->assertFormSet([
            'notifications.comment.mail' => true,
            'notifications.comment.database' => true,
            'notifications.escalation.mail' => true,
            'notifications.escalation.database' => true,
            'notifications.assignment_change.mail' => true,
            'notifications.assignment_change.database' => true,
        ]);
});

it('shows the Admin the task created, comment and escalation rows', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(EditProfile::class)
        ->assertSee(NotificationEvent::TaskCreated->getLabel())
        ->assertSee(NotificationEvent::Comment->getLabel())
        ->assertSee(NotificationEvent::Escalation->getLabel())
        ->assertDontSee(NotificationEvent::AssignmentChange->getLabel())
        ->assertFormSet([
            'notifications.task_created.mail' => true,
            'notifications.task_created.database' => true,
        ]);
});

it('stores a Partner switching e-mail off for comments and reads it back as a narrowed channel', function (): void {
    $this->actingAs($this->partnerA);

    Livewire::test(EditProfile::class)
        ->fillForm(['notifications.comment.mail' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = notificationPrefsStored($this->partnerA);
    $preferences = NotificationPreferences::for($this->partnerA->refresh());

    expect($stored['comment'])->toBe(['mail' => false, 'database' => true])
        ->and($preferences->allows(NotificationEvent::Comment, NotificationChannel::Mail))->toBeFalse()
        ->and($preferences->allows(NotificationEvent::Comment, NotificationChannel::Database))->toBeTrue()
        ->and($preferences->allows(NotificationEvent::Escalation, NotificationChannel::Mail))->toBeTrue();
});

it('leaves the other account untouched when a Partner saves the switches', function (): void {
    $this->actingAs($this->partnerA);

    Livewire::test(EditProfile::class)
        ->fillForm(['notifications.comment.mail' => false])
        ->call('save');

    expect(notificationPrefsStored($this->partnerB))->toBe([])
        ->and(notificationPrefsStored($this->admin))->toBe([]);
});

it('labels the profile save button for the notification settings, not with the generic Uložit', function (): void {
    $this->actingAs($this->partnerA);

    expect(__('kokpit.notifications.profile.save'))->toBe('Uložit nastavení');

    Livewire::test(EditProfile::class)
        ->assertSee(__('kokpit.notifications.profile.save'));
});

it('turns the bell on for a Partner as well as the Admin', function (): void {
    $panel = Filament::getPanel('admin');

    $this->actingAs($this->partnerA);
    expect($panel->hasDatabaseNotifications())->toBeTrue();

    $this->actingAs($this->admin);
    expect($panel->hasDatabaseNotifications())->toBeTrue();

    auth()->logout();
    expect($panel->hasDatabaseNotifications())->toBeFalse();
});

it('serves the profile route with the notifications section to a signed-in Partner', function (): void {
    $this->actingAs($this->partnerA)
        ->get('/admin/profile')
        ->assertOk()
        ->assertSee(__('kokpit.notifications.profile.heading'))
        ->assertSee(__('kokpit.notifications.profile.save'));
});
