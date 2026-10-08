<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The outcome of one health indicator (D-12).
 *
 * A measurement that could not be taken is NotAvailable or Error, never Ok.
 */
enum HealthStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case NotAvailable = 'not_available';

    public function getLabel(): string
    {
        return __('enums.health_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
            self::NotAvailable => 'gray',
        };
    }
}
