<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The state of a payment confirmation e-mail (D-05). `Sending` is the claim state of the sending job; `Skipped` marks
 * a payment whose client has no address to mail. The Platby tab shows the label (R-13-08-2); `Queued` and `Sending`
 * read alike, as in {@see InvoiceEmailState}.
 */
enum PaymentConfirmationState: string implements HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return __('enums.payment_confirmation_state.'.$this->value);
    }
}
