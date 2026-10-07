<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Filament\Support\Contracts\HasLabel;

/**
 * An enum whose labels come from keys that exist in the generated Czech pack
 * (pagination). It is the passing counterpart of UntranslatedFixtureEnum.
 */
enum TranslatedFixtureEnum: string implements HasLabel
{
    case Next = 'next';
    case Previous = 'previous';

    public function getLabel(): string
    {
        return __('pagination.'.$this->value);
    }
}
