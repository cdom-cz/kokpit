<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Livewire\Attributes\Locked;

/**
 * The page an invitation link opens (US-02, D-01, D-02).
 *
 * A guest page: it is reached only through its own signed route
 * (`filament.admin.invitation.accept`, registered by the panel provider), it is
 * not registered in the panel and uses no access trait, because
 * Audience::Guest is always denied by AccessRules.
 *
 * The invitation is read only through ClientInvitation::findAcceptable(), the one
 * sanctioned system run of a guest request. Whatever is wrong with the link (an
 * unknown, malformed, tampered, expired, revoked or accepted invitation) the page
 * shows the same neutral message, so it is neither an account nor an invitation
 * oracle. The password form is added by the plan that creates the account.
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
        ]);
    }
}
