<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Notifications\UpdateNotificationPreferences;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Filament\Auth\EditProfile;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

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

/**
 * A bell row for the user in the format Filament lists (the shape of OperationalAlert::toDatabase).
 */
function notificationPrefsBellRow(User $user, string $title): void
{
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => OperationalAlert::class,
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
        'data' => json_encode((new OperationalAlert($title, 'Fictional body'))->toDatabase($user), JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
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

it('allows every event on every channel when the column is empty', function (): void {
    $preferences = NotificationPreferences::for($this->partnerA->refresh());

    foreach (NotificationEvent::cases() as $event) {
        foreach (NotificationChannel::cases() as $channel) {
            expect($preferences->allows($event, $channel))->toBeTrue();
        }
    }
});

it('ignores unknown events, unknown channels and values that are not booleans', function (): void {
    $preferences = NotificationPreferences::fromInput([
        'comment' => ['mail' => false, 'database' => 'no', 'sms' => false],
        'escalation' => ['mail' => 0, 'database' => null],
        'telegram' => ['mail' => false],
        'task_created' => 'off',
    ]);

    expect($preferences->toArray())->toBe(['comment' => ['mail' => false]])
        ->and($preferences->allows(NotificationEvent::Comment, NotificationChannel::Database))->toBeTrue()
        ->and($preferences->allows(NotificationEvent::Escalation, NotificationChannel::Mail))->toBeTrue()
        ->and($preferences->allows(NotificationEvent::TaskCreated, NotificationChannel::Mail))->toBeTrue();
});

it('reads a column that holds a value of the wrong shape as no preference at all', function (): void {
    $user = $this->partnerA;
    $user->setRawAttributes([...$user->getAttributes(), 'notification_preferences' => '"x"']);

    $preferences = NotificationPreferences::for($user);

    expect($preferences->toArray())->toBe([])
        ->and($preferences->allows(NotificationEvent::Comment, NotificationChannel::Mail))->toBeTrue();
});

it('opens the profile of a Partner whose column holds a key for an event the role cannot receive', function (): void {
    app(UpdateNotificationPreferences::class)->handle($this->partnerA, $this->partnerA, [
        'task_created' => ['mail' => false, 'database' => false],
        'comment' => ['mail' => false],
    ]);
    $this->actingAs($this->partnerA->refresh());

    Livewire::test(EditProfile::class)
        ->assertFormSet(['notifications.comment.mail' => false, 'notifications.comment.database' => true])
        ->assertDontSee(NotificationEvent::TaskCreated->getLabel());
});

it('does not take the column through mass assignment', function (): void {
    expect(fn () => $this->partnerA->update(['notification_preferences' => ['comment' => ['mail' => false]]]))
        ->toThrow(MassAssignmentException::class);

    expect(notificationPrefsStored($this->partnerA))->toBe([]);
});

it('refuses to change the preferences of another user and changes nothing', function (): void {
    expect(fn () => app(UpdateNotificationPreferences::class)->handle($this->partnerA, $this->partnerB, [
        'comment' => ['mail' => false, 'database' => false],
    ]))->toThrow(AuthorizationException::class);

    expect(notificationPrefsStored($this->partnerB))->toBe([]);
});

it('refuses the Admin changing the preferences of a Partner', function (): void {
    expect(fn () => app(UpdateNotificationPreferences::class)->handle($this->admin, $this->partnerA, [
        'comment' => ['mail' => false],
    ]))->toThrow(AuthorizationException::class);

    expect(notificationPrefsStored($this->partnerA))->toBe([]);
});

it('stores the switches of the owner through the Action', function (): void {
    app(UpdateNotificationPreferences::class)->handle($this->partnerA, $this->partnerA, [
        'comment' => ['mail' => false, 'database' => true],
        'sms' => ['mail' => false],
    ]);

    expect(notificationPrefsStored($this->partnerA))->toBe(['comment' => ['mail' => false, 'database' => true]]);
});

it('refuses a JSON array or a string in the column with a check violation', function (): void {
    RawSql::expectSqlState('23514', fn () => DB::statement("UPDATE users SET notification_preferences = '[]'::jsonb WHERE id = ?", [$this->partnerA->id]));
    RawSql::expectSqlState('23514', fn () => DB::statement("UPDATE users SET notification_preferences = '\"off\"'::jsonb WHERE id = ?", [$this->partnerA->id]));
    RawSql::expectAllowed(fn () => DB::statement("UPDATE users SET notification_preferences = '{}'::jsonb WHERE id = ?", [$this->partnerA->id]));
});

it('keeps the stored switches when the name is saved and the name when the switches are saved', function (): void {
    $this->actingAs($this->partnerA);
    $newName = Canary::canary('name');

    Livewire::test(EditProfile::class)
        ->fillForm(['notifications.escalation.database' => false])
        ->call('save');

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => $newName])
        ->call('save')
        ->assertHasNoFormErrors();

    $partner = $this->partnerA->refresh();

    expect($partner->name)->toBe($newName)
        ->and(notificationPrefsStored($partner)['escalation'])->toBe(['mail' => true, 'database' => false]);

    Livewire::test(EditProfile::class)
        ->fillForm(['notifications.comment.mail' => false])
        ->call('save');

    expect($partner->refresh()->name)->toBe($newName);
});

it('lists in the bell of each user only the own notifications', function (): void {
    $adminTitle = Canary::canary('admin_alert');
    $partnerTitle = Canary::canary('partner_alert');
    $otherTitle = Canary::canary('other_alert');
    notificationPrefsBellRow($this->admin, $adminTitle);
    notificationPrefsBellRow($this->partnerA, $partnerTitle);
    notificationPrefsBellRow($this->partnerB, $otherTitle);

    $this->actingAs($this->partnerA);
    Livewire::test(DatabaseNotifications::class)
        ->assertSee($partnerTitle)
        ->assertDontSee($adminTitle)
        ->assertDontSee($otherTitle);

    $this->actingAs($this->admin);
    Livewire::test(DatabaseNotifications::class)
        ->assertSee($adminTitle)
        ->assertDontSee($partnerTitle)
        ->assertDontSee($otherTitle);
});

it('does not let a Partner clear or read the notification of another user', function (): void {
    notificationPrefsBellRow($this->admin, Canary::canary('admin_alert'));
    $adminRowId = (string) DB::table('notifications')->where('notifiable_id', $this->admin->id)->value('id');

    $this->actingAs($this->partnerA);
    Livewire::test(DatabaseNotifications::class)
        ->call('removeNotification', $adminRowId)
        ->call('markNotificationAsRead', $adminRowId);

    $row = DB::table('notifications')->where('id', $adminRowId)->first();

    expect($row)->not->toBeNull()
        ->and($row->read_at)->toBeNull();
});
