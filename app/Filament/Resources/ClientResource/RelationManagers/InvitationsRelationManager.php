<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Domain\Clients\Actions\ResendInvitation;
use App\Domain\Clients\Actions\RevokeInvitation;
use App\Domain\Clients\Enums\InvitationState;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
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
 * The "Pozvánky" tab of the client detail (US-02, D-02): the Partner invitations of
 * a client with their derived state, and the two things the Admin can do with an
 * open one, send it again and cancel it. Admin only (D-06).
 *
 * The tab has no create, edit or delete: an invitation changes only through
 * InvitePartner (the header action of the detail page), ResendInvitation and
 * RevokeInvitation. Both actions are offered for a pending or an expired invitation
 * only, and resend also not for an archived client; the Actions re-check on the
 * locked row, so a stale list cannot do what the state forbids.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Invitations carry the e-mail addresses and the links that create Partner accounts; a Partner never sees them.')]
final class InvitationsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'invitations';

    /**
     * The tab is on the read-only detail page, but the Admin resends and revokes
     * there, so Filament's read-only default for view pages does not apply.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.invitations.admin.relation_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('kokpit.invitations.admin.fields.name')),
                TextColumn::make('email')
                    ->label(__('kokpit.invitations.admin.fields.email')),
                TextColumn::make('state')
                    ->label(__('kokpit.invitations.admin.fields.state'))
                    ->badge()
                    ->state(static fn (ClientInvitation $record): InvitationState => $record->state())
                    ->color(static fn (InvitationState $state): string => match ($state) {
                        InvitationState::Pending => 'warning',
                        InvitationState::Accepted => 'success',
                        InvitationState::Revoked => 'gray',
                        InvitationState::Expired => 'danger',
                    }),
                TextColumn::make('expires_at')
                    ->label(__('kokpit.invitations.admin.fields.expires_at'))
                    ->dateTime(),
                TextColumn::make('send_count')
                    ->label(__('kokpit.invitations.admin.fields.send_count'))
                    ->numeric(),
                TextColumn::make('last_sent_at')
                    ->label(__('kokpit.invitations.admin.fields.last_sent_at'))
                    ->dateTime(),
            ])
            ->defaultSort(static fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->recordActions([
                Action::make('resend')
                    ->label(__('kokpit.invitations.admin.actions.resend'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->requiresConfirmation()
                    ->modalHeading(__('kokpit.invitations.admin.actions.resend_heading'))
                    ->modalDescription(__('kokpit.invitations.admin.actions.resend_description'))
                    ->hidden(fn (Model $record): bool => ! $this->isOpen($record) || $this->clientIsArchived())
                    ->action(function (Model $record): void {
                        assert($record instanceof ClientInvitation);

                        $this->run(
                            static fn () => app(ResendInvitation::class)->handle($record),
                            __('kokpit.invitations.admin.notifications.resent'),
                        );
                    }),
                Action::make('revoke')
                    ->label(__('kokpit.invitations.admin.actions.revoke'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('kokpit.invitations.admin.actions.revoke_heading'))
                    ->modalDescription(__('kokpit.invitations.admin.actions.revoke_description'))
                    ->hidden(fn (Model $record): bool => ! $this->isOpen($record))
                    ->action(function (Model $record): void {
                        assert($record instanceof ClientInvitation);

                        $this->run(
                            static fn () => app(RevokeInvitation::class)->handle($record),
                            __('kokpit.invitations.admin.notifications.revoked'),
                        );
                    }),
            ])
            ->emptyStateHeading(__('kokpit.invitations.admin.empty_heading'))
            ->emptyStateDescription(__('kokpit.invitations.admin.empty_description'));
    }

    /**
     * Pending or expired: the only states that can be resent or revoked.
     */
    private function isOpen(Model $record): bool
    {
        return $record instanceof ClientInvitation
            && in_array($record->state(), [InvitationState::Pending, InvitationState::Expired], true);
    }

    private function clientIsArchived(): bool
    {
        $client = $this->getOwnerRecord();

        return $client instanceof Client && $client->trashed();
    }

    /**
     * Runs one lifecycle Action and reports the outcome; a refusal of the Action
     * (the row changed meanwhile) becomes a notification with its reason.
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
                ->title(__('kokpit.invitations.admin.notifications.failed'))
                ->body($e->getMessage())
                ->send();

            return;
        }

        Notification::make()->success()->title($successTitle)->send();
    }
}
