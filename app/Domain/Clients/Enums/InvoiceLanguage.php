<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The language of the invoices issued to a client (D-09): Czech or English.
 *
 * The values equal the `clients_invoice_language_check` constraint of the clients table.
 */
enum InvoiceLanguage: string implements HasLabel
{
    case Czech = 'cs';
    case English = 'en';

    public function getLabel(): string
    {
        return __('enums.invoice_language.'.$this->value);
    }
}
