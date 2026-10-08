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

    public function defaultPattern(): string
    {
        return match ($this) {
            self::Task => '{KEY}-{N}',
            default => '{YYYY}{NNNN}',
        };
    }

    public function getLabel(): string
    {
        return __('enums.document_kind.'.$this->value);
    }
}
