<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The state of a queued document e-mail (D-22). `Sending` is the claim state of the sending job;
 * screens show it like `Queued`. `Skipped` is the end state of a row whose client switched document e-mails off
 * (Phase 16, D-07): never sent and never failed.
 */
enum InvoiceEmailState: string implements HasLabel
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return __('enums.invoice_email_state.'.$this->value);
    }
}
