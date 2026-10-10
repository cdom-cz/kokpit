<?php

declare(strict_types=1);

namespace App\Domain\Signal\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The priority colour of a planner task: main = red, medium = blue, other = green, extra = grey.
 *
 * Only the first three can be planned; `extra` is assigned automatically to what is added during
 * the day it is for.
 */
enum SignalCategory: string implements HasColor, HasLabel
{
    case Main = 'main';
    case Medium = 'medium';
    case Other = 'other';
    case Extra = 'extra';

    /**
     * The categories a user may pick when planning ahead, in display order.
     *
     * @return list<self>
     */
    public static function plannable(): array
    {
        return [self::Main, self::Medium, self::Other];
    }

    public function isPlannable(): bool
    {
        return $this !== self::Extra;
    }

    /**
     * The per-day limit, or null when the category is unlimited.
     */
    public function dailyLimit(): ?int
    {
        return match ($this) {
            self::Main, self::Medium => 3,
            self::Other, self::Extra => null,
        };
    }

    /**
     * Sort rank within a day: main, medium, other, extra.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Main => 0,
            self::Medium => 1,
            self::Other => 2,
            self::Extra => 3,
        };
    }

    public function getLabel(): string
    {
        return __('enums.signal_category.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Main => 'danger',
            self::Medium => 'info',
            self::Other => 'success',
            self::Extra => 'gray',
        };
    }
}
