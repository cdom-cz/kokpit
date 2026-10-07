<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Filament\Support\Contracts\HasLabel;

/**
 * An enum whose label keys exist in no language file. EnumLabelChecker must
 * report it; without it the enum label test could pass without checking anything.
 */
enum UntranslatedFixtureEnum: string implements HasLabel
{
    case Alpha = 'alpha';
    case Beta = 'beta';

    public function getLabel(): string
    {
        return __('enums.fixture.untranslated.'.$this->value);
    }
}
