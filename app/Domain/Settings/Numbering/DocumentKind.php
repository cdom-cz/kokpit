<?php

declare(strict_types=1);

namespace App\Domain\Settings\Numbering;

use Filament\Support\Contracts\HasLabel;

/**
 * The numbered things and their counter series (D-05).
 *
 * Invoices, proformas and credit notes use a configurable token pattern with
 * one series each. A task number is fixed to KEY-N and counted per project.
 */
enum DocumentKind: string implements HasLabel
{
    case Invoice = 'invoice';
    case Proforma = 'proforma';
    case CreditNote = 'credit_note';
    case Task = 'task';

    /**
     * The kind part of the allocator scope key; the enum value fits the
     * allocator's kind pattern.
     */
    public function sequenceKind(): string
    {
        return $this->value;
    }

    /**
     * The default pattern. A proforma gets a nine-digit number, so its variable
     * symbol can never equal the eight-digit invoice number of the default
     * pattern (research Pitfall 13).
     */
    public function defaultPattern(): string
    {
        return match ($this) {
            self::Task => '{KEY}-{N}',
            self::Proforma => '{YYYY}9{NNNN}',
            default => '{YYYY}{NNNN}',
        };
    }

    /**
     * Whether the document number is printed as the variable symbol of the
     * SPAYD payment QR. SPAYD allows only 1 to 10 digits there, so the pattern
     * of such a kind is digits only and at most 10 characters. A credit note
     * carries neither a variable symbol nor a QR.
     */
    public function carriesVariableSymbol(): bool
    {
        return $this === self::Invoice || $this === self::Proforma;
    }

    public function getLabel(): string
    {
        return __('enums.document_kind.'.$this->value);
    }
}
