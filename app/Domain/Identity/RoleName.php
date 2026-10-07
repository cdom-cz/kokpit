<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Filament\Support\Contracts\HasLabel;

/**
 * The two roles of the panel: the single Admin and the client accounts.
 *
 * The backed value is the role name stored by the permission package.
 */
enum RoleName: string implements HasLabel
{
    case Admin = 'admin';
    case Partner = 'partner';

    public function getLabel(): string
    {
        return __('enums.role_name.'.$this->value);
    }
}
