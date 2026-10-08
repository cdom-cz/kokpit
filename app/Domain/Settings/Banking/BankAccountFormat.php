<?php

declare(strict_types=1);

namespace App\Domain\Settings\Banking;

use Filament\Support\Contracts\HasLabel;

/**
 * The format of a bank account, which decides the fields it carries (D-03):
 * Europe 1 is a domestic account number with a bank code, Europe 2 is an IBAN
 * only, World is an account number with a recipient and a bank.
 */
enum BankAccountFormat: string implements HasLabel
{
    case Europe1 = 'europe_1';
    case Europe2 = 'europe_2';
    case World = 'world';

    /**
     * The format-dependent fields this format shows, as stored keys. The label,
     * the currency and the BIC/SWIFT belong to every format.
     *
     * @return list<string>
     */
    public function visibleFields(): array
    {
        return match ($this) {
            self::Europe1 => ['account_number', 'bank_code', 'bank_name', 'iban'],
            self::Europe2 => ['iban'],
            self::World => ['account_number', 'recipient_name', 'bank_name', 'bank_address'],
        };
    }

    public function getLabel(): string
    {
        return __('enums.bank_account_format.'.$this->value);
    }
}
