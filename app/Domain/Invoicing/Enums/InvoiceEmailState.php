<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The state of a queued document e-mail (D-22). `Sending` is the claim state of the sending job;
 * screens show it like `Queued`.
 */
enum InvoiceEmailState: string implements HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('enums.invoice_email_state.'.$this->value);
    }
}
