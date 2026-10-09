<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Notifications\UpdateNotificationPreferences;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * The profile page of every signed-in account, Admin and Partner alike (TA-07, D-15).
 *
 * Filament's own page edits the name, e-mail and password and hosts the two-factor
 * section. This subclass adds the "Upozornění" section: one row for each event the
 * role can receive and a switch for e-mail and for the bell, all on until the user
 * switches one off. The page edits only the signed-in account, so no other user's
 * preferences are reachable from it.
 *
 * The class lives outside app/Filament/Pages, whose classes the panel discovers and
 * would register a second time as a normal page.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'Every signed-in account, Admin or Partner, edits its own profile and notification switches; the page only ever touches the signed-in user.')]
final class EditProfile extends BaseEditProfile
{
    use EnforcesPageAccessRule;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                $this->notificationsSection(),
            ]);
    }

    private function notificationsSection(): Section
    {
        $rows = [];

        foreach (NotificationEvent::forRole($this->role()) as $event) {
            $rows[] = Fieldset::make($event->getLabel())
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    Text::make($event->helper($this->role()))->color('gray')->columnSpanFull(),
                    ...array_map(
                        static fn (NotificationChannel $channel): Toggle => Toggle::make('notifications.'.$event->value.'.'.$channel->value)
                            ->label($channel->getLabel()),
                        NotificationChannel::cases(),
                    ),
                ]);
        }

        return Section::make(__('kokpit.notifications.profile.heading'))
            ->description(__('kokpit.notifications.profile.description'))
            ->schema($rows);
    }

    /**
     * The role decides which rows exist. A Partner is anyone who is not the Admin.
     */
    private function role(): RoleName
    {
        $user = $this->getUser();

        return $user instanceof User && $user->hasRole(RoleName::Admin->value) ? RoleName::Admin : RoleName::Partner;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getUser();

        // The raw column stays out of the form state: the switches below are its only door.
        unset($data['notification_preferences']);

        if (! $user instanceof User) {
            return $data;
        }

        $preferences = NotificationPreferences::for($user);
        $data['notifications'] = [];

        foreach (NotificationEvent::forRole($this->role()) as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $data['notifications'][$event->value][$channel->value] = $preferences->allows($event, $channel);
            }
        }

        return $data;
    }

    /**
     * The switches go through the Action; everything else is the stock profile save,
     * which would otherwise try to mass-assign them onto the user.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $notifications = $data['notifications'] ?? [];
        unset($data['notifications']);

        $record = parent::handleRecordUpdate($record, $data);

        if ($record instanceof User && is_array($notifications)) {
            app(UpdateNotificationPreferences::class)->handle($record, $record, $notifications);
        }

        return $record;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('kokpit.notifications.profile.save'));
    }
}
