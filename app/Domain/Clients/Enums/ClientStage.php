<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The relationship stage of a client (D-10). It is a list filter and a label
 * only: it changes no permission and no billing value.
 *
 * The values equal the `clients_stage_check` constraint of the clients table.
 */
enum ClientStage: string implements HasColor, HasLabel
{
    case Lead = 'lead';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    public function getLabel(): string
    {
        return __('enums.client_stage.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Lead => 'info',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Ended => 'gray',
        };
    }
}
