<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

/**
 * The state of a payment confirmation e-mail (D-05). `Sending` is the claim state of the sending job; `Skipped` marks
 * a payment whose client has no address to mail. Labels come with the screens that show the log.
 */
enum PaymentConfirmationState: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
