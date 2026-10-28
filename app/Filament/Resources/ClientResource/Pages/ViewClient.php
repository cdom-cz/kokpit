<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Domain\Clients\Actions\InvitePartner;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Rules\EmailHasNoAccount;
use App\Domain\Clients\Rules\EmailHasNoOpenInvitation;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Widgets\ClientOverviewStats;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * The client detail page: billing data, terms and tags read-only, with the
 * invite, archive and restore actions. Contacts, invitations and Partner accounts
 * attach here as relation managers.
 */
final class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up, like on the
     * edit page: the deny-all scope of the client would answer 404 first.
     */
    public function mount(int|string $record): void
    {
        abort_unless(ClientResource::canAccess(), 403);

        parent::mount($record);
    }

    /**
     * The client name, with a warning badge when the client's documents are not e-mailed (Phase 16, D-05).
     * With the switch on the heading is the usual one.
     */
    public function getHeading(): string|Htmlable|null
    {
        $heading = parent::getHeading();

        if ($this->clientRecord()->document_emails_enabled) {
            return $heading;
        }

        $text = $heading instanceof Htmlable ? $heading->toHtml() : e((string) $heading);

        $badge = Blade::render(
            '<x-filament::badge color="warning" style="vertical-align: middle;">{{ $label }}</x-filament::badge>',
            ['label' => __('kokpit.clients.badges.document_emails_off')],
        );

        return new HtmlString($text.' '.$badge);
    }

    /**
     * The stats row "Přehled klienta" above the details: unbilled time, what is owed and what is overdue.
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [ClientOverviewStats::class];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->invitePartnerAction(),
            EditAction::make(),
            ClientResource::archiveAction(DeleteAction::make()),
            ClientResource::restoreAction(RestoreAction::make()),
        ];
    }

    /**
     * "Pozvat partnera" (US-02, D-01, D-03): name and e-mail of the person, optionally
     * filled from one of the client's contacts. The e-mail field carries the same rules
     * as the Action, so a duplicate shows on the field before anything runs; the Action
     * validates again, so a race is caught too. An archived client cannot be invited for.
     */
    private function invitePartnerAction(): Action
    {
        return Action::make('invitePartner')
            ->label(__('kokpit.invitations.admin.actions.invite'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading(__('kokpit.invitations.admin.actions.invite_heading'))
            ->modalDescription(__('kokpit.invitations.admin.actions.invite_description'))
            ->modalSubmitActionLabel(__('kokpit.invitations.admin.actions.invite_submit'))
            ->hidden(fn (): bool => $this->clientRecord()->trashed())
            ->schema([
                Select::make('contact_id')
                    ->label(__('kokpit.invitations.admin.fields.contact_id'))
                    ->helperText(__('kokpit.invitations.admin.fields.contact_hint'))
                    ->options(fn (): array => $this->clientRecord()->contacts()->orderBy('name')->orderBy('id')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    // Only a helper to fill the two fields below; it is never part of the submitted data.
                    ->dehydrated(false)
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $contact = is_string($state) ? $this->clientRecord()->contacts()->whereKey($state)->first() : null;

                        if ($contact === null) {
                            return;
                        }

                        $set('name', $contact->name);
                        $set('email', $contact->email);
                    }),
                TextInput::make('name')
                    ->label(__('kokpit.invitations.admin.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('kokpit.invitations.admin.fields.email'))
                    ->required()
                    ->email()
                    ->maxLength(255)
                    ->rules([new EmailHasNoAccount, new EmailHasNoOpenInvitation]),
            ])
            ->action(function (array $data): void {
                abort_unless(ClientResource::canAccess(), 403);

                try {
                    app(InvitePartner::class)->handle(
                        $this->clientRecord(),
                        (string) $data['name'],
                        (string) $data['email'],
                        auth()->user(),
                    );
                } catch (ValidationException $e) {
                    throw $this->underModal($e);
                }

                Notification::make()
                    ->success()
                    ->title(__('kokpit.invitations.admin.notifications.sent'))
                    ->send();
            });
    }

    private function clientRecord(): Client
    {
        $record = $this->getRecord();
        assert($record instanceof Client);

        return $record;
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path.
     * An error that belongs to no field (an archived client) lands on the e-mail field.
     */
    private function underModal(ValidationException $e): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            $field = in_array($key, ['name', 'email'], true) ? $key : 'email';
            $path = "mountedActions.0.data.{$field}";
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
