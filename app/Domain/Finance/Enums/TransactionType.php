<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The direction of money: an income or an expense (D-15).
 *
 * The values equal the `transaction_categories_type_check` constraint of the categories table; a category
 * has one type and a transaction takes only a category of its own type.
 */
enum TransactionType: string implements HasColor, HasLabel
{
    case Income = 'income';
    case Expense = 'expense';

    public function getLabel(): string
    {
        return __('enums.transaction_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'danger',
        };
    }
}
