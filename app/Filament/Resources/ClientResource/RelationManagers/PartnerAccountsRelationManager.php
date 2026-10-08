<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Domain\Clients\Actions\DeactivatePartnerAccount;
use App\Domain\Clients\Actions\ReactivatePartnerAccount;
use App\Domain\Clients\Actions\SendPartnerPasswordReset;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Účty" tab of the client detail (US-02, D-04): the Partner accounts of a
 * client, which the Admin switches off and on. Admin only (D-06).
 *
 * The tab has no create, edit, delete, attach or detach: accounts come from the
 * invitation flow and are never removed, deactivation is the only way to take
 * access away. Besides deactivate and reactivate the Admin can send a password
 * reset link to an active account. There is no global user screen either; the one Admin account is
 * managed by the install and reset commands.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Partner accounts decide who may sign in for a client; a Partner never sees or changes them.')]
final class PartnerAccountsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'users';

    /**
     * The tab is on the read-only detail page, but the Admin deactivates and
     * reactivates there, so Filament's read-only default for view pages does not apply.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.partner_accounts.relation_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('kokpit.partner_accounts.fields.name')),
                TextColumn::make('email')
                    ->label(__('kokpit.partner_accounts.fields.email')),
                TextColumn::make('state')
                    ->label(__('kokpit.partner_accounts.fields.state'))
                    ->badge()
                    ->state(static fn (User $record): string => $record->deactivated_at === null
                        ? __('kokpit.partner_accounts.states.active')
                        : __('kokpit.partner_accounts.states.deactivated'))
                    ->color(static fn (User $record): string => $record->deactivated_at === null ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('kokpit.partner_accounts.fields.created_at'))
                    ->dateTime(),
            ])
            ->defaultSort(static fn ($query) => $query->orderBy('name')->orderBy('id'))
            ->recordActions([
                Action::make('deactivate')
                    ->label(__('kokpit.partner_accounts.actions.deactivate'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('kokpit.partner_accounts.actions.deactivate_heading'))
                    ->modalDescription(__('kokpit.partner_accounts.actions.deactivate_description'))
                    ->hidden(static fn (Model $record): bool => ! $record instanceof User || $record->deactivated_at !== null)
                    ->action(function (Model $record): void {
                        assert($record instanceof User);

                        $this->run(
                            static fn () => app(DeactivatePartnerAccount::class)->handle($record),
                            __('kokpit.partner_accounts.notifications.deactivated'),
                        );
                    }),
                Action::make('reactivate')
                    ->label(__('kokpit.partner_accounts.actions.reactivate'))
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->hidden(static fn (Model $record): bool => ! $record instanceof User || $record->deactivated_at === null)
                    ->action(function (Model $record): void {
                        assert($record instanceof User);

                        $this->run(
                            static fn () => app(ReactivatePartnerAccount::class)->handle($record),
                            __('kokpit.partner_accounts.notifications.reactivated'),
                        );
                    }),
                Action::make('sendPasswordReset')
                    ->label(__('kokpit.partner_accounts.actions.send_password_reset'))
                    ->icon(Heroicon::OutlinedKey)
                    ->requiresConfirmation()
                    ->modalHeading(__('kokpit.partner_accounts.actions.send_password_reset_heading'))
                    ->modalDescription(__('kokpit.partner_accounts.actions.send_password_reset_description'))
                    ->hidden(static fn (Model $record): bool => ! $record instanceof User || $record->deactivated_at !== null)
                    ->action(function (Model $record): void {
                        assert($record instanceof User);

                        $this->run(
                            static fn () => app(SendPartnerPasswordReset::class)->handle($record),
                            __('kokpit.partner_accounts.notifications.reset_sent'),
                        );
                    }),
            ])
            ->emptyStateHeading(__('kokpit.partner_accounts.empty_heading'))
            ->emptyStateDescription(__('kokpit.partner_accounts.empty_description'));
    }

    /**
     * Runs one lifecycle Action and reports the outcome; a refusal of the Action
     * becomes a notification with its reason.
     *
     * @param  callable(): mixed  $step
     */
    private function run(callable $step, string $successTitle): void
    {
        try {
            $step();
        } catch (DomainException $e) {
            Notification::make()
                ->danger()
                ->title(__('kokpit.partner_accounts.notifications.failed'))
                ->body($e->getMessage())
                ->send();

            return;
        }

        Notification::make()->success()->title($successTitle)->send();
    }
}
