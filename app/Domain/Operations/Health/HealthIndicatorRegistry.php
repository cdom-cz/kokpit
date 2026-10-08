<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health;

use LogicException;

/**
 * Maps every HealthSlot to exactly one indicator (D-13).
 *
 * Bound as a singleton in OperationsServiceProvider with one indicator per
 * slot. Later phases swap a placeholder through replace().
 */
class HealthIndicatorRegistry
{
    /**
     * @var array<string, HealthIndicator> keyed by the slot value
     */
    private array $indicators = [];

    /**
     * Adds the indicator of a slot that has none yet.
     *
     * @throws LogicException when the slot already has an indicator; use replace() to swap it
     */
    public function register(HealthIndicator $indicator): void
    {
        $key = $indicator->slot()->value;

        if (isset($this->indicators[$key])) {
            throw new LogicException("The health slot [{$key}] already has an indicator; use replace() to swap it.");
        }

        $this->indicators[$key] = $indicator;
    }

    public function replace(HealthSlot $slot, HealthIndicator $indicator): void
    {
        $this->indicators[$slot->value] = $indicator;
    }

    /**
     * The registered indicators in HealthSlot::cases() order, keyed by slot value.
     *
     * @return array<string, HealthIndicator>
     */
    public function indicators(): array
    {
        $ordered = [];

        foreach (HealthSlot::cases() as $slot) {
            if (isset($this->indicators[$slot->value])) {
                $ordered[$slot->value] = $this->indicators[$slot->value];
            }
        }

        return $ordered;
    }

    /**
     * One result per registered indicator, in HealthSlot::cases() order.
     *
     * @return array<string, HealthResult>
     */
    public function results(): array
    {
        return array_map(
            static fn (HealthIndicator $indicator): HealthResult => $indicator->check(),
            $this->indicators(),
        );
    }
}
