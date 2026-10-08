<?php

declare(strict_types=1);

namespace App\Domain\Clients;

use RuntimeException;

/**
 * The one failure of the accept flow that concerns the invitation itself (US-02,
 * D-01). Whatever is wrong at submit time (the link is no longer valid, the
 * invitation was accepted, revoked, expired or resent meanwhile, the client was
 * archived, the e-mail got an account meanwhile), the caller sees this one
 * exception and the guest page shows one neutral message. The message carries no
 * detail on purpose, so neither a log line nor a response can tell the cases
 * apart: the flow must not be an account or an invitation oracle.
 */
final class InvitationNotAcceptable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The invitation cannot be accepted.');
    }
}
