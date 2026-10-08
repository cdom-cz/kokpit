<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Clients\Actions\AcceptInvitation as AcceptInvitationAction;
use App\Domain\Clients\InvitationNotAcceptable;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The page an invitation link opens (US-02, D-01, D-02).
 *
 * A guest page: it is reached only through its own signed route
 * (`filament.admin.invitation.accept`, registered by the panel provider), it is
 * not registered in the panel and uses no access trait, because
 * Audience::Guest is always denied by AccessRules.
 *
 * The invitation is read on load only through ClientInvitation::findAcceptable(),
 * the one sanctioned system run of a guest request. Whatever is wrong with the link
 * (an unknown, malformed, tampered, expired, revoked or accepted invitation) the
 * page shows the same neutral message, so it is neither an account nor an
 * invitation oracle. On submit the page hands name and password to the
 * AcceptInvitation Action, which re-checks everything under a lock; a failure of
 * the invitation side there switches the page to the same neutral state. The page
 * never creates a user itself. Filament's reset flow does not sign in after the
 * password is set, and neither does this page: it redirects to the login page.
 *
 * @property-read Schema $form
 */
#[AccessRule(Audience::Guest, reason: 'Opened only through its own signed invitation route; a guest page is never granted through the panel access checks.')]
final class AcceptInvitation extends SimplePage
{
    #[Locked]
    public ?string $invitationId = null;

    #[Locked]
    public ?string $token = null;

    #[Locked]
    public ?string $inviteeEmail = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $id = request()->query('invitation');
        $token = request()->query('token');

        $invitation = is_string($id) && is_string($token) ? ClientInvitation::findAcceptable($id, $token) : null;

        if ($invitation === null) {
            return;
        }

        $this->invitationId = $invitation->id;
        $this->token = $token;
        $this->inviteeEmail = $invitation->email;

        $this->form->fill(['name' => $invitation->name]);
    }

    public function accept(): void
    {
        if ($this->invitationId === null || $this->token === null) {
            return;
        }

        $data = $this->form->getState();

        try {
            app(AcceptInvitationAction::class)->handle(
                $this->invitationId,
                $this->token,
                (string) $data['name'],
                (string) $data['password'],
            );
        } catch (InvitationNotAcceptable) {
            $this->switchToNeutralState();

            return;
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(
                    static fn (array $messages, string $key): array => ["data.{$key}" => $messages],
                )->all(),
            );
        }

        Notification::make()
            ->title(__('kokpit.invitations.accept.done'))
            ->success()
            ->send();

        $this->redirect(Filament::getLoginUrl());
    }

    /**
     * The same state the page has for a link that was not acceptable on load.
     */
    private function switchToNeutralState(): void
    {
        $this->invitationId = null;
        $this->token = null;
        $this->inviteeEmail = null;
        $this->data = [];
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('kokpit.invitations.accept.fields.name'))
                ->required()
                ->maxLength(255)
                ->autofocus(),
            TextInput::make('password')
                ->label(__('kokpit.invitations.accept.fields.password'))
                ->password()
                ->revealable()
                ->required()
                ->same('passwordConfirmation')
                ->validationAttribute(__('kokpit.invitations.accept.attributes.password')),
            TextInput::make('passwordConfirmation')
                ->label(__('kokpit.invitations.accept.fields.password_confirmation'))
                ->password()
                ->revealable()
                ->required()
                ->dehydrated(false),
        ]);
    }

    public function getTitle(): string
    {
        return __('kokpit.invitations.accept.title');
    }

    public function getHeading(): string
    {
        return $this->inviteeEmail === null
            ? __('kokpit.invitations.accept.invalid_heading')
            : __('kokpit.invitations.accept.heading');
    }

    public function content(Schema $schema): Schema
    {
        if ($this->inviteeEmail === null) {
            return $schema->components([
                Text::make(__('kokpit.invitations.accept.invalid_message')),
            ]);
        }

        return $schema->components([
            Text::make(__('kokpit.invitations.accept.intro')),
            Text::make($this->inviteeEmail)->weight(FontWeight::Bold),
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('accept')
            ->footer([
                Actions::make([
                    Action::make('accept')
                        ->label(__('kokpit.invitations.accept.submit'))
                        ->submit('accept'),
                ])
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth()
                    ->key('form-actions'),
            ]);
    }
}
