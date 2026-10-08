<?php

declare(strict_types=1);

namespace App\Domain\Clients;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Shared\Auth\PartnerContext;
use App\Providers\LocalisationServiceProvider;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Mails the invitation link (US-02, D-01, D-02).
 *
 * The only place where the plain token leaves the Actions: it is put into a
 * temporary signed URL that expires together with the invitation, and the URL
 * travels to the invitee in a queued notification built from scalars. The
 * signature protects the query string; the token inside it is checked against the
 * stored hash when the link is opened, so a resend that rotates the hash
 * invalidates an older link whose signature is still valid.
 */
final class InvitationMail
{
    public const string ACCEPT_ROUTE = 'filament.admin.invitation.accept';

    public function send(ClientInvitation $invitation, string $plainToken): void
    {
        $acceptUrl = URL::temporarySignedRoute(self::ACCEPT_ROUTE, $invitation->expires_at, [
            'invitation' => $invitation->getKey(),
            'token' => $plainToken,
        ]);

        // The client may be archived by now and the sender may be a console run: read it as a system run.
        $clientName = app(PartnerContext::class)->runAsSystem(
            static fn (): string => (string) Client::query()->withTrashed()->whereKey($invitation->client_id)->value('name'),
        );

        Notification::route('mail', $invitation->email)->notify(new PartnerInvitation(
            $invitation->name,
            $clientName,
            $acceptUrl,
            $invitation->expires_at->copy()->timezone('Europe/Prague')->format(LocalisationServiceProvider::DATE_TIME_FORMAT),
        ));
    }
}
